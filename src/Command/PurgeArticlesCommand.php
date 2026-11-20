<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Maintenance\ArticleRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(name: 'app:maintenance:purge-articles', description: 'Supprime par lots les articles arrivés à rétention.')]
final class PurgeArticlesCommand extends Command
{
    public function __construct(private readonly ArticleRetention $retention, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Budget de temps entre deux lots, en secondes.', '300')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Articles supprimés par lot (1 à 1000).', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxSeconds = filter_var($input->getOption('max-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $batchSize = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (false === $maxSeconds || false === $batchSize) {
            $output->writeln('<error>--max-seconds doit être un entier >= 0 et --batch-size un entier entre 1 et 1000.</error>');

            return Command::INVALID;
        }
        $deleted = $this->retention->purge($batchSize, $maxSeconds);
        $this->logger->info('Article retention purge completed.', ['deleted_articles' => $deleted]);
        $output->writeln(sprintf('Articles supprimés : %d', $deleted));

        return Command::SUCCESS;
    }
}
