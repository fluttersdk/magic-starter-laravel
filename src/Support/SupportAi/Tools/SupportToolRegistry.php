<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Contracts\SupportTool;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Every support tool the host registered, as class-strings in registration order.
 *
 * Bound as a singleton and filled once at boot. It keeps class-strings, not
 * instances, because one Horizon worker answers turns for many teams: an
 * instance kept here could carry a lookup from one team's turn into the next,
 * so {@see self::resolve()} builds fresh ones for every turn. The order is the
 * registration order, so the tool list a host sends stays byte-stable for
 * prompt caching.
 *
 * There is no feature flag: an empty registry is the off switch, and a host
 * checks {@see self::isEmpty()} before offering the model any tool.
 */
final class SupportToolRegistry
{
    /** @var array<string, class-string<SupportTool>> Keyed by tool name. */
    private array $tools = [];

    public function __construct(
        private Container $container,
    ) {}

    /**
     * @throws InvalidArgumentException When the class is not a SupportTool or its name is taken.
     */
    public function register(string $toolClass): void
    {
        if (! is_subclass_of($toolClass, SupportTool::class)) {
            throw new InvalidArgumentException(
                "The support tool [{$toolClass}] does not implement " . SupportTool::class . '.',
            );
        }

        $name = $this->make($toolClass)->name();

        if (isset($this->tools[$name])) {
            throw new InvalidArgumentException(
                "A support tool named [{$name}] is already registered by [{$this->tools[$name]}].",
            );
        }

        $this->tools[$name] = $toolClass;
    }

    /**
     * @return list<class-string<SupportTool>> In registration order.
     */
    public function classes(): array
    {
        return array_values($this->tools);
    }

    /**
     * New instances built through the container on every call, in registration order.
     *
     * @return list<SupportTool>
     */
    public function resolve(): array
    {
        return array_map(
            fn (string $toolClass): SupportTool => $this->make($toolClass),
            $this->classes(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    /**
     * @param  class-string<SupportTool>  $toolClass
     */
    private function make(string $toolClass): SupportTool
    {
        return $this->container->make($toolClass);
    }
}
