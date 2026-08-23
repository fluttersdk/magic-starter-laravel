<?php

namespace FlutterSdk\MagicStarter\Tests\Contracts;

use FlutterSdk\MagicStarter\Contracts\ReportsUsage;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Guards the refusal at the heart of {@see ReportsUsage}: the package binds
 * nothing, on purpose, even with billing enabled.
 *
 * A test that only asserts the interface exists would pass just as well with
 * a default binding silently added later, because "exists" and "unbound" are
 * different claims. So this asserts the container's OWN answer,
 * `app()->bound(ReportsUsage::class)`, and proves that assertion can actually
 * fail by binding an implementation locally, watching it go red, then
 * removing the binding and watching it go green again. Without that half, a
 * broken assertion (one that is always false, say) would pass for the same
 * wrong reason a missing one would.
 */
final class ReportsUsageTest extends TestCase
{
    public function test_reports_usage_is_not_bound_with_billing_enabled(): void
    {
        config(['magic-starter.features' => [Features::billing()]]);

        $this->assertFalse($this->app->bound(ReportsUsage::class));
    }

    public function test_reports_usage_is_not_bound_with_billing_disabled(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse($this->app->bound(ReportsUsage::class));
    }

    public function test_the_unbound_assertion_can_actually_fail(): void
    {
        config(['magic-starter.features' => [Features::billing()]]);

        // 1. Prove the assertion is not vacuous: bind a default locally and
        //    watch the very assertion the other tests rely on go RED.
        $this->app->bind(ReportsUsage::class, function () {
            return new class implements ReportsUsage
            {
                public function forBillable(Model $billable): array
                {
                    return [];
                }
            };
        });

        $this->assertTrue($this->app->bound(ReportsUsage::class));

        try {
            $this->assertFalse($this->app->bound(ReportsUsage::class));
            $this->fail('Expected the unbound assertion to fail once a default binding is present.');
        } catch (ExpectationFailedException) {
            // Expected: this is the RED half.
        }

        // 2. Remove the binding and confirm the same assertion goes GREEN again.
        $this->app->offsetUnset(ReportsUsage::class);

        $this->assertFalse($this->app->bound(ReportsUsage::class));
    }
}
