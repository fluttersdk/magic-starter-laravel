<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for removing a prompt override, which returns the prompt to its code default.
 */
interface DeletesAiPromptOverrides
{
    /**
     * Delete the given override.
     *
     * @param  Authenticatable  $actor  The staff user deleting the override.
     * @param  AiPromptOverride  $override  The override to delete.
     */
    public function delete(Authenticatable $actor, AiPromptOverride $override): void;
}
