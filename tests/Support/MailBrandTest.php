<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\MailBrand;
use FlutterSdk\MagicStarter\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks what an app may put under `magic-starter.mail.brand` and what the mail
 * theme can rely on once it has been read.
 *
 * The values end up in a stylesheet and an `<img src>` that the mail theme prints
 * without escaping or with the least escaping a mail client tolerates, so the
 * brand is validated once, loudly, where it is read: a bad value names its config
 * key and never reaches a delivered mail.
 */
final class MailBrandTest extends TestCase
{
    private const DARK = [
        'primary' => '#a78bfa',
        'on_primary' => '#0b0b0f',
        'heading' => '#fafafa',
        'text' => '#d4d4d8',
        'muted' => '#71717a',
        'background' => '#09090b',
        'card' => '#18181b',
        'border' => '#27272a',
    ];

    public function test_the_defaults_are_off_light_only_and_look_like_stock_laravel(): void
    {
        $brand = MailBrand::current();

        $this->assertFalse(MailBrand::enabled());
        $this->assertNull($brand->dark);
        $this->assertSame('light', $brand->colorScheme());
        $this->assertSame('#18181b', $brand->light['primary']);
        $this->assertSame('#fafafa', $brand->light['background']);
        $this->assertSame(MailBrand::COLOR_KEYS, array_keys($brand->light));
        $this->assertNull($brand->logoUrl);
        $this->assertNull($brand->footer);
    }

    public function test_the_brand_switches_on_through_its_config_flag(): void
    {
        config(['magic-starter.mail.brand.enabled' => true]);

        $this->assertTrue(MailBrand::enabled());
    }

    public function test_a_full_dark_map_turns_the_colour_scheme_to_light_dark(): void
    {
        config(['magic-starter.mail.brand.colors.dark' => self::DARK]);

        $brand = MailBrand::current();

        $this->assertSame('light dark', $brand->colorScheme());
        $this->assertSame(self::DARK, $brand->dark);
    }

    public function test_colours_are_stored_lowercased(): void
    {
        config([
            'magic-starter.mail.brand.colors.light.primary' => '#ABCDEF',
            'magic-starter.mail.brand.colors.dark' => [...self::DARK, 'primary' => '#A78BFA'],
        ]);

        $brand = MailBrand::current();

        $this->assertSame('#abcdef', $brand->light['primary']);
        $this->assertSame('#a78bfa', $brand->dark['primary']);
    }

    public function test_a_partial_dark_map_throws_naming_the_missing_key(): void
    {
        config(['magic-starter.mail.brand.colors.dark' => [...self::DARK, 'card' => null]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.colors.dark.card');

        MailBrand::current();
    }

    public function test_an_invalid_dark_colour_throws_naming_the_key(): void
    {
        config(['magic-starter.mail.brand.colors.dark' => [...self::DARK, 'text' => 'grey']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.colors.dark.text');

        MailBrand::current();
    }

    public function test_an_invalid_light_colour_throws_naming_the_key_and_the_value(): void
    {
        config(['magic-starter.mail.brand.colors.light.primary' => 'red']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('colors.light.primary');
        $this->expectExceptionMessage("'red'");

        MailBrand::current();
    }

    public function test_a_three_digit_hex_colour_throws(): void
    {
        config(['magic-starter.mail.brand.colors.light.heading' => '#abc']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('colors.light.heading');

        MailBrand::current();
    }

    public function test_a_javascript_logo_url_throws(): void
    {
        config(['magic-starter.mail.brand.logo_url' => 'javascript:alert(1)']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.logo_url');

        MailBrand::current();
    }

    public function test_an_ftp_logo_url_throws(): void
    {
        config(['magic-starter.mail.brand.logo_url' => 'ftp://x']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.logo_url');

        MailBrand::current();
    }

    public function test_an_https_logo_url_is_kept(): void
    {
        config(['magic-starter.mail.brand.logo_url' => 'https://cdn.example.test/logo.png']);

        $this->assertSame('https://cdn.example.test/logo.png', MailBrand::current()->logoUrl);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeFontStacks(): array
    {
        return [
            'semicolon' => ["Arial; background: url('https://evil.test')"],
            'closing brace' => ['Arial } body { display: none'],
            'angle bracket' => ['Arial</style><script>'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unsafeFontStacks')]
    public function test_a_font_stack_that_could_break_out_of_the_stylesheet_throws(string $stack): void
    {
        config(['magic-starter.mail.brand.font_stack' => $stack]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.font_stack');

        MailBrand::current();
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function outOfRangeNumbers(): array
    {
        return [
            'logo width zero' => ['logo_width', 0],
            'logo width above 600' => ['logo_width', 601],
            'logo width as string' => ['logo_width', '48'],
            'button radius negative' => ['button_radius', -1],
            'button radius above 64' => ['button_radius', 65],
            'card radius negative' => ['card_radius', -1],
            'card radius above 64' => ['card_radius', 65],
        ];
    }

    #[DataProvider('outOfRangeNumbers')]
    public function test_a_number_outside_its_range_throws_naming_the_key(string $key, mixed $value): void
    {
        config(["magic-starter.mail.brand.{$key}" => $value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("magic-starter.mail.brand.{$key}");

        MailBrand::current();
    }

    public function test_the_range_edges_are_accepted(): void
    {
        config([
            'magic-starter.mail.brand.logo_width' => 600,
            'magic-starter.mail.brand.button_radius' => 0,
            'magic-starter.mail.brand.card_radius' => 64,
        ]);

        $brand = MailBrand::current();

        $this->assertSame(600, $brand->logoWidth);
        $this->assertSame(0, $brand->buttonRadius);
        $this->assertSame(64, $brand->cardRadius);
    }

    public function test_an_empty_name_throws(): void
    {
        config(['magic-starter.mail.brand.name' => '  ']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.name');

        MailBrand::current();
    }

    /**
     * Consumer configs replace whole top-level arrays, so an app that restates
     * the block partially has keys missing, not null.
     */
    public function test_missing_optional_keys_count_as_null(): void
    {
        $brand = config('magic-starter.mail.brand');
        unset($brand['logo_url'], $brand['footer'], $brand['colors']['dark']);
        config(['magic-starter.mail.brand' => $brand]);

        $current = MailBrand::current();

        $this->assertNull($current->logoUrl);
        $this->assertNull($current->footer);
        $this->assertNull($current->dark);
    }

    public function test_a_missing_required_key_throws_naming_it(): void
    {
        $brand = config('magic-starter.mail.brand');
        unset($brand['button_radius']);
        config(['magic-starter.mail.brand' => $brand]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.button_radius');

        MailBrand::current();
    }

    public function test_a_missing_light_colour_throws_naming_it(): void
    {
        $brand = config('magic-starter.mail.brand');
        unset($brand['colors']['light']['border']);
        config(['magic-starter.mail.brand' => $brand]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic-starter.mail.brand.colors.light.border');

        MailBrand::current();
    }

    public function test_without_a_footer_the_line_is_laravels_own(): void
    {
        config(['magic-starter.mail.brand.name' => 'Acme']);

        $line = MailBrand::current()->footerLine();

        $this->assertSame('© ' . date('Y') . ' Acme. All rights reserved.', $line);
    }

    public function test_a_footer_gets_the_year_filled_in(): void
    {
        config(['magic-starter.mail.brand.footer' => '© :year Acme']);

        $this->assertSame('© ' . date('Y') . ' Acme', MailBrand::current()->footerLine());
    }
}
