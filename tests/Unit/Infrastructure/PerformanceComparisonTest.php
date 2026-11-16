<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

final class PerformanceComparisonTest extends TestCase
{
    public function testIdenticalMeasurementsMeetBudgets(): void
    {
        self::assertSame(0, $this->compare($this->report(), $this->report()));
    }

    public function testMoreQueriesExceedBudgetEvenWhenFaster(): void
    {
        self::assertSame(1, $this->compare($this->report(), $this->report(11, 5.0)));
    }

    public function testTwentyPercentMedianIncreaseMeetsBudget(): void
    {
        self::assertSame(0, $this->compare($this->report(), $this->report(10, 12.0)));
    }

    public function testSlowerMedianExceedsBudgetEvenWithFewerQueries(): void
    {
        self::assertSame(1, $this->compare($this->report(), $this->report(9, 12.1)));
    }

    public function testIncompatibleDatasetsAreRejected(): void
    {
        $candidate = $this->report();
        $candidate['dataset']['articles'] = 1;
        self::assertSame(2, $this->compare($this->report(), $candidate));
    }

    public function testIncompleteSamplesAreRejected(): void
    {
        $candidate = $this->report();
        array_pop($candidate['cases']['case_0']['samples']);
        self::assertSame(2, $this->compare($this->report(), $candidate));
    }

    private function report(int $queries = 10, float $milliseconds = 10.0): array
    {
        $cases = [];
        for ($index = 0; $index < 8; ++$index) {
            $cases['case_'.$index] = ['samples' => array_fill(0, 5, ['sql_statements' => $queries, 'milliseconds' => $milliseconds])];
        }

        return ['format' => 1, 'rollback_verified' => true, 'dataset' => ['articles' => 10000], 'protocol' => ['samples' => 5], 'php' => '8.5.0', 'mariadb' => '10.11.0-MariaDB', 'cases' => $cases];
    }

    private function compare(array $baseline, array $candidate): int
    {
        $first = tempnam(sys_get_temp_dir(), 'zunaflux-perf-');
        $second = tempnam(sys_get_temp_dir(), 'zunaflux-perf-');
        self::assertIsString($first);
        self::assertIsString($second);
        try {
            file_put_contents($first, json_encode($baseline, JSON_THROW_ON_ERROR));
            file_put_contents($second, json_encode($candidate, JSON_THROW_ON_ERROR));
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2).'/Performance/compare.php', $first, $second],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($process);
        } finally {
            unlink($first);
            unlink($second);
        }
    }
}
