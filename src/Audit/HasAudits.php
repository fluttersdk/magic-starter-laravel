<?php

namespace FlutterSdk\MagicStarter\Audit;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives an application model the audit rows written about it.
 *
 * Auditing itself does not depend on this trait: the global listener records
 * every model. The trait only adds the read side.
 */
trait HasAudits
{
    /**
     * @return MorphMany<Audit, $this>
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
}
