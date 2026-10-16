<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\FrontendUrl;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\URL;

/**
 * Locks the absolute link a mail carries: the frontend base when one is usable, the application root when not,
 * so a mail client never receives a relative path it cannot resolve.
 */
final class FrontendUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('https://api.example.test');
        URL::forceScheme('https');
    }

    public function test_to_joins_the_frontend_base_and_the_path(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test/']);

        $this->assertSame('https://app.example.test/teams/billing', FrontendUrl::to('/teams/billing'));
    }

    public function test_to_falls_back_to_the_app_frontend_url(): void
    {
        config(['magic-starter.frontend_url' => '', 'app.frontend_url' => 'https://fallback.example.test']);

        $this->assertSame('https://fallback.example.test/teams/billing', FrontendUrl::to('/teams/billing'));
    }

    public function test_to_falls_back_to_the_application_root_when_no_base_is_usable(): void
    {
        foreach (['', '   ', '/', null] as $unusable) {
            config(['magic-starter.frontend_url' => $unusable, 'app.frontend_url' => null]);

            $this->assertSame('https://api.example.test/teams/billing', FrontendUrl::to('/teams/billing'));
        }
    }

    public function test_the_client_thread_path_fills_the_id_into_the_default_route(): void
    {
        $this->assertSame('/support/7', SupportConversation::clientThreadPath(7));
        $this->assertSame('/support/0194-abc', SupportConversation::clientThreadPath('0194-abc'));
    }

    public function test_the_client_thread_path_follows_the_configured_route(): void
    {
        config(['magic-starter.support.client_thread_path' => '/help/threads/{id}/view']);

        $this->assertSame('/help/threads/9/view', SupportConversation::clientThreadPath(9));
    }

    public function test_a_published_config_without_the_path_key_keeps_the_default(): void
    {
        config(['magic-starter.support' => []]);

        $this->assertSame('/support/7', SupportConversation::clientThreadPath(7));
    }

    public function test_a_thread_link_is_absolute_in_the_frontend(): void
    {
        config(['magic-starter.frontend_url' => 'https://app.example.test']);

        $this->assertSame(
            'https://app.example.test/support/7',
            FrontendUrl::to(SupportConversation::clientThreadPath(7)),
        );
    }
}
