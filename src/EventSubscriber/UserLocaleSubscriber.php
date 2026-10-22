<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class UserLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private Security $security, private EntityManagerInterface $entityManager, private TranslatorInterface $translator) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $user = $this->security->getUser();
        if (!$user instanceof User) return;
        $preference = $this->entityManager->getRepository(UserPreference::class)->findOneBy(['user' => $user]);
        if ($preference instanceof UserPreference) {
            $event->getRequest()->setLocale($preference->getLocale());
            if ($this->translator instanceof LocaleAwareInterface) $this->translator->setLocale($preference->getLocale());
        }
    }
}
