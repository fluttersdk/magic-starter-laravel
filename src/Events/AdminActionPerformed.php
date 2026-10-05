<?php

namespace FlutterSdk\MagicStarter\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin panel write went through a package contract and succeeded.
 *
 * Dispatched only after the contract returned, never for a refusal, so a
 * listener records what actually happened. The event carries no Filament type:
 * it is the seam an audit trail or an application listener hooks into.
 */
class AdminActionPerformed
{
    /**
     * @param  Authenticatable  $actor  The panel user who performed the write.
     * @param  string  $action  A stable dotted name such as `user.updated`.
     * @param  Model|null  $subject  The record written to, when there is one.
     * @param  array<string, mixed>  $context  Extra facts a listener needs; never secrets.
     */
    public function __construct(
        public Authenticatable $actor,
        public string $action,
        public ?Model $subject,
        public array $context = [],
    ) {}
}
