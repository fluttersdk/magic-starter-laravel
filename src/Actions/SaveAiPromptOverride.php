<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\SavesAiPromptOverrides;
use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use FlutterSdk\MagicStarter\Support\AiPrompts\PromptRegistry;
use FlutterSdk\MagicStarter\Support\AiPrompts\RequiredPhrases;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Default prompt override save action.
 *
 * Every refusal is a `ValidationException` keyed on the field. The stored
 * `default_hash` is the registry's current hash, never the client's value: the
 * client's `loaded_default_hash` only proves the editor opened on the code
 * default that is live now, and an edit started on an older one is refused even
 * when its body is valid, so a changed default is reviewed instead of silently
 * overwritten.
 */
class SaveAiPromptOverride implements SavesAiPromptOverrides
{
    public function __construct(
        private readonly PromptRegistry $registry,
    ) {}

    /**
     * Validate and save the override of one prompt, creating its row or replacing its body.
     *
     * @param  Authenticatable  $actor  The staff user saving the override.
     * @param  array<string, mixed>  $input  The prompt `key`, the new `body` and the `loaded_default_hash`.
     * @return AiPromptOverride The saved override.
     *
     * @throws ValidationException
     */
    public function save(Authenticatable $actor, array $input): AiPromptOverride
    {
        // 1. A key nobody registered has no default to override.
        $key = (string) ($input['key'] ?? '');

        $definition = $this->registry->find($key);

        if ($definition === null) {
            throw $this->refusal('key', 'unknown_key', ['key' => $key]);
        }

        $body = (string) ($input['body'] ?? '');

        // 2. A blank body would send the model an empty prompt.
        if (trim($body) === '') {
            throw $this->refusal('body', 'blank_body');
        }

        // 3. The editor opened on a code default that has since changed.
        if (($input['loaded_default_hash'] ?? null) !== $definition->defaultHash()) {
            throw $this->refusal('body', 'default_changed');
        }

        // 4. The body must keep every phrase the host's parser or fence depends on.
        $missing = RequiredPhrases::missing($body, $definition->requiredPhrases);

        if ($missing !== []) {
            throw $this->refusal('body', 'missing_phrases', [
                'phrases' => RequiredPhrases::describe($missing),
            ]);
        }

        // 5. One row per key, with the hash computed here and never taken from the client.
        return AiPromptOverride::query()->updateOrCreate(
            ['key' => $key],
            [
                'body' => $body,
                'default_hash' => $definition->defaultHash(),
            ],
        );
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function refusal(string $field, string $code, array $replace = []): ValidationException
    {
        return ValidationException::withMessages([
            $field => Lang::get('magic-starter::admin_ai.refusals.' . $code, $replace),
        ]);
    }
}
