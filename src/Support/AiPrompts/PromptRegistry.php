<?php

namespace FlutterSdk\MagicStarter\Support\AiPrompts;

use Closure;
use InvalidArgumentException;

/**
 * Every prompt the host registered, keyed by the host's own prompt name.
 *
 * Bound as a singleton and filled once at boot. A registration mistake (a key
 * defined twice, a required phrase its own default lacks) throws from
 * {@see self::define()}, so it fails the host's boot and its tests rather than
 * a call in production.
 */
final class PromptRegistry
{
    /** @var array<string, PromptDefinition> */
    private array $definitions = [];

    /**
     * @param  Closure(): string  $body
     * @param  Closure(): string  $boundary
     * @param  list<string>  $requiredPhrases
     *
     * @throws InvalidArgumentException When the key is taken or the default lacks a required phrase.
     */
    public function define(
        string $key,
        string $label,
        Closure $body,
        Closure $boundary,
        array $requiredPhrases = [],
    ): void {
        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("The AI prompt [{$key}] is already defined.");
        }

        $definition = new PromptDefinition($key, $label, $body, $boundary, $requiredPhrases);
        $missing = RequiredPhrases::missing($definition->body(), $requiredPhrases);

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "The default body of the AI prompt [{$key}] lacks its required phrases: "
                . implode(', ', array_map(static fn (string $phrase): string => "[{$phrase}]", $missing)) . '.',
            );
        }

        $this->definitions[$key] = $definition;
    }

    /**
     * @throws InvalidArgumentException When no prompt is defined under the key.
     */
    public function get(string $key): PromptDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException("No AI prompt is defined as [{$key}].");
    }

    /**
     * @return array<string, PromptDefinition> In definition order.
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
