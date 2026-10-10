<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\BillingLog;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;

/**
 * Locks where a billing line goes: the configured channel when one is named,
 * and the plain default logger otherwise, so an unconfigured application (and
 * every `Log::spy()` in this suite) sees exactly the calls it saw before.
 */
class BillingLogTest extends TestCase
{
    public function test_without_a_channel_each_level_goes_to_the_default_logger(): void
    {
        config()->set('magic-starter.billing.log_channel', null);
        Log::spy();

        BillingLog::info('Applied.', ['type' => 'a']);
        BillingLog::warning('Refused.', ['type' => 'b']);
        BillingLog::error('Failed.', ['type' => 'c']);

        Log::shouldHaveReceived('info')->once()->with('Applied.', ['type' => 'a']);
        Log::shouldHaveReceived('warning')->once()->with('Refused.', ['type' => 'b']);
        Log::shouldHaveReceived('error')->once()->with('Failed.', ['type' => 'c']);
        Log::shouldNotHaveReceived('channel');
    }

    public function test_a_configured_channel_receives_each_level(): void
    {
        config()->set('magic-starter.billing.log_channel', 'billing');
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('Applied.', ['type' => 'a']);
        $logger->shouldReceive('warning')->once()->with('Refused.', ['type' => 'b']);
        $logger->shouldReceive('error')->once()->with('Failed.', []);
        Log::shouldReceive('channel')->times(3)->with('billing')->andReturn($logger);

        BillingLog::info('Applied.', ['type' => 'a']);
        BillingLog::warning('Refused.', ['type' => 'b']);
        BillingLog::error('Failed.');
    }
}
