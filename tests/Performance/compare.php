<?php

declare(strict_types=1);

// Relative budgets: no SQL growth; at most 20% median-time growth on the same host.
try {
    if (3 !== $argc) {
        throw new RuntimeException('Usage: compare.php baseline.json candidate.json');
    }
    $baseline = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $candidate = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    foreach ([$baseline, $candidate] as $report) {
        if (1 !== ($report['format'] ?? null) || true !== ($report['rollback_verified'] ?? null)
            || 8 !== count($report['cases'] ?? [])
            || !is_array($report['dataset'] ?? null) || !is_array($report['protocol'] ?? null)
            || !is_string($report['php'] ?? null) || !is_string($report['mariadb'] ?? null)
        ) {
            throw new RuntimeException('Incomplete measurement report.');
        }
    }
    foreach (['dataset', 'protocol', 'php', 'mariadb'] as $field) {
        if ($baseline[$field] !== $candidate[$field]) {
            throw new RuntimeException('Incompatible measurement conditions.');
        }
    }
    $failed = false;
    foreach ($baseline['cases'] as $name => $before) {
        $after = $candidate['cases'][$name] ?? throw new RuntimeException('Missing measurement case.');
        foreach ([$before, $after] as $case) {
            if (5 !== count($case['samples'] ?? [])) {
                throw new RuntimeException('Five measurement samples are required.');
            }
            foreach ($case['samples'] as $sample) {
                if (!is_numeric($sample['milliseconds'] ?? null) || $sample['milliseconds'] < 0
                    || !is_int($sample['sql_statements'] ?? null) || $sample['sql_statements'] < 1
                ) {
                    throw new RuntimeException('Invalid measurement sample.');
                }
            }
        }
        $median = static function (array $case): float {
            $times = array_column($case['samples'], 'milliseconds');
            sort($times, SORT_NUMERIC);

            return (float) $times[2];
        };
        $sqlBefore = max(array_column($before['samples'], 'sql_statements'));
        $sqlAfter = max(array_column($after['samples'], 'sql_statements'));
        $timeBefore = $median($before);
        $timeAfter = $median($after);
        $passed = $sqlAfter <= $sqlBefore && $timeAfter <= max(0.001, $timeBefore * 1.20);
        $failed = $failed || !$passed;
        printf("%s %s: SQL %d -> %d; median %.3f -> %.3f ms\n", $passed ? 'PASS' : 'FAIL', $name, $sqlBefore, $sqlAfter, $timeBefore, $timeAfter);
    }
    exit($failed ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Comparison failed. Supply two complete compatible performance reports.\n");
    exit(2);
}
