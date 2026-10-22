<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Locks the route-binding guard `HasUuids` gives a model and `ConditionallyUsesUuids` has to give too: a route
 * key that cannot be a uuid is a missing model, answered 404 before any query, because PostgreSQL rejects a
 * malformed value against a uuid column and the request would otherwise end in a 500.
 */
final class ConditionallyUsesUuidsRouteBindingTest extends TestCase
{
    public function test_a_malformed_key_is_a_missing_model_when_uuids_are_on(): void
    {
        config(['magic-starter.use_uuids' => true]);

        $team = new ConcreteTeam;

        foreach (['not-a-uuid', '12345', ''] as $malformed) {
            try {
                $team->resolveRouteBindingQuery(ConcreteTeam::query(), $malformed);
                $this->fail("'{$malformed}' reached the query.");
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(ConcreteTeam::class, $exception->getModel());
            }
        }
    }

    public function test_a_well_formed_key_reaches_the_query(): void
    {
        config(['magic-starter.use_uuids' => true]);

        $query = (new ConcreteTeam)->resolveRouteBindingQuery(ConcreteTeam::query(), (string) Str::orderedUuid());

        $this->assertInstanceOf(Builder::class, $query);
    }

    public function test_a_field_other_than_the_key_is_not_checked(): void
    {
        config(['magic-starter.use_uuids' => true]);

        $query = (new ConcreteTeam)->resolveRouteBindingQuery(ConcreteTeam::query(), 'Summer house', 'name');

        $this->assertInstanceOf(Builder::class, $query);
    }

    public function test_integer_keys_pass_through_when_uuids_are_off(): void
    {
        config(['magic-starter.use_uuids' => false]);

        $query = (new ConcreteTeam)->resolveRouteBindingQuery(ConcreteTeam::query(), '12345');

        $this->assertInstanceOf(Builder::class, $query);
    }
}
