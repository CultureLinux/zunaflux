<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Administration\DeleteUserAccount;
use App\Application\Administration\AdminStatistics;
use App\Application\Feed\FeedSyncRunService;
use App\Entity\Feed;
use App\Entity\FeedIncident;
use App\Entity\FeedIncidentOccurrence;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\UserRepository;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminController extends AbstractController
{
    #[Route('/admin/feeds/{id}/delete', name: 'app_admin_feed_delete', methods: ['POST'])]
    public function deleteFeed(string $id, Request $r, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        try {
            $feed = $em->getRepository(Feed::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $feed = null;
        }
        if (!$feed instanceof Feed) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('delete-feed-'.$id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $scope = $r->request->getString('scope');
        if (!in_array($scope, ['incident', 'unused'], true)) {
            throw $this->createAccessDeniedException();
        }
        $em->wrapInTransaction(function () use ($em, $feed, $scope): void {
            $em->lock($feed, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ('unused' === $scope && $em->getRepository(Subscription::class)->count(['feed' => $feed]) > 0) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Ce flux possède désormais un abonnement.');
            }
            if (
                'incident' === $scope
                && !$em->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed, 'acknowledgedAt' => null]) instanceof FeedIncident
            ) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Cet incident n’est plus ouvert.');
            }
            $em->remove($feed);
            $em->flush();
        });
        $this->addFlash('success', 'Flux et données associées supprimés pour tous les comptes.');

        return $this->redirectToRoute('app_admin');
    }

    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(Request $r, EntityManagerInterface $em, \App\Application\Administration\RegistrationLimit $registrationLimit, AdminStatistics $statistics, FeedRepository $feeds): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $users = $em->getRepository(User::class)->findBy([], ['createdAt' => 'DESC']);
        $requestedUser = $r->query->getString('stats_user');
        $statsUser = null;
        if ('' !== $requestedUser && 'all' !== $requestedUser) {
            $statsUser = Uuid::isValid($requestedUser) ? $em->getRepository(User::class)->find(Uuid::fromString($requestedUser)) : null;
            if (!$statsUser instanceof User) {
                throw $this->createNotFoundException();
            }
        }
        $perAccount = $statistics->subscriptionsPerAccount();
        $quotaCounts = [];
        foreach ($users as $user) {
            $quotaCounts[(string) $user->getId()] = $perAccount[(string) $user->getId()] ?? 0;
        }
        $syncTargets = $statistics->syncTargets();
        $worker = $statistics->worker();
        $incidentTimezone = 'Europe/Paris';
        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $preference = $em->getRepository(UserPreference::class)->findOneBy(['user' => $currentUser]);
            if ($preference instanceof UserPreference) {
                $incidentTimezone = $preference->getTimezone();
            }
        }
        $incidents = $em->getRepository(FeedIncident::class)->findBy(['acknowledgedAt' => null]);
        $incidentHistory = [];
        foreach ($incidents as $incident) {
            $incidentHistory[(string) $incident->getId()] = $em
                ->getRepository(FeedIncidentOccurrence::class)
                ->findBy(['incident' => $incident], ['occurredAt' => 'ASC']);
        }

        return $this->render(
            'admin/index.html.twig',
            [
                'users' => $users,
                'statsUser' => $statsUser,
                'quotaCounts' => $quotaCounts,
                'stats' => $statistics->activity($statsUser),
                'registrationLimit' => $registrationLimit->effectiveLimit(),
                'registrationLimitLocked' => null !== $registrationLimit->environmentLimit(),
                'unusedFeeds' => $feeds->findUnused(),
                'incidents' => $incidents,
                'incidentHistory' => $incidentHistory,
                'feedCount' => $em->getRepository(Feed::class)->count([]),
                'subscriptionCount' => $em->getRepository(Subscription::class)->count([]),
                'syncCategories' => $syncTargets['categories'],
                'syncFeeds' => $syncTargets['feeds'],
                'queueCount' => $worker['queueCount'],
                'workerActive' => $worker['workerActive'],
                'incidentTimezone' => $incidentTimezone,
            ],
        );
    }

    #[Route('/admin/registration-limit', name: 'app_admin_registration_limit', methods: ['POST'])]
    public function registrationLimit(Request $r, \App\Application\Administration\RegistrationLimit $limit, \Psr\Log\LoggerInterface $logger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('registration-limit', $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (null !== $limit->environmentLimit()) {
            throw $this->createAccessDeniedException('La limite est définie par l’environnement.');
        }
        $value = $r->request->getString('account_limit');
        try {
            if ('' !== $value && (!ctype_digit($value) || (string) (int) $value !== $value)) {
                throw new \App\Application\Exception\UserInputError('Saisissez un entier positif.');
            }
            $limit->update('' === $value ? null : (int) $value);
            $this->addFlash('success', 'Limite d’inscriptions enregistrée.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/incidents/{id}/retry', name: 'app_admin_incident_retry', methods: ['POST'])]
    public function retry(
        string $id,
        Request $r,
        EntityManagerInterface $em,
        FeedSyncRunService $syncRuns,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        try {
            $incident = $em->getRepository(FeedIncident::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $incident = null;
        }
        if (!$incident instanceof FeedIncident) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('retry-incident-'.$id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (null !== $incident->getAcknowledgedAt()) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Cet incident n’est plus ouvert.');
        }
        // The collection runs in the worker; the dialog follows it through the sync run and the
        // handler drops the incident as soon as the collection succeeds.
        $run = $syncRuns->start([$incident->getFeed()->getId()], clearIncident: true);
        if ('application/json' === $r->headers->get('Accept')) {
            return $this->json(['runId' => (string) $run], Response::HTTP_ACCEPTED);
        }
        $this->addFlash('success', 'Nouvelle collecte placée en file. L’incident sera supprimé si elle réussit.');

        return $this->redirectToRoute('app_admin');
    }

    #[Route('/admin/users/{id}', name: 'app_admin_user_update', methods: ['POST'])]
    public function update(string $id, Request $r, EntityManagerInterface $em, UserRepository $users): Response
    {
        try {
            $u = $em->getRepository(User::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $u = null;
        }
        if (!$u instanceof User) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('admin-user-'.$id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $keepsAdmin = '1' === $r->request->getString('enabled') && '1' === $r->request->getString('admin');
        if (
            $u->isAdmin()
            && $u->isActive()
            && !$keepsAdmin
            && 0 === $users->countOtherActiveAdministrators($u)
        ) {
            $this->addFlash('error', 'Impossible de désactiver ou de rétrograder le dernier administrateur actif.');

            return $this->redirectToRoute('app_admin');
        }
        '1' === $r->request->getString('enabled') ? $u->enable() : $u->disable();
        '1' === $r->request->getString('admin') ? $u->addRole('ROLE_ADMIN') : $u->removeAdminRole();
        $limit = $r->request->getString('subscription_limit');
        $u->setSubscriptionLimit('' === $limit ? null : (int) $limit);
        $em->flush();

        return $this->redirectToRoute('app_admin');
    }

    /**
     * Impersonation is decided here, after the CSRF check, and never by a query parameter:
     * a link must not be able to switch an administrator into another account.
     */
    #[Route('/admin/users/{id}/impersonate', name: 'app_admin_user_impersonate', methods: ['POST'])]
    public function impersonate(string $id, Request $r, EntityManagerInterface $em, TokenStorageInterface $tokens): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $u = Uuid::isValid($id) ? $em->getRepository(User::class)->find(Uuid::fromString($id)) : null;
        if (!$u instanceof User || !$u->isActive()) {
            throw $this->createNotFoundException();
        }
        $current = $this->getUser();
        $original = $tokens->getToken();
        if (
            !$current instanceof User
            || null === $original
            || $original instanceof SwitchUserToken
            || $current->getId()->equals($u->getId())
            || $u->isAdmin()
            || !$this->isCsrfTokenValid('impersonate-user-'.$id, $r->request->getString('_token'))
        ) {
            throw $this->createAccessDeniedException();
        }
        $tokens->setToken(new SwitchUserToken($u, 'main', $u->getRoles(), $original, $r->getUri()));

        return $this->redirectToRoute('app_articles');
    }

    #[Route('/admin/users', name: 'app_admin_user_create', methods: ['POST'])]
    public function createUser(
        Request $r,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        \App\Application\Administration\RegistrationLimit $limit,
        \Psr\Log\LoggerInterface $logger,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('admin-user-create', $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $user = new User($r->request->getString('email'));
            $password = $r->request->getString('password');
            if (mb_strlen($password) < 12) {
                throw new \App\Application\Exception\UserInputError('Le mot de passe doit contenir au moins 12 caractères.');
            }
            $user->setPasswordHash($hasher->hashPassword($user, $password));
            if ('1' === $r->request->getString('admin')) {
                $user->addRole('ROLE_ADMIN');
            }
            $over = null !== $limit->effectiveLimit() && $em->getRepository(User::class)->count([]) >= $limit->effectiveLimit();
            $em->persist($user);
            $em->flush();
            $this->addFlash($over ? 'warning' : 'success', $over ? 'Compte créé malgré la limite d’inscriptions.' : 'Compte créé.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->addFlash('error', 'Un compte existe déjà pour cette adresse e-mail.');
        } catch (\Throwable $e) {
            $logger->error('Admin user creation failed.', ['exception' => $e]);
            $this->addFlash('error', 'Le compte n’a pas pu être créé.');
        }

        return $this->redirectToRoute('app_admin');
    }

    #[Route('/impersonation/exit', name: 'app_impersonation_exit', methods: ['POST'])]
    public function exitImpersonation(Request $r, TokenStorageInterface $tokens): Response
    {
        if (!$this->isCsrfTokenValid('exit-impersonation', $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $token = $tokens->getToken();
        if ($token instanceof SwitchUserToken) {
            $tokens->setToken($token->getOriginalToken());
        }

        return $this->redirectToRoute('app_admin');
    }

    #[Route('/admin/incidents/{id}/acknowledge', name: 'app_admin_incident_acknowledge', methods: ['POST'])]
    public function acknowledge(string $id, Request $r, EntityManagerInterface $em): Response
    {
        try {
            $incident = $em->getRepository(FeedIncident::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $incident = null;
        }
        if (!$incident instanceof FeedIncident) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('incident-'.$id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $incident->acknowledge();
        $em->flush();

        return $this->redirectToRoute('app_admin');
    }

    #[Route('/admin/users/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function delete(string $id, Request $r, EntityManagerInterface $em, DeleteUserAccount $deletion): Response
    {
        try {
            $u = $em->getRepository(User::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $u = null;
        }
        if (!$u instanceof User) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('delete-user-'.$id, $r->request->getString('_token')) || 'SUPPRIMER' !== $r->request->getString('confirmation')) {
            throw $this->createAccessDeniedException();
        }
        if ($this->getUser() instanceof User && $this->getUser()->getId()->equals($u->getId())) {
            throw $this->createAccessDeniedException('Un administrateur ne peut pas supprimer son propre compte.');
        }
        try {
            $deletion->delete($u);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin');
        }
        $this->addFlash('success', 'Compte et données privées supprimés.');

        return $this->redirectToRoute('app_admin');
    }
}
