<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for saving the body staff chose for one registered prompt.
 */
interface SavesAiPromptOverrides
{
    /**
     * Validate and save the override of one prompt, creating its row or replacing its body.
     *
     * @param  Authenticatable  $actor  The staff user saving the override.
     * @param  array<string, mixed>  $input  The prompt `key`, the new `body` and the `loaded_default_hash`
     *                                       the editor opened, which proves the edit started on the current default.
     * @return AiPromptOverride The saved override.
     *
     * @throws ValidationException On an unknown key, a blank body, a code default that changed since the
     *                             editor opened, or a body that lacks a required phrase; all keyed on the field.
     */
    public function save(Authenticatable $actor, array $input): AiPromptOverride;
}
