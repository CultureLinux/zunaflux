<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Mastodon\MastodonOAuthService;
use App\Entity\MastodonAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MastodonConnectionController extends AbstractController
{
    #[Route('/mastodon/connect', name: 'app_mastodon_connect', methods: ['POST'])]
    public function connect(Request $request, MastodonOAuthService $oauth): Response
    {
        $this->user();
        if (!$this->isCsrfTokenValid('mastodon-connect', $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        try { return $this->redirect($oauth->begin($request->getSession(), $request->request->getString('instance_url'))); } catch (\RuntimeException|\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); return $this->redirectToRoute('app_preferences'); }
    }

    #[Route('/mastodon/callback', name: 'app_mastodon_callback', methods: ['GET'])]
    public function callback(Request $request, MastodonOAuthService $oauth): Response
    {
        try { $oauth->complete($this->user(), $request->getSession(), $request->query->getString('state'), $request->query->getString('code')); $this->addFlash('success', 'Compte Mastodon connecté.'); } catch (\RuntimeException|\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); }
        return $this->redirectToRoute('app_preferences');
    }

    #[Route('/mastodon/disconnect', name: 'app_mastodon_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->user(); if (!$this->isCsrfTokenValid('mastodon-disconnect', $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $account = $em->getRepository(MastodonAccount::class)->findOneBy(['user' => $user]); if ($account instanceof MastodonAccount) { $em->remove($account); $em->flush(); }
        return $this->redirectToRoute('app_preferences');
    }
    private function user(): User { $user = $this->getUser(); if (!$user instanceof User) throw $this->createAccessDeniedException(); return $user; }
}
