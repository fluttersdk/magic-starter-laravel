<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use FlutterSdk\MagicStarter\Filament\Support\CodeBlock;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * The panel's code view: what reads as JSON, how it is highlighted, and that
 * nothing reaches the page unescaped.
 */
class CodeBlockTest extends TestCase
{
    public function test_an_array_state_is_pretty_printed_json(): void
    {
        $block = CodeBlock::from(['cause' => 'database', 'retries' => 2]);

        $this->assertSame(CodeBlock::JSON, $block->language);
        $this->assertSame("{\n    \"cause\": \"database\",\n    \"retries\": 2\n}", $block->source);
    }

    public function test_a_string_holding_a_json_object_or_list_reads_as_json(): void
    {
        // A model's raw output is often the JSON it was asked for, stored as text.
        $this->assertSame(CodeBlock::JSON, CodeBlock::from('{"a":1}')->language);
        $this->assertSame(CodeBlock::JSON, CodeBlock::from(" [1, 2]\n")->language);
        $this->assertSame("{\n    \"a\": 1\n}", CodeBlock::from('{"a":1}')->source);
    }

    public function test_prose_and_bare_scalars_stay_text(): void
    {
        $this->assertSame(CodeBlock::TEXT, CodeBlock::from('Why is checkout slow?')->language);
        $this->assertSame(CodeBlock::TEXT, CodeBlock::from('42')->language);
        $this->assertSame(CodeBlock::TEXT, CodeBlock::from('"quoted"')->language);
        $this->assertSame(CodeBlock::TEXT, CodeBlock::from('{not json}')->language);
        $this->assertSame("First line\nSecond line", CodeBlock::from("First line\nSecond line")->source);
    }

    public function test_a_blank_state_has_nothing_to_show(): void
    {
        $this->assertNull(CodeBlock::from(null));
        $this->assertNull(CodeBlock::from(''));
        $this->assertNull(CodeBlock::from([]));
    }

    public function test_json_tokens_are_wrapped_by_kind(): void
    {
        $html = CodeBlock::from(['name' => 'up', 'n' => -1.5, 'ok' => true, 'none' => null])->html();

        $this->assertStringContainsString('<span class="msl-code-key">&quot;name&quot;</span>: ', $html);
        $this->assertStringContainsString('<span class="msl-code-str">&quot;up&quot;</span>', $html);
        $this->assertStringContainsString('<span class="msl-code-num">-1.5</span>', $html);
        $this->assertStringContainsString('<span class="msl-code-lit">true</span>', $html);
        $this->assertStringContainsString('<span class="msl-code-lit">null</span>', $html);
    }

    public function test_markup_inside_json_and_text_reaches_the_page_escaped(): void
    {
        $json = CodeBlock::from(['body' => '<script>alert(1)</script>', '<b>' => 1])->html();
        $text = CodeBlock::from("<script>alert(1)</script>\nnext")->html();

        foreach ([$json, $text] as $html) {
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
        $this->assertStringNotContainsString('<b>', $json);
    }

    public function test_an_escaped_quote_does_not_end_a_json_string(): void
    {
        $html = CodeBlock::from(['say' => 'a "quoted" word'])->html();

        $this->assertStringContainsString(
            '<span class="msl-code-str">&quot;a \\&quot;quoted\\&quot; word&quot;</span>',
            $html,
        );
    }

    public function test_a_long_string_value_does_not_stop_the_highlighting(): void
    {
        // A backtracking string pattern ran out of PCRE stack past about 9 KB,
        // so everything after a long system prompt rendered uncoloured.
        $html = CodeBlock::from(['instructions' => str_repeat('x', 20000), 'after' => true])->html();

        $this->assertStringContainsString('<span class="msl-code-key">&quot;after&quot;</span>', $html);
        $this->assertStringContainsString('<span class="msl-code-lit">true</span>', $html);
    }

    public function test_json_text_that_would_not_survive_a_round_trip_is_shown_as_written(): void
    {
        // Decoding and re-encoding turned `{}` into `[]`, dropped a duplicate key
        // and rounded a big integer, so the view and its copy button showed what
        // the model never returned.
        $raw = '{"a":{},"b":1,"b":2,"n":123456789012345678901234}';
        $block = CodeBlock::from($raw);

        $this->assertSame(CodeBlock::JSON, $block->language);
        $this->assertSame($raw, $block->source);
        $this->assertStringContainsString('<span class="msl-code-num">123456789012345678901234</span>', $block->html());
    }

    public function test_an_array_holding_invalid_utf8_still_shows_its_content(): void
    {
        $block = CodeBlock::from(['bad' => "\xB1\x31"]);

        $this->assertNotSame('', $block->source);
        $this->assertStringContainsString('&quot;bad&quot;', $block->html());
    }
}
