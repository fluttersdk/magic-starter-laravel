<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract for redeeming a sign-in handoff code for the user it names.
 */
interface ExchangesHandoffCodes
{
    /**
     * Redeem a code, once, for the user who minted it.
     *
     * The code is spent whatever the outcome, so a refused code cannot be
     * presented again. Every refusal answers null and no reason: telling an
     * unknown code from a spent, an expired or an orphaned one would make the
     * exchange an oracle.
     *
     * @return array{user: Model, next: string|null}|null The user to sign in and the path to open, or null.
     */
    public function exchange(string $code): ?array;
}
