<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesAiPromptOverrides;
use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default prompt override deletion action.
 */
class DeleteAiPromptOverride implements DeletesAiPromptOverrides
{
    /**
     * Delete the given override.
     *
     * @param  Authenticatable  $actor  The staff user deleting the override.
     * @param  AiPromptOverride  $override  The override to delete.
     */
    public function delete(Authenticatable $actor, AiPromptOverride $override): void
    {
        $override->delete();
    }
}
