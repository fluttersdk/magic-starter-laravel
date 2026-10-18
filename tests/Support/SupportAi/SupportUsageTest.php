<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the token counts a turn reports: the cache buckets ride beside the
 * uncached input, a negative count is refused, and the legs of a turn add up
 * bucket by bucket.
 */
class SupportUsageTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function negativeCounts(): array
    {
        return [
            'prompt' => [-1, 0, 0, 0],
            'completion' => [0, -1, 0, 0],
            'cache read' => [0, 0, -1, 0],
            'cache write' => [0, 0, 0, -1],
        ];
    }

    #[DataProvider('negativeCounts')]
    public function test_a_negative_count_in_any_bucket_throws(
        int $prompt,
        int $completion,
        int $read,
        int $write,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new SupportUsage($prompt, $completion, $read, $write);
    }

    public function test_plus_sums_all_four_buckets(): void
    {
        $sum = (new SupportUsage(100, 40, 300, 20))->plus(new SupportUsage(10, 4, 30, 2));

        $this->assertSame(110, $sum->promptTokens);
        $this->assertSame(44, $sum->completionTokens);
        $this->assertSame(330, $sum->cacheReadTokens);
        $this->assertSame(22, $sum->cacheWriteTokens);
    }

    public function test_total_is_the_unweighted_sum_of_all_four_buckets(): void
    {
        $this->assertSame(460, (new SupportUsage(100, 40, 300, 20))->total());
    }

    public function test_a_two_argument_usage_has_no_cache_tokens(): void
    {
        $usage = new SupportUsage(promptTokens: 60, completionTokens: 40);

        $this->assertSame(0, $usage->cacheReadTokens);
        $this->assertSame(0, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->total());
    }
}
