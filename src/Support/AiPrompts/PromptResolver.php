<?php

namespace FlutterSdk\MagicStarter\Support\AiPrompts;

use FlutterSdk\MagicStarter\Contracts\ResolvesPrompts;
use FlutterSdk\MagicStarter\Enums\AiPromptSource;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * Answers a registered prompt's effective text: a body, then two newlines, then
 * the definition's boundary.
 *
 * The boundary is never stored or editable: it comes from the code registration
 * on every resolve, so no override can remove or alter it. An override replaces
 * the body only while its `default_hash` equals the CURRENT code default's hash
 * and it keeps every required phrase; staleness means the code default changed
 * after staff edited, and such an override is ignored until someone re-saves it.
 * With the ai-prompts feature off nothing is read and the code default answers.
 *
 * The overrides are read as one map through the shared cache under
 * {@see AiPromptOverride::CACHE_KEY}, which the model forgets on every save and
 * delete once the write has committed. There is deliberately no instance or
 * static memo on top: Octane and Horizon workers live for many requests, and a
 * memo would keep serving a deleted override until the worker restarts. The map
 * does expire after {@see self::CACHE_TTL_SECONDS}: a reader that rebuilt the map
 * from rows read before a commit can cache it just after the commit's forget, and
 * the expiry bounds how long that lost race can serve the old overrides.
 *
 * The read runs in its own transaction, a savepoint when the host already has one
 * open, so a failed read on PostgreSQL rolls back to the savepoint instead of
 * aborting the host's transaction.
 *
 * Failure mode: a database failure while reading (a missing table, a lost
 * connection, a database-backed cache store;
 * {@see \Illuminate\Database\QueryException} is a PDOException) answers the
 * code default and logs one warning per process. A failure of any other cache
 * store, and anything that is not a database failure, propagates.
 */
final class PromptResolver implements ResolvesPrompts
{
    /** How long the cached map of overrides lives at most, however a write reaches the database. */
    public const CACHE_TTL_SECONDS = 300;

    /** Whether this process already warned that the overrides could not be read. */
    private static bool $warnedUnreadable = false;

    public function __construct(
        private readonly PromptRegistry $registry,
    ) {}

    public function resolve(string $key): ResolvedPrompt
    {
        $definition = $this->registry->get($key);
        $body = $definition->body();
        $defaultHash = PromptDefinition::hashBody($body);
        $override = $this->currentOverride($definition, $defaultHash);

        return new ResolvedPrompt(
            text: ($override ?? $body) . "\n\n" . $definition->boundary(),
            source: $override === null ? AiPromptSource::CODE : AiPromptSource::OVERRIDE,
            defaultHash: $defaultHash,
        );
    }

    /**
     * The override body that applies to the definition, or null when the code default does.
     */
    private function currentOverride(PromptDefinition $definition, string $defaultHash): ?string
    {
        if (! Features::hasAiPromptFeatures()) {
            return null;
        }

        $override = $this->overrides($definition->key)[$definition->key] ?? null;

        if ($override === null || $override['default_hash'] !== $defaultHash) {
            return null;
        }

        if (RequiredPhrases::missing($override['body'], $definition->requiredPhrases) !== []) {
            return null;
        }

        return $override['body'];
    }

    /**
     * Every stored override keyed by prompt key, or none when the database cannot answer.
     *
     * @return array<string, array{body: string, default_hash: string}>
     */
    private function overrides(string $key): array
    {
        try {
            return Cache::remember(
                AiPromptOverride::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                static fn (): array => DB::transaction(static fn (): array => AiPromptOverride::query()
                    ->get([
                        'key',
                        'body',
                        'default_hash',
                    ])
                    ->mapWithKeys(static fn (AiPromptOverride $override): array => [
                        $override->key => [
                            'body' => $override->body,
                            'default_hash' => $override->default_hash,
                        ],
                    ])
                    ->all()),
            );
        } catch (PDOException $exception) {
            if (! self::$warnedUnreadable) {
                self::$warnedUnreadable = true;

                Log::warning('The AI prompt overrides could not be read; the code default answers.', [
                    'reason' => 'ai_prompt_overrides_unreadable',
                    'key' => $key,
                    'exception' => $exception->getMessage(),
                ]);
            }

            return [];
        }
    }
}
