<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\MailLocale;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Model;

/**
 * Locks the language a mail built in somebody else's request is pinned to: the notifiable's own preference when it
 * declares one, else the locale of the request that builds the mail.
 */
final class MailLocaleTest extends TestCase
{
    public function test_a_preference_wins_over_the_request_locale(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('tr', MailLocale::for($this->preferring('tr')));
    }

    public function test_a_preference_of_null_falls_back_to_the_request_locale(): void
    {
        $this->app->setLocale('tr');

        $this->assertSame('tr', MailLocale::for($this->preferring(null)));
    }

    public function test_a_notifiable_without_a_preference_gets_the_request_locale(): void
    {
        $this->app->setLocale('tr');

        $this->assertSame('tr', MailLocale::for(new class extends Model {}));
    }

    public function test_no_notifiable_gets_the_request_locale(): void
    {
        $this->app->setLocale('de');

        $this->assertSame('de', MailLocale::for(null));
    }

    private function preferring(?string $locale): HasLocalePreference
    {
        return new class($locale) implements HasLocalePreference
        {
            public function __construct(private readonly ?string $locale) {}

            public function preferredLocale(): ?string
            {
                return $this->locale;
            }
        };
    }
}
