<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class PerformancePlan
{
    /** @param list<array<string, mixed>> $rows */
    public static function summarize(array $rows): array
    {
        // Tabular EXPLAIN avoids JSON conditions containing binary UUIDs.
        // Exclude Extra and ref, which may contain bound values.
        $fields = array_flip(['id', 'select_type', 'table', 'type', 'possible_keys', 'key', 'key_len', 'rows', 'filtered']);
        $result = [];
        foreach ($rows as $row) {
            $result[] = array_intersect_key($row, $fields);
        }

        return $result;
    }
}
