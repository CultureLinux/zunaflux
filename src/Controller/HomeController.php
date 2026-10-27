<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(UserRepository $users): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_articles');
        }

        return $this->render('home/index.html.twig', [
            'initial_setup_required' => 0 === $users->count([]),
        ]);
    }
}
