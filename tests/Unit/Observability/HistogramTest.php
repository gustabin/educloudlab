<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Observability;

use EduCloud\Modules\Observability\Heartbeat;
use EduCloud\Modules\Observability\Histogram;
use PHPUnit\Framework\TestCase;

final class HistogramTest extends TestCase
{
    public function testBucketBoundsAreInclusive(): void
    {
        self::assertSame('le_50', Histogram::column(0));
        self::assertSame('le_50', Histogram::column(50));
        self::assertSame('le_100', Histogram::column(51));
        self::assertSame('le_5000', Histogram::column(5000));
        self::assertSame('le_inf', Histogram::column(5001));
    }

    public function testPercentilesUseTheUpperBoundOfTheBucket(): void
    {
        $counts = ['le_50' => 90, 'le_250' => 8, 'le_inf' => 2];
        self::assertNull(Histogram::percentile([], 95, 0));
        self::assertSame(50, Histogram::percentile($counts, 50, 9000));
        self::assertSame(50, Histogram::percentile($counts, 90, 9000));
        self::assertSame(250, Histogram::percentile($counts, 95, 9000));
        self::assertSame(9000, Histogram::percentile($counts, 99, 9000), 'the open bucket reports the observed maximum');
        self::assertSame(30, Histogram::percentile(['le_50' => 3], 95, 30), 'never above the observed maximum');
    }

    public function testExactPercentilesUseNearestRank(): void
    {
        self::assertNull(Histogram::exact([], 50));
        self::assertSame(3, Histogram::exact([5, 1, 3, 2, 4], 50));
        self::assertSame(100, Histogram::exact(array_merge(array_fill(0, 19, 1), [100]), 96));
        self::assertSame(1, Histogram::exact(array_merge(array_fill(0, 19, 1), [100]), 95));
    }

    public function testHeartbeatDetailsAreAllowlisted(): void
    {
        $safe = Heartbeat::safe([
            'processed' => '12', 'docker' => false, 'sent' => 1.9, 'failed' => 'x',
            'path' => 'C:/secret', 'error' => 'SQLSTATE', 'token' => 'abc',
        ]);
        self::assertSame(['processed' => 12, 'sent' => 1, 'failed' => null, 'docker' => false], $safe);
    }
}
