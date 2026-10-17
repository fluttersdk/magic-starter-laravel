<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * The brand an app configures under `magic-starter.mail.brand`, checked on every read.
 *
 * The mail theme prints these values into a stylesheet and an `<img src>`, so a
 * bad one has to fail here, loudly, with the config key and the value in the
 * message, instead of becoming broken CSS in a delivered mail. Nothing falls
 * back silently: a key an app restated partially is either optional (null) or an
 * error, never a guess.
 */
final class MailBrand
{
    public const COLOR_KEYS = [
        'primary',
        'on_primary',
        'heading',
        'text',
        'muted',
        'background',
        'card',
        'border',
    ];

    private const CONFIG = 'magic-starter.mail.brand';

    /**
     * @param  array<string, string>  $light  lowercased `#rrggbb` for every key of COLOR_KEYS
     * @param  array<string, string>|null  $dark  the same keys, or null when no dark scheme is configured
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $logoUrl,
        public readonly int $logoWidth,
        public readonly ?string $footer,
        public readonly string $fontStack,
        public readonly int $buttonRadius,
        public readonly int $cardRadius,
        public readonly array $light,
        public readonly ?array $dark,
    ) {}

    /**
     * Whether the app has opted into the branded mail layout and theme.
     */
    public static function enabled(): bool
    {
        return (bool) config(self::CONFIG . '.enabled');
    }

    /**
     * The configured brand.
     *
     * @throws InvalidArgumentException naming the config key and the value that failed
     */
    public static function current(): self
    {
        $config = config(self::CONFIG);
        $config = is_array($config) ? $config : [];

        return new self(
            name: self::name($config),
            logoUrl: self::logoUrl($config),
            logoWidth: self::integer($config, 'logo_width', 1, 600),
            footer: self::footerTemplate($config),
            fontStack: self::fontStack($config),
            buttonRadius: self::integer($config, 'button_radius', 0, 64),
            cardRadius: self::integer($config, 'card_radius', 0, 64),
            light: self::light($config),
            dark: self::dark($config),
        );
    }

    /**
     * The copyright line: the app's own footer with `:year` filled in, else Laravel's.
     */
    public function footerLine(): string
    {
        if ($this->footer !== null) {
            return __($this->footer, ['year' => date('Y')]);
        }

        return '© ' . date('Y') . ' ' . $this->name . '. ' . __('All rights reserved.');
    }

    /**
     * The value of the `color-scheme` and `supported-color-schemes` meta tags.
     *
     * A client that is told `light dark` may restyle a mail by itself, so it is
     * only claimed once the dark colours exist.
     */
    public function colorScheme(): string
    {
        return $this->dark !== null ? 'light dark' : 'light';
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function name(array $config): string
    {
        $value = self::required($config, 'name');

        if (! is_string($value) || trim($value) === '') {
            throw self::invalid('name', $value, 'must be a non-empty string');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function logoUrl(array $config): ?string
    {
        $value = $config['logo_url'] ?? null;

        if ($value === null) {
            return null;
        }

        $scheme = is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? parse_url($value, PHP_URL_SCHEME)
            : null;

        if (! is_string($value) || ! in_array($scheme, ['http', 'https'], true)) {
            throw self::invalid('logo_url', $value, 'must be an absolute http or https URL');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function footerTemplate(array $config): ?string
    {
        $value = $config['footer'] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw self::invalid('footer', $value, 'must be a string or null');
        }

        return $value;
    }

    /**
     * The stack is printed unescaped into the theme stylesheet, so the character
     * set is the guard: no `;`, `}` or `<` can end the declaration or the tag.
     *
     * @param  array<array-key, mixed>  $config
     */
    private static function fontStack(array $config): string
    {
        $value = self::required($config, 'font_stack');

        if (! is_string($value) || preg_match('/^[A-Za-z0-9 ,\'"\-]+$/D', $value) !== 1) {
            throw self::invalid(
                'font_stack',
                $value,
                'may only contain letters, digits, spaces, commas, quotes and hyphens',
            );
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function integer(array $config, string $key, int $min, int $max): int
    {
        $value = self::required($config, $key);

        if (! is_int($value) || $value < $min || $value > $max) {
            throw self::invalid($key, $value, "must be an integer from {$min} to {$max}");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, string>
     */
    private static function light(array $config): array
    {
        $colors = [];

        foreach (self::COLOR_KEYS as $key) {
            $colors[$key] = self::color("colors.light.{$key}", self::required($config, "colors.light.{$key}"));
        }

        return $colors;
    }

    /**
     * All eight dark colours or none: a half dark scheme would paint light text
     * on a light card in the clients that honour it.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<string, string>|null
     */
    private static function dark(array $config): ?array
    {
        $values = [];

        foreach (self::COLOR_KEYS as $key) {
            $values[$key] = Arr::get($config, "colors.dark.{$key}");
        }

        $set = array_filter($values, static fn (mixed $value): bool => $value !== null);

        if ($set === []) {
            return null;
        }

        $colors = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                throw self::invalid("colors.dark.{$key}", null, 'must be set when any other dark colour is set');
            }

            $colors[$key] = self::color("colors.dark.{$key}", $value);
        }

        return $colors;
    }

    private static function color(string $key, mixed $value): string
    {
        if (! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
            throw self::invalid($key, $value, 'must be a six digit hex colour such as #18181b');
        }

        return strtolower($value);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function required(array $config, string $key): mixed
    {
        if (! Arr::has($config, $key)) {
            throw self::invalid($key, null, 'is missing');
        }

        return Arr::get($config, $key);
    }

    private static function invalid(string $key, mixed $value, string $rule): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf('%s.%s %s, got %s.', self::CONFIG, $key, $rule, var_export($value, true)),
        );
    }
}
