<?php

namespace FlutterSdk\MagicStarter\Tests\Mail;

use FlutterSdk\MagicStarter\Notifications\TrialRefusedNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The branded mail theme, rendered through the real Markdown pipeline.
 *
 * The provider writes `mail.markdown.paths` and `mail.markdown.theme` during boot
 * and the Markdown singleton reads them once, at its first resolve, so every brand
 * setting is applied in an environment method that runs before boot, and no test
 * touches Markdown before the provider has booted.
 */
final class MailBrandRenderTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function brandOn($app): void
    {
        $this->configureBrand($app, [
            'primary' => '#34d399',
            'on_primary' => '#062e22',
            'heading' => '#f4f4f5',
            'text' => '#d4d4d8',
            'muted' => '#a1a1aa',
            'background' => '#09090b',
            'card' => '#18181b',
            'border' => '#27272a',
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function brandOnNoDark($app): void
    {
        $this->configureBrand($app, [
            'primary' => null,
            'on_primary' => null,
            'heading' => null,
            'text' => null,
            'muted' => null,
            'background' => null,
            'card' => null,
            'border' => null,
        ]);
    }

    /**
     * A cached config already carries the path the previous boot appended.
     *
     * @param  Application  $app
     */
    protected function brandOnCached($app): void
    {
        $this->brandOn($app);

        $app['config']->set('mail.markdown.paths', [
            $app->resourcePath('views/vendor/mail'),
            $this->packageMailPath(),
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function brandOnWithAppOverride($app): void
    {
        $this->brandOn($app);

        $app['config']->set('mail.markdown.paths', [
            __DIR__ . '/../Fixtures/mail-override',
        ]);
    }

    #[DefineEnvironment('brandOn')]
    public function test_the_brand_appends_the_package_path_once_and_sets_the_theme(): void
    {
        $this->assertSame(1, $this->occurrencesOfPackagePath());
        $this->assertSame('magic-starter::mail.theme', config('mail.markdown.theme'));
    }

    #[DefineEnvironment('brandOnCached')]
    public function test_a_cached_config_keeps_the_package_path_exactly_once(): void
    {
        $this->assertSame(1, $this->occurrencesOfPackagePath());
    }

    #[DefineEnvironment('brandOn')]
    public function test_a_mail_message_renders_with_the_brand(): void
    {
        $html = (string) $this->mailMessage()->render();

        $this->writeEvidence($html);

        $this->assertStringContainsString('src="https://cdn.example.test/mark.png"', $html);
        $this->assertStringContainsString('width="48"', $html);
        $this->assertStringContainsString('alt="Northwind"', $html);
        $this->assertMatchesRegularExpression('/class="header-name"[^>]*>Northwind<\/span>/', $html);
        $this->assertMatchesRegularExpression(
            '/<a href="https:\/\/acme\.test\/go" class="button button-primary"[^>]*'
            . 'style="[^"]*background-color: #008560/',
            $html,
        );
        $this->assertStringContainsString(date('Y') . ' Acme', $html);
        $this->assertStringContainsString('<a href="https://acme.test"', $html);
        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
        $this->assertMatchesRegularExpression(
            '/class="inner-body"[^>]*style="[^"]*border-style: solid/',
            $html,
        );
        $this->assertStringContainsString(
            'background-color: ' . config('magic-starter.mail.brand.colors.dark.background') . ' !important',
            $html,
        );
        $this->assertStringContainsString(
            'color: ' . config('magic-starter.mail.brand.colors.dark.on_primary') . ' !important',
            $html,
        );
    }

    #[DefineEnvironment('brandOn')]
    public function test_success_and_error_buttons_keep_white_text_whatever_the_brand_foreground(): void
    {
        config(['magic-starter.mail.brand.colors.light.on_primary' => '#111111']);

        $html = (string) (new MailMessage)->error()->line('Body')->action('Retry', 'https://acme.test/retry')->render();

        $this->assertMatchesRegularExpression(
            '/<a href="https:\/\/acme\.test\/retry" class="button button-error"[^>]*style="[^"]*color: #ffffff/',
            $html,
        );
        $this->assertStringNotContainsString('color: #111111', $html);
    }

    #[DefineEnvironment('brandOnNoDark')]
    public function test_without_dark_colours_the_mail_claims_only_the_light_scheme(): void
    {
        $html = (string) $this->mailMessage()->render();

        $this->assertStringContainsString('content="light"', $html);
        $this->assertStringNotContainsString('light dark', $html);
        $this->assertStringNotContainsString('prefers-color-scheme', $html);
    }

    #[DefineEnvironment('brandOn')]
    public function test_the_laravel_password_reset_mail_is_branded(): void
    {
        $html = (string) (new ResetPassword('tok-123'))
            ->toMail(new MailBrandRenderTestUser(['email' => 'ada@example.test']))
            ->render();

        $this->assertStringContainsString('src="https://cdn.example.test/mark.png"', $html);
    }

    #[DefineEnvironment('brandOnWithAppOverride')]
    public function test_a_component_in_the_app_paths_wins_over_the_package_one(): void
    {
        $html = (string) $this->mailMessage()->render();

        $this->assertStringContainsString('APP-OVERRIDE-HEADER', $html);
        $this->assertStringNotContainsString('class="header-name"', $html);
    }

    public function test_the_disabled_brand_leaves_the_mail_stock(): void
    {
        $this->assertSame(0, $this->occurrencesOfPackagePath());
        $this->assertSame('default', config('mail.markdown.theme'));

        $html = (string) $this->mailMessage()->render();

        $this->assertStringNotContainsString('header-name', $html);
        $this->assertStringContainsString('All rights reserved.', $html);
    }

    #[DefineEnvironment('brandOn')]
    public function test_the_text_part_carries_the_brand_name_and_footer(): void
    {
        $text = (string) app(Markdown::class)->renderText('notifications::email', $this->mailMessage()->data());

        $this->assertStringContainsString('Northwind: ', $text);
        $this->assertStringContainsString('© ' . date('Y') . ' Acme · [acme.test](https://acme.test)', $text);
    }

    #[DefineEnvironment('brandOn')]
    public function test_a_package_notification_renders_branded(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test']);

        $html = (string) (new TrialRefusedNotification)->toMail(null)->render();

        $this->assertStringContainsString('src="https://cdn.example.test/mark.png"', $html);
        $this->assertMatchesRegularExpression('/class="header-name"[^>]*>Northwind<\/span>/', $html);
    }

    /**
     * @param  Application  $app
     * @param  array<string, string|null>  $dark
     */
    private function configureBrand($app, array $dark): void
    {
        $app['config']->set('magic-starter.mail.brand.enabled', true);
        $app['config']->set('magic-starter.mail.brand.name', 'Northwind');
        $app['config']->set('magic-starter.mail.brand.logo_url', 'https://cdn.example.test/mark.png');
        $app['config']->set('magic-starter.mail.brand.footer', '© :year Acme · [acme.test](https://acme.test)');
        $app['config']->set('magic-starter.mail.brand.colors.light.primary', '#008560');
        $app['config']->set('magic-starter.mail.brand.colors.dark', $dark);
    }

    private function packageMailPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/views/mail';
    }

    private function occurrencesOfPackagePath(): int
    {
        return count(array_keys(config('mail.markdown.paths', []), $this->packageMailPath(), true));
    }

    private function mailMessage(): MailMessage
    {
        return (new MailMessage)
            ->subject('S')
            ->line('Body')
            ->action('Go', 'https://acme.test/go');
    }

    /**
     * Keeps the rendered mail for a person to open in a browser; a no-op unless asked for.
     */
    private function writeEvidence(string $html): void
    {
        $path = getenv('MAIL_BRAND_EVIDENCE');

        if (! is_string($path) || $path === '') {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $html);
    }
}

final class MailBrandRenderTestUser extends Model
{
    protected $guarded = [];

    public function getEmailForPasswordReset(): string
    {
        return $this->getAttribute('email');
    }
}
