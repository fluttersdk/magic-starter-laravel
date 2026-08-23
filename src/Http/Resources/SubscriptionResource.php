<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Actions\WriteEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Cashier\Concerns\ManagesCustomer;

/**
 * JSON shape for a billable subject's billing entitlement, in rail-neutral words.
 *
 * Every field is read from the billable's own row: `plan`/`plan_status` are the
 * single entitlement truth and the eight `plan_*` provenance columns are written
 * by whichever rail's event last claimed the subject ({@see WriteEntitlement}).
 * Naming a rail on the wire would force every client to learn one rail's dialect
 * and then relearn it when a second rail arrives, so the rail's own words survive
 * only in `provider_status`, which is opaque debug text and never a gate.
 *
 * FOUR of the twelve fields are non-null guaranteed, and a decoder may rely on
 * it: `plan_status`, `subscribed`, `provider`, `manage_via`. The other eight are
 * nullable, and `plan` is nullable HERE while the application this shape was
 * ported from could promise it non-null. That promise was kept by a tier reader
 * that read a revoked NULL back as its own free tier, and this package has no
 * tier vocabulary to name one with, so a null `plan` on this wire means the
 * billable holds nothing and the client decides what to call that. Four of the
 * remaining seven are nullable on the Stripe rail BY DESIGN rather than by
 * accident: `manage_url` and `grace_period_ends_at` have no Stripe source at all,
 * and `provider_status` and `product_id` stay null until a rail writes them.
 *
 * Every provenance read goes through {@see ReadsBillableAttributes} rather than
 * through a typed property, because this package ships the COLUMNS and not the
 * model that casts them. The application this was ported from casts `plan_renews`
 * to `boolean` and the two period dates to `datetime`; an adopter who casts none
 * of them gets whatever the driver hands back, so a typed read would put a `1` on
 * the wire where a decoder expects a boolean, and would reach
 * `?->toIso8601String()` on a plain string, which is a fatal on the billing
 * screen rather than a wrong field.
 *
 * NOTHING here may dial a payment rail. This resource is on the billing screen's
 * hot path, and both of Cashier's tempting accessors are live network calls:
 * `Subscription::currentPeriodEnd()` retrieves per subscription item, and
 * `billingPortalUrl()` mints a portal session. The provenance columns exist so
 * that the period, the product and the management destination are local reads.
 * The one Cashier read that stays is `subscriptions.trial_ends_at`, a plain
 * column, which is why `trial_ends_at` is Stripe-only by construction.
 *
 * @property Model $resource
 */
class SubscriptionResource extends JsonResource
{
    use ReadsBillableAttributes;

    /**
     * Transform the billable's entitlement into its wire shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $billable = $this->resource;
        $plan = $this->stringAttribute($billable, 'plan');
        $status = PlanStatus::fromWire($this->stringAttribute($billable, 'plan_status'));
        $provider = BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'));

        return [
            // The consuming application's own tier word, passed through, or null
            // when the billable holds nothing. This package never names a tier.
            'plan' => $plan,
            'plan_status' => $status->value,
            'subscribed' => $this->subscribed($plan, $status),
            // Nullable on purpose: null means the rail has not said whether this
            // subscription rolls over, which is not the claim `false` makes.
            'renews' => $this->booleanAttribute($billable, 'plan_renews'),
            'provider' => $provider->value,
            // Debug and support text only. It carries a rail's own word,
            // including words the neutral vocabulary has none for, so it must
            // never reach a gate or a computed field.
            'provider_status' => $this->stringAttribute($billable, 'plan_provider_status'),
            'product_id' => $this->stringAttribute($billable, 'plan_product_id'),
            'manage_via' => $this->manageVia($provider, $billable),
            'manage_url' => $this->manageUrl($provider, $billable),
            // When the paid period ends, whether or not it renews. Deliberately
            // not Cashier's `ends_at`, which answers a different question (the
            // date a cancellation takes effect) and has no store-rail meaning.
            'current_period_end' => $this->dateAttribute($billable, 'plan_current_period_end')?->toIso8601String(),
            'trial_ends_at' => $this->trialEndsAt($billable),
            'grace_period_ends_at' => $this->dateAttribute($billable, 'plan_grace_period_ends_at')?->toIso8601String(),
        ];
    }

    /**
     * Whether the billable currently holds a paid plan.
     *
     * Derived from the entitlement columns rather than from Cashier's `active()`,
     * whose vocabulary is Stripe's and therefore answers nothing about a
     * store-sold plan. Both halves are load-bearing: the tier says the subject
     * was sold something, and {@see PlanStatus::grants()} says that plan is still
     * owed to them, which keeps a customer with a failed charge subscribed while
     * their rail retries.
     *
     * The tier half is a NULL CHECK and nothing more, which is where this
     * diverges from the application it was ported from: there it compared the
     * tier against a free case, through a reader that answered "free" for both a
     * stored `'free'` and a revoked NULL. This package has no tier vocabulary and
     * cannot recognise a free tier by name. What it does have is the convention
     * its own writer keeps, that a revoked subject stores NULL rather than a
     * free-tier word, so "holds nothing" stays answerable without naming a tier.
     * A consumer that writes its own free tier into the column instead reads as
     * subscribed here; that is the price of the package never inventing a tier
     * name, and it is why `plan` reaches the wire raw for the client to read.
     */
    protected function subscribed(?string $plan, PlanStatus $status): bool
    {
        return $plan !== null && $status->grants();
    }

    /**
     * Where the customer manages this subscription, as one of `none`, `portal`,
     * `app_store` or `play_store`.
     *
     * Computed server-side so no client has to learn the rail-to-surface
     * mapping, and computed from the RAIL rather than from the request's
     * platform: the two are independent axes, and a subscription bought on an
     * iPhone is still managed in the App Store when the customer opens the web
     * app. It is a function of the rail plus one existence check, never of
     * whether the subject is currently subscribed, because a cancelled customer
     * still needs to reach invoices and remove a card.
     *
     * The `hasStripeId()` half is correctness, not padding: `billingPortalUrl()`
     * calls `assertCustomerExists()` as its first line
     * ({@see ManagesCustomer::billingPortalUrl()}), so naming `portal` for a
     * customer-less subject would be the wire pointing the client at an endpoint
     * that cannot answer. It is reached through `method_exists()` because the
     * billable model belongs to the consuming application: Cashier's trait is
     * theirs to apply, and an application that sells only through the two stores
     * has no reason to have applied it. An absent trait reads as `none`, the same
     * answer a Stripe subject with no customer gets, rather than as a fatal on
     * the billing screen.
     *
     * `portal` is a surface rather than a URL because a Stripe portal session is
     * short-lived, single-use and carries a baked-in `return_url`; the client
     * calls `GET /billing/portal` for it. There is deliberately no
     * `contact_sales` value, and no tier-derived value of any kind: this is a
     * function of the rail, and a catalogue tier sold by a conversation rather
     * than by a rail has no entitlement for a customer to manage. A plan-grid CTA
     * is not an entitlement-management state.
     *
     * The `match` has no `default` arm on purpose: a fifth rail has to be
     * decided here rather than inheriting a quiet `none`.
     */
    protected function manageVia(BillingProvider $provider, Model $billable): string
    {
        return match ($provider) {
            BillingProvider::APP_STORE => 'app_store',
            BillingProvider::PLAY_STORE => 'play_store',
            BillingProvider::STRIPE => $this->hasStripeCustomer($billable) ? 'portal' : 'none',
            BillingProvider::NONE, BillingProvider::MANUAL => 'none',
        };
    }

    /**
     * Whether this billable has a Stripe customer for a portal session to open
     * against.
     *
     * A local column read (`stripe_id`) and never a call to Stripe, in keeping
     * with this class's one hard rule.
     */
    protected function hasStripeCustomer(Model $billable): bool
    {
        return method_exists($billable, 'hasStripeId') && $billable->hasStripeId();
    }

    /**
     * The destination that pairs with a store `manage_via`, else null.
     *
     * Only a store rail has a durable one, and it is passed through from the
     * store aggregator's own management URL rather than hardcoded, so a store
     * moving its subscriptions page does not need an app release. Gated on the
     * rail rather than merely read from the column: a stale or mistaken value on
     * a non-store row must not reach a client that would open it.
     */
    protected function manageUrl(BillingProvider $provider, Model $billable): ?string
    {
        return $provider->isStore() ? $this->stringAttribute($billable, 'plan_manage_url') : null;
    }

    /**
     * When a Stripe trial ends, read off the subscription row.
     *
     * The one Cashier read on this path, and it is a plain column on a local row
     * rather than an accessor that retrieves anything. There is no provenance
     * column for it because no store rail reports a trial end in a form this wire
     * could promise, so the field is Stripe-only by construction and null
     * everywhere else.
     *
     * Two guards, for two different absences. `method_exists()` covers a billable
     * whose application never applied Cashier's trait, and the `Model` check
     * covers a billable that has it and holds no subscription of that type.
     */
    protected function trialEndsAt(Model $billable): ?string
    {
        if (! method_exists($billable, 'subscription')) {
            return null;
        }

        $subscription = $billable->subscription('default');

        if (! $subscription instanceof Model) {
            return null;
        }

        return $this->dateAttribute($subscription, 'trial_ends_at')?->toIso8601String();
    }
}
