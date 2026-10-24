<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, MailerInterface $mailer, #[Autowire(service: 'limiter.contact')] RateLimiterFactoryInterface $contactLimiter): Response
    {
        if ($request->isMethod('GET')) return $this->render('contact/index.html.twig', ['data' => [], 'errors' => []]);
        if (!$this->isCsrfTokenValid('contact', $request->request->getString('_token'))) return $this->render('contact/index.html.twig', ['data' => [], 'errors' => ['form' => 'Votre session a expiré. Rechargez la page puis réessayez.']], new Response(status: Response::HTTP_FORBIDDEN));

        $data = ['name' => trim($request->request->getString('name')), 'email' => trim($request->request->getString('email')), 'subject' => trim($request->request->getString('subject')), 'message' => trim($request->request->getString('message'))];
        $errors = $this->validate($data);
        if ([] !== $errors) return $this->render('contact/index.html.twig', ['data' => $data, 'errors' => $errors], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        if (!$contactLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) return $this->render('contact/index.html.twig', ['data' => $data, 'errors' => ['form' => 'Trop de messages ont été envoyés depuis cette connexion. Réessayez plus tard.']], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));

        try {
            $mailer->send((new Email())->from('products@clinux.fr')->to('products@clinux.fr')->replyTo($data['email'])->subject('Contact Zunaflux — '.$data['subject'])->text("Nom : {$data['name']}\nE-mail : {$data['email']}\n\n{$data['message']}"));
        } catch (\Throwable) {
            return $this->render('contact/index.html.twig', ['data' => $data, 'errors' => ['form' => 'Le message n’a pas pu être envoyé. Réessayez ultérieurement.']], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }
        $this->addFlash('success', 'Votre message a bien été envoyé.');
        return $this->redirectToRoute('app_contact', status: Response::HTTP_SEE_OTHER);
    }

    /** @param array{name: string, email: string, subject: string, message: string} $data */
    private function validate(array $data): array
    {
        $errors = [];
        if (mb_strlen($data['name']) < 2 || mb_strlen($data['name']) > 100) $errors['name'] = 'Saisissez votre nom (2 à 100 caractères).';
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($data['email']) > 254) $errors['email'] = 'Saisissez une adresse e-mail valide.';
        if (mb_strlen($data['subject']) < 3 || mb_strlen($data['subject']) > 160 || preg_match('/[\r\n]/', $data['subject'])) $errors['subject'] = 'Saisissez un sujet de 3 à 160 caractères.';
        if (mb_strlen($data['message']) < 10 || mb_strlen($data['message']) > 5000) $errors['message'] = 'Votre message doit contenir entre 10 et 5 000 caractères.';
        return $errors;
    }
}
