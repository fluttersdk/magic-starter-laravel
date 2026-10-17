<?php

namespace FlutterSdk\MagicStarter\Listeners;

use FlutterSdk\MagicStarter\Audit\Auditor;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Keeps the newsletter row's copy of the address in step with its owner's.
 *
 * Listens to the user model's `updated` event, so the profile action, any
 * `$user->update()` and the admin panel's user form all reach it. The column is
 * unique: an owner-less row already holding the new address is removed first,
 * because the person who now owns that address is the one subscribing under it.
 * Both writes go through the query builder, so the sync is not a change the
 * audit trail records.
 */
class SyncNewsletterEmail
{
    /**
     * @param  string  $event  e.g. `eloquent.updated: App\Models\User`.
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        $user = $payload[0] ?? null;

        if (! $user instanceof Model || ! Auditor::isUser($user) || ! $user->wasChanged('email')) {
            return;
        }

        if (empty($user->email)) {
            return;
        }

        $email = Str::lower($user->email);

        $unclaimed = NewsletterSubscriber::query()
            ->whereNull('user_id')
            ->whereRaw('lower(email) = ?', [$email])
            ->pluck('id')
            ->all();

        if ($unclaimed !== []) {
            // The trail of a removed row keeps the address it was written with, and no erasure can
            // find it later (it has no owner), so it goes with the row, as the migration does it.
            if (Schema::hasTable('magic_starter_audits')) {
                DB::table('magic_starter_audits')
                    ->where('auditable_type', NewsletterSubscriber::class)
                    ->whereIn('auditable_id', array_map('strval', $unclaimed))
                    ->delete();
            }

            NewsletterSubscriber::query()->whereKey($unclaimed)->delete();
        }

        NewsletterSubscriber::query()
            ->where('user_id', $user->getKey())
            ->update(['email' => $email]);
    }
}
