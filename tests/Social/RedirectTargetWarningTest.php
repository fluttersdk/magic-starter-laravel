<?php

namespace FlutterSdk\MagicStarter\Tests\Social;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The boot-time warning for an Android redirect target that is not an https App Link.
 *
 * The log is captured from the provider's own boot, through a MessageLogged
 * listener registered before the provider boots, so the test measures the
 * one warning a real boot emits rather than a second, hand-driven boot.
 */
class RedirectTargetWarningTest extends TestCase
{
    /**
     * @var list<MessageLogged>
     */
    private array $logged = [];

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['events']->listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    /**
     * @param  Application  $app
     */
    protected function socialLoginOn($app): void
    {
        $app['config']->set('magic-starter.features', [
            Features::socialLogin(),
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function androidCustomScheme($app): void
    {
        $this->socialLoginOn($app);
        $app['config']->set('magic-starter.social.redirects.android', 'com.example.app://auth/social');
    }

    /**
     * @param  Application  $app
     */
    protected function androidAppLink($app): void
    {
        $this->socialLoginOn($app);
        $app['config']->set('magic-starter.social.redirects.android', 'https://app.example.test/auth/social');
    }

    /**
     * @param  Application  $app
     */
    protected function socialLoginOff($app): void
    {
        $app['config']->set('magic-starter.social.redirects.android', 'com.example.app://auth/social');
    }

    #[DefineEnvironment('androidCustomScheme')]
    public function test_an_android_custom_scheme_target_is_warned_about_once(): void
    {
        $warnings = $this->androidWarnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]->level);
        $this->assertSame('com.example.app://auth/social', $warnings[0]->context['target'] ?? null);
    }

    #[DefineEnvironment('androidAppLink')]
    public function test_an_android_https_app_link_is_not_warned_about(): void
    {
        $this->assertSame([], $this->androidWarnings());
    }

    #[DefineEnvironment('socialLoginOn')]
    public function test_an_unset_android_target_is_not_warned_about(): void
    {
        $this->assertSame([], $this->androidWarnings());
    }

    #[DefineEnvironment('socialLoginOff')]
    public function test_nothing_is_warned_about_while_social_login_is_off(): void
    {
        $this->assertSame([], $this->androidWarnings());
    }

    /**
     * @return list<MessageLogged>
     */
    private function androidWarnings(): array
    {
        return array_values(array_filter(
            $this->logged,
            fn (MessageLogged $event): bool => str_contains($event->message, 'redirects.android'),
        ));
    }
}
