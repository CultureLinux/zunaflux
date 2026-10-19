<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Maintenance\ArticleRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(name: 'app:maintenance:purge-articles', description: 'Supprime un lot d’articles arrivés à rétention.')]
final class PurgeArticlesCommand extends Command
{
    public function __construct(private readonly ArticleRetention $retention, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->retention->purgeBatch();
        $this->logger->info('Article retention batch completed.', ['deleted_articles' => $deleted]);
        $output->writeln(sprintf('Articles supprimés : %d', $deleted));

        return Command::SUCCESS;
    }
}
