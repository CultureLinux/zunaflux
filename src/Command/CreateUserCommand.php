<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Creates a Zunaflux account.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Account email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Initial password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant the administrator role');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = new User((string) $input->getArgument('email'));
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, (string) $input->getArgument('password')));

        if ($input->getOption('admin')) {
            $user->addRole('ROLE_ADMIN');
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln(sprintf('Account created for %s.', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }
}
