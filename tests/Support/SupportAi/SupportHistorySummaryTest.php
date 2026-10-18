<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use FlutterSdk\MagicStarter\Support\SupportAi\SupportHistorySummary;
use FlutterSdk\MagicStarter\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the compaction a responder hands back: a summary needs words, and it
 * names the newest message it covers by that message's own key type.
 */
class SupportHistorySummaryTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blankTexts(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'newlines and tabs' => ["\n\t \n"],
        ];
    }

    #[DataProvider('blankTexts')]
    public function test_a_blank_summary_throws(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SupportHistorySummary($text, 7);
    }

    public function test_a_summary_keeps_its_text_and_boundary_of_either_key_type(): void
    {
        $byInt = new SupportHistorySummary('The customer asked about invoices.', 7);
        $byUuid = new SupportHistorySummary(
            'The customer asked about invoices.',
            '0199f0c2-0000-7000-8000-000000000001',
        );

        $this->assertSame('The customer asked about invoices.', $byInt->text);
        $this->assertSame(7, $byInt->throughMessageId);
        $this->assertSame('0199f0c2-0000-7000-8000-000000000001', $byUuid->throughMessageId);
    }
}
