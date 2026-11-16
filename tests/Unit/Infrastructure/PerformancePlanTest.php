<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Tests\Support\PerformancePlan;
use PHPUnit\Framework\TestCase;

final class PerformancePlanTest extends TestCase
{
    public function testStructuralEstimatesArePreserved(): void
    {
        $row = ['id' => 1, 'select_type' => 'SIMPLE', 'table' => 'article', 'type' => 'ref', 'possible_keys' => 'idx_feed', 'key' => 'idx_feed', 'key_len' => '16', 'rows' => '42', 'filtered' => '100.00'];
        self::assertSame([$row], PerformancePlan::summarize([$row]));
    }

    public function testBinaryAndControlValuesAreExcludedFromReport(): void
    {
        $row = ['table' => 'article', 'rows' => '42', 'Extra' => "secret-\xFF\x00\"\\", 'ref' => "secret-\x01", 'attached_condition' => "secret-\x80"];
        $plan = PerformancePlan::summarize([$row]);
        self::assertSame([['table' => 'article', 'rows' => '42']], $plan);
        self::assertStringNotContainsString('secret', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function testJoinAndSubqueryRowsRemainInOrder(): void
    {
        $rows = [['id' => 1, 'table' => 'a'], ['id' => 1, 'table' => 's'], ['id' => 2, 'table' => 'st']];
        self::assertSame($rows, PerformancePlan::summarize($rows));
    }

    public function testEmptyPlanIsHandled(): void
    {
        self::assertSame([], PerformancePlan::summarize([]));
    }
}
