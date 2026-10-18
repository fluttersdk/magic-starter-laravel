<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolContext;

/**
 * One read the support AI may make about the customer's own account while it
 * drafts an answer.
 *
 * The host registers the class with {@see \FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolRegistry}
 * and maps it onto its own AI SDK; the package never imports one. Three rules
 * hold for every tool, because what it returns lands in a model prompt:
 *
 * - Never throw for a data reason (no team, nothing found, a refused
 *   argument): return a short refusal sentence the model can relay. Only a
 *   genuine fault, such as a dead database, may propagate.
 * - Never return a URL, a credential or another team's data. The team comes
 *   from {@see SupportToolContext::team()} alone, never from an argument the
 *   model chose. Leave out the url columns you own; `UntrustedText` replaces
 *   the links a customer wrote, but keeps a bare name without a path.
 * - Pass every tenant-written text (a monitor name, an incident note) through
 *   {@see \FlutterSdk\MagicStarter\Support\SupportAi\Tools\UntrustedText}, so the
 *   model reads it as data and not as instructions.
 */
interface SupportTool
{
    /**
     * The name the model calls the tool by: snake_case, unique in the registry.
     */
    public function name(): string;

    /**
     * What the tool answers, written for the model choosing whether to call it.
     */
    public function description(): string;

    /**
     * The arguments as a JSON-schema `object`, which the host maps to its SDK.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * @param  array<string, mixed>  $arguments  As the model sent them: untrusted, validate before use.
     * @return string The answer, or a short refusal; never an exception for a data reason.
     */
    public function handle(SupportToolContext $context, array $arguments): string;
}
