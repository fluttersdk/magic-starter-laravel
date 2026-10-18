<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Contracts\SupportTool;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolContext;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolRegistry;
use FlutterSdk\MagicStarter\Tests\TestCase;
use InvalidArgumentException;

/**
 * Locks what a host's boot-time tool registration promises: class-strings in
 * registration order, one tool per name, and a fresh instance on every resolve
 * so no tool carries a lookup from one turn into the next.
 */
class SupportToolRegistryTest extends TestCase
{
    public function test_the_registry_is_bound_as_a_singleton(): void
    {
        $this->assertSame(
            $this->app->make(SupportToolRegistry::class),
            $this->app->make(SupportToolRegistry::class),
        );
    }

    public function test_a_new_registry_is_empty(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->isEmpty());
        $this->assertSame([], $registry->classes());
        $this->assertSame([], $registry->resolve());
    }

    public function test_registered_classes_keep_their_registration_order(): void
    {
        $registry = $this->registry();

        $registry->register(SupportToolRegistryTestUptimeTool::class);
        $registry->register(SupportToolRegistryTestIncidentsTool::class);

        $this->assertFalse($registry->isEmpty());
        $this->assertSame(
            [
                SupportToolRegistryTestUptimeTool::class,
                SupportToolRegistryTestIncidentsTool::class,
            ],
            $registry->classes(),
        );
        $this->assertSame(
            [
                'uptime',
                'incidents',
            ],
            array_map(fn (SupportTool $tool): string => $tool->name(), $registry->resolve()),
        );
    }

    public function test_resolve_builds_new_instances_on_every_call(): void
    {
        $registry = $this->registry();
        $registry->register(SupportToolRegistryTestUptimeTool::class);
        $registry->register(SupportToolRegistryTestIncidentsTool::class);

        $first = $registry->resolve();
        $second = $registry->resolve();

        $this->assertCount(2, $first);
        $this->assertInstanceOf(SupportToolRegistryTestUptimeTool::class, $first[0]);
        $this->assertInstanceOf(SupportToolRegistryTestIncidentsTool::class, $first[1]);
        $this->assertNotSame($first[0], $second[0]);
        $this->assertNotSame($first[1], $second[1]);
    }

    public function test_resolve_builds_through_the_container(): void
    {
        $registry = $this->registry();
        $registry->register(SupportToolRegistryTestUptimeTool::class);
        $this->app->bind(
            SupportToolRegistryTestUptimeTool::class,
            fn (): SupportToolRegistryTestUptimeTool => new SupportToolRegistryTestUptimeTool('from the container'),
        );

        $tool = $registry->resolve()[0];

        $this->assertSame('from the container', $tool->handle(SupportToolContext::none(), []));
    }

    public function test_register_refuses_a_class_that_is_not_a_tool(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(SupportToolRegistryTestNotATool::class);

        $registry->register(SupportToolRegistryTestNotATool::class);
    }

    public function test_register_refuses_a_class_that_does_not_exist(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidArgumentException::class);

        $registry->register('App\\Ai\\Tools\\Missing');
    }

    public function test_register_refuses_a_second_tool_with_a_taken_name(): void
    {
        $registry = $this->registry();
        $registry->register(SupportToolRegistryTestUptimeTool::class);

        try {
            $registry->register(SupportToolRegistryTestUptimeTwinTool::class);
            $this->fail('A second tool named [uptime] was registered.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('[uptime]', $exception->getMessage());
        }

        $this->assertSame(
            [
                SupportToolRegistryTestUptimeTool::class,
            ],
            $registry->classes(),
        );
    }

    public function test_register_refuses_the_same_class_twice(): void
    {
        $registry = $this->registry();
        $registry->register(SupportToolRegistryTestUptimeTool::class);

        $this->expectException(InvalidArgumentException::class);

        $registry->register(SupportToolRegistryTestUptimeTool::class);
    }

    private function registry(): SupportToolRegistry
    {
        return $this->app->make(SupportToolRegistry::class);
    }
}

class SupportToolRegistryTestUptimeTool implements SupportTool
{
    public function __construct(
        private string $answer = 'up',
    ) {}

    public function name(): string
    {
        return 'uptime';
    }

    public function description(): string
    {
        return 'The uptime of a monitor.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function handle(SupportToolContext $context, array $arguments): string
    {
        return $this->answer;
    }
}

class SupportToolRegistryTestUptimeTwinTool extends SupportToolRegistryTestUptimeTool {}

class SupportToolRegistryTestIncidentsTool implements SupportTool
{
    public function name(): string
    {
        return 'incidents';
    }

    public function description(): string
    {
        return 'The open incidents of the team.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function handle(SupportToolContext $context, array $arguments): string
    {
        return 'none open';
    }
}

class SupportToolRegistryTestNotATool
{
    public function name(): string
    {
        return 'not_a_tool';
    }
}
