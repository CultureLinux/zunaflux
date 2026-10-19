<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Export\UserExport;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ExportController extends AbstractController
{
    #[Route('/exports/account', name: 'app_account_export', methods: ['POST'])]
    public function account(Request $request, UserExport $export): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('account-export', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $archive = $export->create($user);

        return new StreamedResponse(static function () use ($archive): void { echo $archive['contents']; }, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$archive['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/exports/opml', name: 'app_opml_export', methods: ['POST'])]
    public function opml(Request $request, UserExport $export): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('opml-export', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return new Response($export->createOpml($user), Response::HTTP_OK, [
            'Content-Type' => 'text/x-opml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="zunaflux-subscriptions.opml"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
