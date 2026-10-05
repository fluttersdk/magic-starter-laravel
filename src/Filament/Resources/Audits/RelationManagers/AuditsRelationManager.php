<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Audits\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Filament\Resources\Audits\AuditResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The audit rows about the owner record, for any model that is audited.
 *
 * The rows are matched on the owner's morph class and key instead of a
 * relationship, so the owner model needs no `audits()` relation and an
 * application that never added the `HasAudits` trait still gets the tab.
 */
class AuditsRelationManager extends RelationManager
{
    protected static ?string $relatedResource = AuditResource::class;

    /**
     * Fixed on, like the resources: without it Filament resolves a policy by
     * calling the missing relation on the owner.
     */
    public static function shouldSkipAuthorization(): bool
    {
        return true;
    }

    /**
     * The audit query for the owner, wrapped in a relation.
     *
     * Filament reads the table query through `getQuery()` on whatever this
     * returns, which on a bare Eloquent builder is the base query and breaks the
     * model's casts. The relation is built without its own constraints, and the
     * key is compared as a string, which is how the audit table stores it: an
     * integer binding against a varchar column fails on PostgreSQL.
     *
     * @return HasMany<Audit, Model>
     */
    public function getRelationship(): HasMany
    {
        $owner = $this->getOwnerRecord();

        $audits = Audit::query()
            ->where('auditable_type', $owner->getMorphClass())
            ->where('auditable_id', (string) $owner->getKey());

        return Relation::noConstraints(
            static fn (): HasMany => new HasMany($audits, $owner, 'auditable_id', $owner->getKeyName()),
        );
    }
}
