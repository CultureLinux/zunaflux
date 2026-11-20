<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SavedFilter;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Uid\Uuid;

final class SavedFilterController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/api/saved-filters', methods: ['GET', 'POST'])]
    public function collection(Request $r, EntityManagerInterface $em): JsonResponse
    {
        $u = $this->user();
        if ($r->isMethod('GET')) {
            return$this->json(
                array_map(
                    fn (SavedFilter $f) => ['id' => (string) $f->getId(), 'name' => $f->getName(), 'filters' => $f->getFilters()],
                    $em->getRepository(SavedFilter::class)->findBy(['user' => $u], ['name' => 'ASC']),
                ),
            );
        }
        try {
            $p = $this->payload($r);
        } catch (\JsonException) {
            return $this->invalid('JSON invalide.', 400);
        }
        try {
            $f = new SavedFilter($u, (string) ($p['name'] ?? ''), $this->filters($p['filters'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e->getMessage(), 422);
        }
        $em->persist($f);
        $em->flush();

        return $this->json(['id' => (string) $f->getId()], 201);
    }

    #[Route('/api/saved-filters/{id}', methods: ['PATCH', 'DELETE'])]
    public function item(string $id, Request $r, EntityManagerInterface $em): JsonResponse
    {
        try {
            $f = $em->getRepository(SavedFilter::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $f = null;
        }
        if (!$f instanceof SavedFilter || !$f->getUser()->getId()->equals($this->user()->getId())) {
            throw $this->createNotFoundException();
        }
        if ($r->isMethod('DELETE')) {
            $em->remove($f);
            $em->flush();

            return $this->json(null, 204);
        }
        try {
            $p = $this->payload($r);
        } catch (\JsonException) {
            return $this->invalid('JSON invalide.', 400);
        }
        try {
            if (isset($p['name'])) {
                $f->rename((string) $p['name']);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e->getMessage(), 422);
        }
        $em->flush();

        return $this->json(['id' => (string) $f->getId(), 'name' => $f->getName(), 'filters' => $f->getFilters()]);
    }

    private function invalid(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $this->translator->trans($message)], $status);
    }

    private function user(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }

    /** @return array<string, mixed> */
    private function payload(Request $r): array
    {
        if (!$this->isCsrfTokenValid('saved-filter', $r->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $p = json_decode($r->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $e;
        }

        return is_array($p) ? $p : [];
    }

    /** @return array<array-key, scalar|null> */
    private function filters(mixed $v): array
    {
        if (!is_array($v)) {
            throw new \App\Application\Exception\UserInputError('Filtres invalides.');
        }

        return array_filter($v, fn ($x) => is_scalar($x) || null === $x);
    }
}
