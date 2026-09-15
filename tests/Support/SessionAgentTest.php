<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\SessionAgent;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * Tests for the SessionAgent support class.
 *
 * These tests are RED by design — `SessionAgent` does not exist yet.
 * Expected failure: `Class "FlutterSdk\MagicStarter\Support\SessionAgent" not found`
 */
final class SessionAgentTest extends TestCase
{
    private const UA_DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const UA_MOBILE_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    /**
     * Test that parse() returns non-empty browser and platform strings for a known UA.
     */
    public function test_parse_returns_browser_and_platform_for_known_user_agent(): void
    {
        $result = SessionAgent::parse(self::UA_DESKTOP_CHROME);

        $this->assertNotEmpty($result['browser']);
        $this->assertNotEmpty($result['platform']);
    }

    /**
     * Test that a desktop Chrome UA is detected as desktop, not mobile.
     */
    public function test_parse_detects_desktop(): void
    {
        $result = SessionAgent::parse(self::UA_DESKTOP_CHROME);

        $this->assertTrue($result['is_desktop']);
        $this->assertFalse($result['is_mobile']);
    }

    /**
     * Test that a mobile iPhone UA is detected as mobile, not desktop.
     */
    public function test_parse_detects_mobile(): void
    {
        $result = SessionAgent::parse(self::UA_MOBILE_IPHONE);

        $this->assertTrue($result['is_mobile']);
        $this->assertFalse($result['is_desktop']);
    }

    /**
     * Test that an empty user agent string returns safe defaults without crashing.
     */
    public function test_parse_returns_defaults_for_empty_user_agent(): void
    {
        $result = SessionAgent::parse('');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('browser', $result);
        $this->assertArrayHasKey('platform', $result);
        $this->assertArrayHasKey('is_desktop', $result);
        $this->assertArrayHasKey('is_mobile', $result);
    }

    /**
     * Test that the parsed result always contains all required keys.
     */
    public function test_parse_returns_array_with_correct_keys(): void
    {
        $result = SessionAgent::parse(self::UA_DESKTOP_CHROME);

        $this->assertArrayHasKey('browser', $result);
        $this->assertArrayHasKey('platform', $result);
        $this->assertArrayHasKey('is_desktop', $result);
        $this->assertArrayHasKey('is_mobile', $result);
    }

    /**
     * An unrecognisable agent names nothing, rather than naming it in English.
     *
     * This class is shipped in a package, so a literal it returns is frozen
     * English inside every consumer's UI. A Turkish session list rendered
     * "Unknown - Unknown" under an otherwise fully Turkish page because of it.
     * Empty is what the method already answers for an absent user agent, so
     * this makes one shape for one fact.
     */
    public function test_parse_names_nothing_when_it_recognises_nothing(): void
    {
        $result = SessionAgent::parse('some-internal-probe/1.0');

        $this->assertSame('', $result['browser']);
        $this->assertSame('', $result['platform']);
    }

    /**
     * A PARTIALLY readable agent still reports the half it read.
     *
     * The client joins the non-empty parts, so this row reads "Mac" rather than
     * "Mac - Unknown": naming what is known and staying silent on what is not.
     * A blanket empty-on-any-miss would have lost the platform too.
     */
    public function test_parse_keeps_the_half_it_could_read(): void
    {
        // A Mac UA with no browser token any of the six patterns match.
        $result = SessionAgent::parse('Mozilla/5.0 (Macintosh; Mac OS X 10_15_7) CustomAgent/2.1');

        $this->assertSame('Mac', $result['platform']);
        $this->assertSame('', $result['browser']);
    }

    /**
     * The empty-user-agent branch keeps answering the same way, so the two
     * "we do not know" paths cannot drift back apart.
     */
    public function test_an_absent_user_agent_answers_the_same_empty_shape(): void
    {
        $result = SessionAgent::parse('');

        $this->assertSame('', $result['browser']);
        $this->assertSame('', $result['platform']);
        $this->assertSame('', $result['app']);
    }

    /**
     * A native client is named rather than filed under "unknown desktop".
     *
     * `magic` sends `<App Name> (Flutter; <platform>)`. Before this, none of
     * the six browser patterns and none of the five platform patterns matched
     * it, so both came back empty, `is_mobile` came back false, and a phone's
     * own session rendered as a browser session on an unknown desktop.
     */
    public function test_parse_reads_a_native_flutter_agent(): void
    {
        $result = SessionAgent::parse('Uptizm (Flutter; iOS)');

        $this->assertSame('iOS', $result['platform']);
        $this->assertSame('Uptizm', $result['app']);
        $this->assertTrue($result['is_mobile']);
        $this->assertFalse($result['is_desktop']);
        $this->assertSame('', $result['browser'], 'a native client is not a browser');
    }

    /**
     * An app name with spaces survives, because plenty have one.
     */
    public function test_parse_keeps_a_multi_word_app_name(): void
    {
        $result = SessionAgent::parse('Uptizm Field Ops (Flutter; Android)');

        $this->assertSame('Uptizm Field Ops', $result['app']);
        $this->assertSame('Android', $result['platform']);
        $this->assertTrue($result['is_mobile']);
    }

    /**
     * A native desktop is a desktop, and it says so for its own reason.
     *
     * `(Flutter; macOS)` contains none of the mobile substrings, so it would
     * have read as a desktop by accident. The native branch decides on the
     * token instead, which is what keeps a future platform from being judged
     * by a coincidence.
     */
    public function test_parse_reads_a_native_desktop_agent(): void
    {
        $result = SessionAgent::parse('Uptizm (Flutter; macOS)');

        $this->assertSame('macOS', $result['platform']);
        $this->assertTrue($result['is_desktop']);
        $this->assertFalse($result['is_mobile']);
    }

    /**
     * A hand-rolled client's casing is normalised to this class's spelling, so
     * two clients naming the same platform do not read differently in the list.
     */
    public function test_parse_normalises_a_native_platform_token(): void
    {
        $this->assertSame('iOS', SessionAgent::parse('X (Flutter; ios)')['platform']);
        $this->assertSame('Android', SessionAgent::parse('X (Flutter; ANDROID)')['platform']);
    }

    /**
     * A platform this class has not heard of is passed through, not emptied.
     *
     * A client naming a platform still knows more than nothing does, and the
     * alternative reads in the session list as an unidentifiable device. It is
     * also not judged mobile, since only the two named tokens are.
     */
    public function test_parse_passes_an_unknown_native_platform_through(): void
    {
        $result = SessionAgent::parse('Acme (Flutter; Tizen)');

        $this->assertSame('Tizen', $result['platform']);
        $this->assertSame('Acme', $result['app']);
        $this->assertFalse($result['is_mobile']);
    }

    /**
     * A browser agent is untouched by the native branch, which is the half a
     * greedy pattern would have broken.
     */
    /**
     * A browser agent carrying a trailing native token is still a browser.
     *
     * The three native reads (platform, mobile, app name) used to be three
     * separate patterns deciding on different substrings, so this string
     * reported `platform=iOS` and `browser=Safari` at the same time and handed
     * the whole `Mozilla/5.0 (...)` prefix back as the application's name. They
     * now share one anchored read whose name part admits no parenthesis, which
     * is what keeps a browser out of the native branch rather than merely
     * making the three agree on a wrong answer.
     */
    public function test_a_browser_prefix_is_not_read_as_a_native_app(): void
    {
        $result = SessionAgent::parse(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) MyApp (Flutter; iOS)',
        );

        $this->assertSame('Mac', $result['platform']);
        $this->assertSame('', $result['app']);
        $this->assertFalse($result['is_mobile']);
    }

    /**
     * An app whose own NAME carries a parenthesis is not read as native, and
     * that is the deliberate cost of excluding a browser prefix.
     *
     * The name part admits no parenthesis, which is what keeps
     * `Mozilla/5.0 (Macintosh; ...) MyApp (Flutter; iOS)` out of the native
     * branch. An app genuinely called `Acme (EU)` pays for that by falling
     * through to the browser patterns and reporting nothing, which is a worse
     * answer for one unusual name than a wrong answer for every browser would
     * be. Pinned so the trade-off is recorded rather than rediscovered as a
     * bug.
     */
    public function test_a_parenthesised_app_name_is_not_recognised(): void
    {
        $result = SessionAgent::parse('Acme (EU) (Flutter; iOS)');

        $this->assertSame('', $result['app']);
        $this->assertSame('', $result['platform']);
        $this->assertFalse($result['is_mobile']);
    }

    public function test_a_browser_agent_reports_no_app(): void
    {
        $result = SessionAgent::parse(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        );

        $this->assertSame('Mac', $result['platform']);
        $this->assertSame('Chrome', $result['browser']);
        $this->assertSame('', $result['app']);
    }
}
