<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        bool $publicRegistration,
    ): Response {
        if (!$publicRegistration) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('GET')) {
            return $this->render('security/register.html.twig');
        }

        if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de formulaire invalide.');
        }

        $email = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('password');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('error', 'Saisissez une adresse e-mail valide.');

            return $this->redirectToRoute('app_register');
        }

        if (mb_strlen($password) < 12) {
            $this->addFlash('error', 'Le mot de passe doit contenir au moins 12 caractères.');

            return $this->redirectToRoute('app_register');
        }

        $user = new User($email);
        if ($users->findOneBy(['emailNormalized' => $user->getUserIdentifier()]) !== null) {
            $this->addFlash('error', 'Un compte existe déjà pour cette adresse e-mail.');

            return $this->redirectToRoute('app_register');
        }

        $user->setPasswordHash($passwordHasher->hashPassword($user, $password));
        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'Votre compte est créé. Vous pouvez maintenant vous connecter.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This route is intercepted by Symfony Security.');
    }
}
