# Billing

- [Introduction](#introduction)
- [Requirements](#requirements)
- [The Catalogue](#the-catalogue)
- [Product Keys](#product-keys)
- [Prices](#prices)
- [Store Ids](#store-ids)
- [Reading the Entitlement](#reading-the-entitlement)
- [Endpoints](#endpoints)
- [Refusals](#refusals)
- [Trials](#trials)
- [RevenueCat Webhook](#revenuecat-webhook)
- [Agent Commands](#agent-commands)
- [Audit Log](#audit-log)
- [Admin Panel](#admin-panel)
- [Account Deletion](#account-deletion)
- [Upgrading](#upgrading)

---

<a name="introduction"></a>
## Introduction

Billing sells subscriptions on two rails and keeps one entitlement for both. The **card rail** is Stripe through Laravel Cashier, and the **store rail** is the App Store and Google Play through RevenueCat. Whichever rail charged last writes the same columns on the billable (`plan`, `plan_status`, `plan_provider`, `plan_product_id`, and the rest of the provenance set), and a rail may only revoke what it granted.

Everything you sell is described once, in `config('magic-starter.billing')`. The package never writes to Stripe, App Store Connect, Google Play or RevenueCat: [`billing:manifest`](#agent-commands) prints what each of them has to hold, and [`billing:doctor`](#agent-commands) checks that they do. An LLM agent with the vendor CLIs can apply the manifest from the [billing setup reference](../../resources/boost/skills/magic-starter-development/references/billing-setup.md).

Throughout this document `{prefix}` is `config('magic-starter.route_prefix')`.

**Feature:** `Features::billing()`. No billing route, command or webhook is registered while the feature is off.

---

<a name="requirements"></a>
## Requirements

- `Features::billing()` in `magic-starter.features`, then `php artisan magic-starter:install --features=billing` for the migrations. Do not run `vendor:publish --tag=cashier-migrations`: the package ships its own three, which resolve the billable table and the key type from config.
- Already ran Cashier's own migrations? Set `MAGIC_STARTER_PACKAGE_SUBSCRIPTION_MODELS=false` (`billing.package_subscription_models`, default `true`). Cashier's `subscriptions` and `subscription_items` are keyed by a bigint, and the package's `Subscription` and `SubscriptionItem` models key by `use_uuids`; with UUIDs on, every subscription write fails and the Stripe webhook answers 500 until Stripe gives up. With the switch off the package hands Cashier neither model, so Cashier's own integer-keyed models stay. `billing:doctor` reports the mismatch as `schema.subscription_keys`.
- `billing.billable` is `'user'` (the default) or `'team'`. `'team'` needs the teams feature, and boot refuses it without. The billable is the subject the entitlement is written to, and its key is the RevenueCat `app_user_id`.
- Cashier's `Billable` trait on the billable model for the card rail. A store-only application can leave it off.
- Stripe: `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`. The webhook is Cashier's own path, `stripe/webhook`.
- Stores: `REVENUECAT_SECRET_API_KEY` and `REVENUECAT_WEBHOOK_SECRET`, see [RevenueCat Webhook](#revenuecat-webhook). `REVENUECAT_API_V2_KEY` and `REVENUECAT_PROJECT_ID` are read only by `billing:doctor --remote`.

The catalogue is validated at boot while the feature is on. A catalogue that could sell the wrong thing throws a `LogicException` naming the key, product or tier, instead of booting.

---

<a name="the-catalogue"></a>
## The Catalogue

Four keys describe what you sell. One product entry carries everything every rail needs.

```php
'billing' => [
    'billable' => 'user',

    'tier_order' => ['free', 'pro', 'business'],

    'tiers' => [
        'free' => ['name' => 'Free', 'features' => ['One project']],
        'pro' => ['name' => 'Pro', 'recommended' => true, 'limits' => ['seats' => 5]],
        'business' => ['name' => 'Business', 'limits' => ['seats' => 50]],
    ],

    'products' => [
        'pro_monthly' => [
            'type' => 'subscription',
            'tier' => 'pro',
            'cycle' => 'monthly',
            'prices' => [
                'web' => ['USD' => 2900, 'TRY' => 49900],
                'app_store' => ['TRY' => 59999],
            ],
            'refs' => [
                'stripe_price' => env('CASHIER_PRICE_PRO_MONTHLY'),
                'app_store' => 'com.example.app.pro.monthly',
                'play' => 'pro:monthly',
            ],
        ],
    ],

    'pricing' => [
        'currency' => 'USD',
        'commission' => ['mode' => 'absorb', 'rate' => 0.15],
    ],
],
```

| Key | Meaning |
|-----|---------|
| `tier_order` | Your tier ids, cheapest first. Required. The first entry is the **free floor**: what a billable holds when nobody pays, so no product may sell it. It is the only list of tiers that exist, and the order every cross-rail decision reads. |
| `tiers` | Display copy per tier id: `name`, `tagline`, `features`, `recommended`, and anything else you add. Copy is passed through `__()` per request, so author it as English source strings or translation keys (see [Translating tier copy](#translating-tier-copy)). `cycles` and `products` are reserved: they are derived and written over. |
| `products` | What you sell, keyed by [product key](#product-keys). |
| `pricing` | The base `currency` a screen falls back to, and the `commission` rule that [derives store prices](#prices). |

A product entry:

| Field | Meaning |
|-------|---------|
| `type` | `subscription`, `consumable`, `non_consumable` or `physical`. Only subscriptions are sold today; the other types validate, and nothing sells them yet. |
| `tier` | A `tier_order` id. Required for a subscription. |
| `cycle` | `monthly` or `annual`. Required for a subscription. |
| `credits` | Optional integer a one-off purchase grants. |
| `prices` | `channel => currency => amount in minor units`. See [Prices](#prices). |
| `refs` | Each rail's id for the product: `stripe_price`, `app_store`, `play`. See [Store Ids](#store-ids). |
| `trial_days` | Optional whole number of free days a subscription starts with, default `0` (no trial). `0`, or `2` to `730`: Stripe Checkout enforces a minimum of 48 hours, so `1` stops boot, as does anything above `730` (the longest trial Stripe accepts), a negative or non-integer value and any non-zero value on a product that is not a subscription. Only the web (Stripe) rail honours it, and it is the length offered, not a promise to everybody. See [Trials](#trials). |
| `sellable` | Boolean, default `true`. `false` keeps the product mapped for webhooks, reconciliation and entitlement reads (a grandfathered price still bills people). `billing/plans` lists it with `sellable: false` so a client can place what a subscriber holds; a client must offer only sellable products. It cannot be checked out or swapped to (422 `product_not_sellable`), and is left out of `billing:manifest` and `billing:doctor`. Anything but a boolean stops boot. |

### What boot refuses

- A leftover `plans`, `prices` or `store_products` key, even empty. The message names where its content moved: `tiers` and `tier_order`, `refs.stripe_price`, and `refs.app_store` and `refs.play`. There is no alias.
- An empty `tier_order`.
- A product with an unknown `type` or a `sellable` that is not a boolean, or a subscription with no tier, a tier outside `tier_order`, or a `cycle` that is not `monthly` or `annual`.
- A `trial_days` that is not a whole number of 0 or more, is `1`, is above `730` (Stripe's longest trial), or is non-zero on a product that is not a subscription.
- A `prices` entry that is not a known channel (`web`, `app_store`, `play`) mapping a three-letter currency code to a whole amount of 0 or more.
- A `refs.play` that is not exactly `<subscription_id>:<base_plan_id>`, or a `refs.app_store` containing `:`.
- A `pricing.commission.mode` other than `absorb` or `gross_up`, or a `rate` that is not a number of at least 0 and below 1.
- A product that sells the free floor.
- Two sellable subscriptions on the same tier and cycle, since the plans screen would list one offer twice. Keep one sellable and mark a retired price `sellable: false`.
- One Stripe price or one store id on two products. The message names both.
- One Play subscription whose base plans sell two different tiers. Play reports a base-plan change inside a subscription as a renewal of the same purchase, so the tier would move with nobody deciding.

Every other process stops at boot on these, artisan commands included, so a deploy fails before the web serves a broken catalogue. `billing:doctor` is the one exception: it logs the refusal, boots, and reports it as `catalogue.valid`.

> [!WARNING]
> An unmapped Stripe price or store id is a config gap, never a downgrade. A rail that cannot name the tier a paying subscription sells leaves the entitlement alone and logs a warning.

---

<a name="product-keys"></a>
## Product Keys

A product key is `<tier>_<cycle>`, for example `pro_monthly` or `business_annual`. Write one product per tier and cycle and put every rail's ref on it. The key is the same everywhere:

| Where | The key is |
|-------|------------|
| `POST billing/checkout`, `POST billing/swap` | the `product` field |
| `GET billing` | `product`, the catalogue key behind the stored rail id |
| Stripe | the price's `lookup_key`, and the product's env variable `CASHIER_PRICE_<KEY>` |
| RevenueCat | the package `lookup_key` in the `default` offering |

The price env variable is the key uppercased with every run of non-alphanumeric characters turned into `_`: `pro_monthly` reads `CASHIER_PRICE_PRO_MONTHLY`. `billing:manifest` prints the variable beside each Stripe price so an agent knows what to fill after creating it.

A reverse lookup (a Stripe price or a store id back to its product) is exact, and boot guarantees one product per ref. A stored id (`plan_product_id`) is tried as a Stripe price first and a store id second, by `GET billing` and `entitledProduct()` alike. An empty ref is no ref: an unset env variable writes an empty string, and a lookup honouring it would name the empty string as the price of a paid tier.

---

<a name="prices"></a>
## Prices

`prices` are display figures, never a charge: each rail charges what its own dashboard says. Amounts are integers in the currency's minor unit under ISO 4217 (`2900` is 29.00 USD, `3400` is 3400 JPY, `1500` is 1.500 KWD).

- Write the **web** price. Any currency you write there is a currency the product sells in.
- A store channel (`app_store`, `play`) derives each remaining currency from the **same currency's** web price under `pricing.commission`:
  - `absorb`: the store charges the web figure and you net less.
  - `gross_up`: the store charges `ceil(web / (1 - rate))`, so you net the web figure. With a `0.15` rate, 2900 becomes 3412.
- An explicit store price always wins over the derivation.
- A currency the web channel does not price is **absent** on a store channel. The package never converts between currencies: a rate frozen into config is wrong the day after it is written.

---

<a name="store-ids"></a>
## Store Ids

| Rail | Ref | Form |
|------|-----|------|
| Stripe | `refs.stripe_price` | The price id, `price_...`. Read from `CASHIER_PRICE_<KEY>`. |
| App Store | `refs.app_store` | The product id, for example `com.example.app.pro.monthly`. |
| Google Play | `refs.play` | `<subscription_id>:<base_plan_id>`, the whole id. |

A store id names one product, matched exactly. RevenueCat's `store_identifier` for a Play product is the same `<subscription_id>:<base_plan_id>` string.

When RevenueCat's subscriber read reports a Play purchase, the store rail resolves it in this order: the composed `<subscription_id>:<base_plan_id>`, then the raw id exactly (every App Store id), then, on Play only, the bare subscription id. The bare id names no base plan, so it resolves the **tier only**: `GET billing` then answers `product` and `cycle` as `null`. A catalogue keyed on the bare subscription id misses on every Android renewal, so write the composed id.

One Play subscription sells one tier. Give each tier its own Play subscription and one base plan per cycle.

---

<a name="reading-the-entitlement"></a>
## Reading the Entitlement

`HasEntitlement` adds read-only checks to the billable model that answer for every rail. Cashier's `subscribed()` answers for Stripe alone, so a store buyer would read as free.

```php
use FlutterSdk\MagicStarter\Traits\HasEntitlement;

class User extends Authenticatable
{
    use HasEntitlement;
}
```

Under team billing the trait belongs on the team model instead.

| Method | Answers |
|--------|---------|
| `entitled()` | Whether somebody is paying for a tier above the floor right now. Dunning statuses count. |
| `onTier('pro')` | Whether the held tier is exactly that one. A lapsed customer holds the floor. |
| `tierAtLeast('pro')` | Whether the held tier ranks at or above it in `tier_order`. A tier outside the ranking answers `false` on either side. |
| `onGracePeriod()` | Whether `plan_grace_period_ends_at` is still in the future. |
| `entitlementProvider()` | The `BillingProvider` behind the plan: `none`, `stripe`, `app_store`, `play_store` or `manual`. |
| `entitledProduct()` | The catalogue key the entitlement was bought as, or `null` when not entitled or the stored id matches no product. |

A finished or paused plan reads as the floor even if the tier is still stored, so a gate on `tierAtLeast()` cannot be passed by a lapsed customer.

---

<a name="endpoints"></a>
## Endpoints

**Middleware:** `auth:sanctum`. Reads are open to any member of the billable team; writes belong to its owner.

| Endpoint | Purpose |
|----------|---------|
| `GET {prefix}/billing` | The entitlement. |
| `GET {prefix}/billing/plans` | The catalogue, one row per tier. |
| `POST {prefix}/billing/checkout` | Start a Stripe Checkout session. |
| `POST {prefix}/billing/swap` | Move the card subscription to another product. |
| `POST {prefix}/billing/cancel` | Cancel at the end of the paid period. |
| `GET {prefix}/billing/portal`, `invoices`, `payment-method` | Stripe portal URL, invoice page, default card. |
| `GET {prefix}/billing/store-funded-team` | See [Refusals](#refusals). |
| `GET {prefix}/billing/usage` | Registered only while your app binds `Contracts\ReportsUsage`. |

### Checkout and swap

```json
{
  "product": "pro_monthly",
  "success_url": "https://app.example.com/billing/done",
  "cancel_url": "https://app.example.com/billing"
}
```

`swap` takes `product` alone. The key fixes the tier and the cycle together, so the customer is charged the figure the screen showed. A product this rail cannot sell answers 422 with `code: "product_not_sellable"`: an unknown key, a product that is not a subscription, the free floor, one with no `refs.stripe_price`, or one marked `sellable: false`.

A product with `trial_days` starts a trial for a caller who is eligible, and a checkout always collects a card. An ineligible caller is not refused: they buy at the full price. See [Trials](#trials).

### Plans

`data` is a list of tier rows in `tier_order` order, each carrying its `tiers` definition plus the derived `cycles` (the cycles a sellable product with a Stripe price sells it on) and `products`, every subscription product of the tier:

```json
{
  "data": [
    {
      "id": "pro",
      "name": "Pro",
      "cycles": ["monthly"],
      "products": [
        {
          "key": "pro_monthly",
          "type": "subscription",
          "tier": "pro",
          "cycle": "monthly",
          "sellable": true,
          "trial_days": 14,
          "store_ids": { "app_store": "com.example.pro.monthly", "play": "pro:monthly" },
          "prices": {
            "web": { "USD": { "amount_minor": 2900, "display": "29.00 USD" } }
          }
        },
        {
          "key": "pro_monthly_2025",
          "type": "subscription",
          "tier": "pro",
          "cycle": "monthly",
          "sellable": false,
          "trial_days": 0,
          "store_ids": { "app_store": "com.example.pro.monthly.2025", "play": "pro:monthly-2025" },
          "prices": {
            "web": { "USD": { "amount_minor": 1900, "display": "19.00 USD" } }
          }
        }
      ]
    }
  ]
}
```

A product with `sellable: false` is listed so a client can rank what a subscriber already holds; offer only the sellable ones. `store_ids` carries `null` for a store the product is not on, and a product with no web price carries `"web": {}`.

`trial_days` is the trial THIS caller would get, not the configured length: it is `0` for a caller who is not eligible (and for a caller with no billing subject), so a screen never offers "Free for 14 days" to somebody the checkout would charge on day one. While no product offers a trial, the endpoint makes no `billing_trials` query. See [Trials](#trials).

An application that has published nothing gets an empty list, not a 404.

<a name="translating-tier-copy"></a>
#### Translating tier copy

Copy is passed through `__()` per request, so author it as English source strings or translation keys. Every top-level string of a tier except `id`, and every string inside a list such as `features`, is translated into the request locale; a line with no translation comes back unchanged. An associative array such as `limits`, and any value that is not a string, is served as configured. A Turkish plan grid is then one entry per line in `lang/tr.json`:

```json
{
    "Kick the tires.": "Bir deneyin.",
    "Everything you need to try it": "Denemek için gereken her şey"
}
```

### The entitlement

`GET billing` answers `plan`, `plan_status`, `subscribed`, `renews`, `cycle`, `provider`, `provider_status`, `product_id` (the rail's own id), `product` (the catalogue key), `manage_via`, `manage_url`, `current_period_end`, `trial_ends_at`, `grace_period_ends_at`, and three collections that are never `null`: `owned` (always `[]` for now), `balances` (always `{}` for now) and `allowances` (your `ReportsUsage` answer, or `{}`). A `null` `plan` means the billable holds nothing; this package names no free tier.

---

<a name="refusals"></a>
## Refusals

Each refusal drives a different next step in the client, so they carry a machine-readable reason and the client never reads the sentence.

### Across rails and stores

| Status | Reason | Meaning |
|--------|--------|---------|
| 409 | `managed_by_store` | A store is billing the subject. `checkout`, `swap`, `cancel` and `portal` refuse, and `billing.provider` names `app_store` or `play_store`. Manage it where it was bought. |
| 409 | `subscription_exists` | The card rail already bills the subject, including a cancelled subscription still inside its paid period. A second checkout would bill twice. Use `swap`, which un-cancels. |
| 409 | `no_billing_account` | There is nothing to manage: no Stripe customer, or the billable has no Cashier trait. |
| 404 | none | `swap` or `cancel` with no card subscription (checked after the store guard, so it never claims there is nothing to cancel while a store charges), or no billing subject at all. |
| 403 | none | The caller is a member and not the owner. |
| 422 | `product_not_sellable` | See [Checkout and swap](#checkout-and-swap). |

Two store SKUs share a subscription group, and a store account holds one active subscription per group, so a second purchase from the same account moves the subscription instead of opening another. `GET billing/store-funded-team` returns `{"store_funded_team": {"id": ..., "name": ...}}` for another subject the caller owns that a store is already funding, or `null`, so a client can refuse a store purchase by name before it moves one.

At the entitlement write, a rail may only revoke what it granted. A cross-rail write that would leave the billable with less access is dropped and logged. A cross-rail upgrade lands and logs a warning, because two rails claiming one customer means somebody is paying twice.

### Identity

RevenueCat's `app_user_id` **is the billable's key**: the user's id under `'user'`, the team's under `'team'`. The client must log in to RevenueCat with it before purchasing. Deliveries whose identity cannot be resolved leave the entitlement untouched and log a warning, and still answer 200:

- an `app_user_id` that cannot be a billable key (an anonymous `$RCAnonymousID:...`), unless exactly one alias can;
- an id with no billable behind it;
- several aliases that could each be a billable, which the job cannot choose between.

A `TRANSFER` event re-reads both the `transferred_from` and the `transferred_to` side, since a subscription moved between two billables. Set the RevenueCat transfer behaviour to "Transfer if there are no active subscriptions". Sequential integer keys are guessable and collide between environments sharing a project, so `billing:doctor` warns and recommends `use_uuids`.

---

<a name="trials"></a>
## Trials

A product with `trial_days` of 2 or more starts a free trial on the **web rail** (Stripe Checkout through Cashier). Whether a given customer still gets it is decided per user and billable, never by the catalogue alone.

```php
'pro_monthly' => [
    'type' => 'subscription',
    'tier' => 'pro',
    'cycle' => 'monthly',
    'trial_days' => 14,
    // ...
],
```

### Who is eligible

`TrialEligibility::allows($user, $billable)` answers it, from the local database only (no Stripe call). The user is always the signed-in person, and the billable is the subject the trial would bill: the user itself, or their current team under team billing. It refuses:

| Refused | Why |
|---------|-----|
| A guest | A guest account costs one tap to make. Asked only where the application applied `HasGuestSupport`; without it there are no guests. |
| A user with any `billing_trials` row | One trial per person, so a person cannot trial on their account and again on every team they create. |
| A billable with any `billing_trials` row | One trial per subject, so a team one member trialed is not trialed again by the next member. |
| A billable holding a `default` Cashier subscription, in any status | A returning paid customer is not a new one, and a current subscriber would meet `subscription_exists` straight after a "Start free trial" button. |

A row the card check refused still counts, so a refusal never resets eligibility. While the `billing_trials` table is missing, nobody is eligible: the plans show `trial_days: 0`, checkout sells at the full price, and a warning names the table, instead of both answering 500. An application with its own rules (a domain allow-list, a sales-led exception) binds a subclass of `TrialEligibility` in the container.

### Checkout

`POST billing/checkout` starts the trial for an eligible caller with Cashier's `trialDays()` and tags the subscription with the metadata `magic_starter_trial_user`, the acting user's key. The tag is how the webhook learns the person, since under team billing the Stripe customer is the team. An ineligible caller is not refused: they buy at the full price, which is what `GET billing/plans` showed them.

Every checkout, trial or not, sends `payment_method_collection=always`. A trial's first invoice is zero, and a session that skipped the card would end the trial on a customer with nothing to charge. The card is also what the check below reads.

### The card check

1. The `customer.subscription.created` webhook records a `billing_trials` row for a `trialing` `default` subscription carrying the metadata tag, in the same transaction as the entitlement write, and queues `CheckTrialCard` after that transaction commits. A trial started anywhere else (the Stripe dashboard, another integration) carries no tag and is not this package's to police.
2. `CheckTrialCard` reads the card's fingerprint outside the webhook: the subscription's default payment method first, then the customer's invoice default. Checkout may not have attached the card yet, so the job asks again a minute later, for about six minutes (six attempts), and then keeps the trial; a trial recorded more than six hours ago is kept on its first attempt, which is what ends the wait on the `sync` queue. A payment method that is not a card, or a card Stripe reports without a fingerprint, also keeps the trial: there is nothing to compare.
3. Under a cache lock (`magic-starter:billing-trials`), the job walks every trial that shares a person, a billed subject or a card with this one, **earliest first**, and keeps the trial Stripe created first (its `created`, then the row key), never the job that happened to run first. A later trial whose local Cashier row is still `trialing` is refused, and cancelled in Stripe with no proration and no invoice only while Stripe's **live** status also says `trialing`. One that already converted to paying is kept, and a refusal whose cancel was still owed when it converted is withdrawn, because this check refuses trials and never customers. One Stripe has already ended is marked done without a cancel.
4. A refused trial on the same subject as the surviving one also loses its local Cashier row, so `subscription('default')` keeps answering the survivor, and the subject's entitlement is re-projected from the survivor's row: the refused trial's own `customer.subscription.created` had written its tier and product.

The lock is fleet-wide only on a shared cache store (`redis`, `memcached`, `database`); on `file` or `array` each server locks for itself.

The refusal reason is `card_reused` when the card is the only thing two trials share, and `duplicate` when they share a person or a subject. The refusal is written before the cancel, and a refused row with no `checked_at` is a cancel still owed that any later check of its set finishes. While it is owed, the webhook keeps applying the subscription's `customer.subscription.updated` events, so its local status stays true; once the refusal is finished they are skipped.

On the `sync` queue the job cannot release itself for a retry, so it never retries there. `billing:reconcile` covers that, a dispatch lost after the commit, and a cancel that failed on the job's last attempt: a full run re-dispatches the check of every trial still unchecked after 30 minutes, refused or not. It does so only while a product offers `trial_days` and the `billing_trials` table exists.

The sweep runs on `billing:reconcile`'s cadence (`magic-starter.billing.reconcile.cadence`, `daily` by default), not on a cadence of its own. While a product offers a trial, set `MAGIC_STARTER_BILLING_RECONCILE_CADENCE=hourly`, or a stranded check waits up to a day.

A check settles the trials one hop from its own row. A chain (A shares a card with B, B a person with C) is settled by the checks of the rows in it, each against its own neighbours, so C is compared with B and not with A. This is a known limitation.

### The refusal mail

`TrialRefusedNotification` is mailed only for `card_reused`: the person's card had already taken a trial, no trial was opened, nothing was charged, and they can still subscribe without one. A `duplicate` sends nothing, since the trial they meant to have is the surviving one. The mail goes out after the cancel is confirmed, so a mail failure never causes a second cancel.

| Config key | Env | Default |
|------------|-----|---------|
| `magic-starter.billing.trial_refused_notification` | `MAGIC_STARTER_TRIAL_REFUSED_NOTIFICATION` | `true` |

Set it `false` to send your own message instead. The sentences are `magic-starter::billing.trial_refused.*` in `en` and `tr`; a published `lang/vendor/magic-starter/` copy wins over the package's own.

### The table

`billing_trials` has one row per trialing subscription a package checkout opened, and is the anti-abuse record behind the rules above. A fresh `magic-starter:install --features=billing` publishes `create_billing_trials_table.php` with the other billing migrations; an existing application copies it in, see [Upgrading](#upgrading). Keys follow `use_uuids`. `billing:doctor` reports a table missing while a product offers a trial as `schema.billing_trials`.

| Column | Holds |
|--------|-------|
| `user_id` | The person who started the trial. Nullable foreign key to `users`, set to null when the user is deleted. |
| `billable_type`, `billable_id` | The subject, as `getMorphClass()` and its key. No foreign key, so deleting a team keeps the record that it trialed. |
| `stripe_subscription_id` | The Stripe subscription, unique. |
| `subscription_created_at` | Stripe's own `created`, the order "earliest wins" is decided on. |
| `card_fingerprint` | The card's fingerprint, indexed, null until read. |
| `checked_at` | Stamped once the check finished, whether or not it found a fingerprint. |
| `refused_at`, `refusal_reason` | Set on a refused trial: `card_reused` or `duplicate`. |

> [!WARNING]
> The card fingerprint of a refused person is retained after their account is deleted: the row keeps its fingerprint and only `user_id` becomes null, because the record has to survive a delete and sign-up again. Disclose it in your privacy policy as an anti-abuse record.

### Limits

- A wallet card (Apple Pay, Google Pay) can carry a different fingerprint than the same card entered plainly, so one card can reach a second trial through the wallet. This is an accepted limitation.
- Web and store trials are separate. `trial_days` reaches Stripe only. A store (intro offer) trial is configured in App Store Connect or Google Play Console, and is not recorded, checked or counted here, so a customer can take one on each rail.

---

<a name="revenuecat-webhook"></a>
## RevenueCat Webhook

The endpoint is `POST {app.url}/webhooks/revenuecat`. Change `REVENUECAT_WEBHOOK_PATH` only together with the dashboard, since a webhook URL cannot move with a deploy.

The endpoint verifies **HMAC signing** and nothing else. A static `Authorization` header, which is RevenueCat's baseline, is refused.

1. In the RevenueCat dashboard, open the webhook integration and turn on HMAC signing.
2. Copy the signing secret it shows. It is shown once.
3. Put it in `REVENUECAT_WEBHOOK_SECRET`.

RevenueCat then signs each delivery in `X-RevenueCat-Webhook-Signature: t=<unix>,v1=<hex>`, an HMAC-SHA256 over `"{t}.{raw body}"`. The signing time must be within five minutes of now. A signature that does not verify answers 403, and it is the only non-200 answer: an unknown billable, an ignored event type and an unreadable body all answer 200, because a retry cannot improve them.

A delivery is only a signal. The job it queues re-reads the subscriber from RevenueCat with `REVENUECAT_SECRET_API_KEY` and decides what is owed from that read. With the API key set and no webhook secret, the route is not registered, boot logs the reason once, and `magic-starter:install` refuses to complete. `SANDBOX` events are acted on only when `REVENUECAT_ACCEPT_SANDBOX` is `true`, which production never sets.

The `billing:reconcile` schedule heals a dropped delivery. Its cadence is `daily` by default; an application selling mostly through the stores should set `MAGIC_STARTER_BILLING_RECONCILE_CADENCE=hourly`, because RevenueCat abandons a delivery after about three hours.

<a name="sandbox-allowlist"></a>
### Sandbox allowlist

`REVENUECAT_ACCEPT_SANDBOX` is all or nothing, and production never sets it. App Review is the case it cannot serve: Apple tests with a sandbox purchase, and the reviewer's account has to reach the tier it bought. `REVENUECAT_SANDBOX_APP_USER_IDS` (`billing.revenuecat.sandbox_app_user_ids`) is the narrow form: a comma-separated list of **billable keys**, the user or team ids behind the review accounts (the same key as the RevenueCat `app_user_id`, see [Identity](#identity)).

```bash
REVENUECAT_ACCEPT_SANDBOX=false
REVENUECAT_SANDBOX_APP_USER_IDS=2f6c1e0a-9d44-4b7e-8a53-0c1d5e7f9a21
```

- The webhook accepts a `SANDBOX` delivery when any identity the event carries (`app_user_id`, `original_app_user_id`, `aliases`, `transferred_from`, `transferred_to`) is a listed key. That only lets the re-read happen.
- The job then counts sandbox subscriptions **per billable**, and only for a billable whose own key is listed. An event that named a listed key in an alias cannot carry that permission to the subscriber it resolved, and the reconciler, which runs the job once per billable, is bound by the same list.
- Everybody else still reads sandbox purchases as no evidence in either direction.
- With `REVENUECAT_ACCEPT_SANDBOX=true` the list is pointless. `billing:doctor` reports `revenuecat.sandbox_allowlist` as `ok` with the number of entries (the keys are never printed), as a `warning` when both are on, and says nothing while the list is empty.

A store record that holds sandbox purchases only is never revoked by the store job. The panel's [Revoke grant](#admin-panel) action ends such a record by hand.

---

<a name="agent-commands"></a>
## Agent Commands

Both commands only read. Neither prints a secret, only whether it is set.

### `billing:manifest`

```bash
php artisan billing:manifest --json
php artisan billing:manifest --json --section=play
```

Prints every vendor object the catalogue needs. `--section` is one of `app_store`, `play`, `revenuecat`, `stripe`, `env`. The JSON carries a `schema_version` and these sections:

| Section | Holds |
|---------|-------|
| `app_store` | One `subscription_group` and a product per subscription: `product_id`, `reference_name`, `period` (`ONE_MONTH`, `ONE_YEAR`), `group_level` (the highest tier is level 1) and `prices`. |
| `play` | A subscription per tier, `base_plans` per cycle with `billing_period` (`P1M`, `P1Y`) and `prices`. |
| `revenuecat` | `apps`, `products` (one per store id), an `entitlements` row per paid tier (lookup key is the tier id), the `default` offering with one package per product key, and the `webhook` URL with its HMAC instruction. |
| `stripe` | A product per tier and, per product key, a price whose `lookup_key` is that key, with `currency`, `unit_amount`, `currency_options`, `recurring.interval` and the `env_key` to fill. |
| `env` | Each rail variable as `present` or `absent`. |

Only subscriptions appear, and a product marked `sellable: false` is left out.

### `billing:doctor`

```bash
php artisan billing:doctor --json
php artisan billing:doctor --json --remote
```

Without `--remote` it checks the configuration: the catalogue validates, the rail secrets that a sold product needs are set, `REVENUECAT_WEBHOOK_SECRET` exists while the store rail is on, every web-sold product has a Stripe price id, store ids are listed, billable keys are UUIDs, the subscription tables are keyed the way their models write them (`schema.subscription_keys`), the `billing_trials` table exists while a product offers a trial (`schema.billing_trials`), the `billing_events` table exists (`schema.billing_events`), the `billing_grants` table exists (`schema.billing_grants`), the sandbox allowlist is consistent (`revenuecat.sandbox_allowlist`), and the reconcile cadence suits the store rail. With `--remote` it also reads Stripe and RevenueCat and diffs them against the manifest: a Stripe price per lookup key (id, interval, amounts), and the RevenueCat apps, products, entitlements, `default` offering, packages and webhook (that one delivers to this application's URL). RevenueCat returns a webhook's signing secret only when it is rotated, so whether HMAC signing is on cannot be read: the doctor reports it as an `agent_check` for a person to confirm in the dashboard.

`REVENUECAT_API_V2_KEY` is a separate secret key scoped to `project_configuration:{apps,products,entitlements,offerings,packages,integrations}:read`, and `REVENUECAT_PROJECT_ID` names the project. Neither is needed to sell.

The JSON is `{"schema_version": 1, "ok": true, "checks": [...]}`. Each check has a stable `id`, a `status` of `ok`, `warning`, `error` or `agent_check`, and a `message`. `agent_check` is vendor state this package cannot read (App Store Connect, Play Console) and names the command to run in `command`. Any `error` exits 1.

---

<a name="audit-log"></a>
## Audit Log

Every billing outcome leaves one row in `billing_events` and dispatches one Laravel event, so "why did this subscriber lose access" is answered after the webhook that did it is long gone. A new install publishes the migration with the other billing ones; an existing application copies it in, see [Upgrading](#upgrading).

| Column | Holds |
|--------|-------|
| `type` | What happened, one of the values below. Indexed. |
| `source` | Which path acted: `webhook` (a Stripe or RevenueCat delivery), `reconcile` (`billing:reconcile`), `request` (an authenticated API call), `trial_check` (the queued card check), `admin` (an operator in the [admin panel](#admin-panel), or the hourly grant expiry). |
| `provider` | The rail (`stripe`, `app_store`, `play_store`), or null. |
| `billable_type`, `billable_id` | The subject, as `getMorphClass()` and its key. Plain nullable strings with no foreign key: a refusal may have no billable, and a raw store id must fit. |
| `actor_user_id` | The signed-in user who caused it, or null for a rail-driven outcome. Nullable foreign key to `users`, set to null when the user is deleted. |
| `reason` | A stable snake_case rule name, never prose, or null. |
| `external_id` | The rail's own id: a Stripe event id, a RevenueCat event id (without the `rc:` prefix the dedup table uses), a Checkout session or Stripe subscription id. Null on a reconcile write. Indexed. |
| `properties` | JSON with whatever else the outcome needs, never a secret. |
| `created_at` | There is no `updated_at`. |

### What is recorded

| `type` | Source | `reason` | Properties |
|--------|--------|----------|------------|
| `entitlement_applied` | `webhook`, `reconcile`, `trial_check` | none | `before`, `after`, `changed`, `direction`, `cross_rail`. |
| `entitlement_dropped` | any feeder | `stale`, `same_instant_revocation`, `undecidable_tier_order`, `cross_rail_revocation`, `projected_cross_rail_takeover` | The log context and `incoming_status`. |
| `checkout_started` | `request` | none | `product`, `price_id`, `trial_days`. |
| `subscription_swapped` | `request` | none | `product`, `price_id`. |
| `subscription_cancelled` | `request` | none | `ends_at`. |
| `portal_opened` | `request` | none | none. |
| `request_refused` | `request` | `managed_by_store`, `no_billing_account`, `subscription_exists` | none. The 409s of [Refusals](#refusals). |
| `delivery_refused` | `webhook` | See below. | Per reason. |
| `trial_recorded` | `webhook` | none | `stripe_subscription_id`, `trial_ends_at`. |
| `trial_refused` | `trial_check` | `card_reused`, `duplicate` | `billing_trial_id`, `user_id`. |
| `trial_cancelled` | `trial_check` | none | `billing_trial_id`, `user_id`, `cancelled_by` (`this_check` when this run cancelled it, `already_ended` when Stripe had already ended it). |
| `trial_refusal_withdrawn` | `trial_check` | `refused_trial_converted` | `stripe_status`. |
| `grant_added` | `admin` | none | `grant_id`, `plan`, `reason`, `expires_at`. |
| `grant_revoked` | `admin` | none | `grant_id`, `plan`, `reason`; a store record revoked by hand carries `reason` and `store_sandbox_only` instead. |
| `grant_expired` | `admin` | none | `grant_id`, `plan`, `expires_at`, `entitlement_written`. No actor: the expiry command wrote it. |
| `trial_extended` | `admin` | none | `until`. |
| `trial_ended` | `admin` | none | `note` (`stripe_bills_now`). |
| `subscription_resumed` | `admin` | none | `stripe_status`. |
| `invoice_refunded` | `admin` | none | `invoice_id`, `amount`, `currency`, `reason`. One row per refund id. |
| `entitlement_synced` | `admin` | none | `rail` (`stripe` or `store`), `changed`. |
| `request_refused` | `admin` | The refusal keys of the [admin panel](#admin-panel). | `operation`, and `rail_message` on a `rail_error`. |

An operator's cancel is a `subscription_cancelled` row with source `admin`, and every entitlement write an admin action makes is an `entitlement_applied` or `entitlement_dropped` row with source `admin`.

An apply writes `entitlement_applied` only when what the entitlement **means** changed: `plan`, `plan_status`, `plan_provider`, `plan_current_period_end` or `plan_renews`. `changed` names those fields and `before` and `after` carry the five values. An apply that only refreshed provenance (a renewal, a reconcile read) writes no row, since that would be one row per delivery that says nothing. `direction` is `upgrade`, `same`, `downgrade`, `unknown`, `no-order` or `nothing-stored`, and `cross_rail` is true when a rail holding the record handed it to another.

`delivery_refused` is a verified delivery the package decided not to act on:

- Stripe: `unmapped_price` (a granting price with no tier mapping) and `revocation_skipped` (a deleted subscription while another still grants).
- RevenueCat endpoint: `unreadable_event`, and `non_production_environment` for an event type that can change an entitlement (a sandbox `PAYWALL_IMPRESSION` is logged and leaves no row).
- RevenueCat job, on the webhook source: `malformed_app_user_id` (for the event's own subscriber, never for a transfer side), `unknown_billable`, `ambiguous_aliases`, `sandbox_only_subscriber`, `undated_subscriptions`, `unmapped_product` and `nothing_to_revoke`. `released_burnt_event_id` is also recorded when the job gives up after its last attempt and releases the dedup claim. Each is one row per delivery and reason, de-duplicated across retries: a refusal first reached on a retry (the read failed on attempt 1) is still recorded, and a later retry does not write it again.

`trial_cancelled` is written once per subscription: a re-run that finds Stripe already ended it does not write a second one.

**Deliberately not recorded:** Stripe and RevenueCat signature failures (unauthenticated input must not write rows), the reconciler's per-run skips, Stripe's silent skips (a duplicate delivery, no billable, a non-default subscription type), a 422 or a 404 (the caller's own mistake, or an honest absence), the RevenueCat `family_shared_entitlement` (the tier was granted) and `unfed_store` (another rail's subscription), and a RevenueCat job refusal on the reconcile source or a second time for the same delivery.

### Append-only, and recording never breaks billing

A row is written once. `BillingEvent` throws a `LogicException` on `update()` and `delete()`, and the only code that removes rows is the prune command, which deletes by age through the query builder.

Recording sits inside the Stripe webhook's own transaction, so the insert runs in a savepoint and only a `QueryException` is caught: it is logged at error level and the entitlement write goes on. A missing table logs one warning per process and skips the row. In both cases the event is still dispatched, with an unsaved model.

`billing_events` survives deletion: deleting a user clears `actor_user_id`, and the billable has no foreign key, so deleting a user or team keeps its rows.

### Listening

Each outcome is one class in `FlutterSdk\MagicStarter\Events\Billing` (`EntitlementApplied`, `EntitlementDropped`, `CheckoutStarted`, `SubscriptionSwapped`, `SubscriptionCancelled`, `PortalOpened`, `RequestRefused`, `DeliveryRefused`, `TrialRecorded`, `TrialRefused`, `TrialCancelled`, `TrialRefusalWithdrawn`, `GrantAdded`, `GrantRevoked`, `GrantExpired`, `TrialExtended`, `TrialEnded`, `SubscriptionResumed`, `InvoiceRefunded`, `EntitlementSynced`), and all of them implement `BillingOutcome`. They are dispatched after the surrounding transaction commits, so an outcome that rolled back never fires. The package registers no listener of its own.

A synchronous listener runs in the billing path, inside the webhook, request or job that produced the outcome. A listener that throws is reported through your exception handler and never propagated, so the delivery still answers 200 and the request still answers what it would have, but that listener's work is lost. Prefer a `ShouldQueue` listener for anything that can fail or take time.

```php
use FlutterSdk\MagicStarter\Events\Billing\BillingOutcome;
use Illuminate\Support\Facades\Event;

Event::listen(BillingOutcome::class, function (BillingOutcome $event): void {
    $record = $event->record();

    if ($record->type->isRefusal()) {
        // alert, count, notify...
    }
});
```

`record()` is the `BillingEvent`, unsaved (`exists` false) when the row could not be written, so read its attributes and not its key. `type` and `source` cast to the `BillingEventType` and `BillingSource` enums, and `provider` to `BillingProvider`. Listen to one concrete class for a single outcome.

### Logs and retention

Billing log lines (webhook outcomes, reconciler runs, drops, refusals) go to the channel named by `magic-starter.billing.log_channel`, and successes are logged at info level. Null or blank, the default, uses the application's default channel. Boot-time messages and the trial eligibility warning keep using the default channel.

| Config key | Env | Default |
|------------|-----|---------|
| `magic-starter.billing.log_channel` | `MAGIC_STARTER_BILLING_LOG_CHANNEL` | `null` |
| `magic-starter.billing.webhook_retention_days` | `MAGIC_STARTER_BILLING_WEBHOOK_RETENTION_DAYS` | `90` |
| `magic-starter.billing.events_retention_days` | `MAGIC_STARTER_BILLING_EVENTS_RETENTION_DAYS` | `null` (keep forever) |

`php artisan magic-starter:billing:prune` deletes `processed_webhook_events` rows older than `webhook_retention_days`, and `billing_events` rows older than `events_retention_days` when it is a number of days. A blank or non-numeric value keeps every row and says so in one line. It runs daily while the billing feature is on. The webhook retention is never taken below 31 days: a dedup claim is the only thing that stops a resent event from running twice, and Stripe's CLI can resend an event up to 30 days old. The history is financial, so keep `events_retention_days` null unless your retention policy demands a number.

`billing:doctor` reports a missing `billing_events` table as an `error` with the id `schema.billing_events`.

A refused request still leaves a `request_refused` row, so put a throttle on the billing routes: name it in `magic-starter.route_middleware` (which wraps every package API route) or in the route group your application loads them from.

---

<a name="admin-panel"></a>
## Admin Panel

With the [admin panel](admin-panel.md) mounted, a **Billing** tab appears on the billable's edit page: the user's under `billing.billable` `'user'`, the team's under `'team'`. It shows what the billable holds (plan, status, provider, period end, the open grant and its expiry, the Stripe trial end), its `billing_events` history, and eight actions. Every action goes through `Contracts\AdministersBilling`, which writes the entitlement through `WritesEntitlement` and leaves a `billing_events` row with source `admin`; nothing in the tab calls Stripe, RevenueCat or the entitlement writer directly.

An action is shown only to an admin [billing authorization](#billing-authorization) allows and only in the state it applies to. The contract re-checks the state and refuses on its own, so a crafted request meets the same answer.

| Action | Shown when | Refused with |
|--------|-----------|--------------|
| Grant plan | No paid rail grants. | `paid_rail_active`, `unknown_plan`, `expiry_in_past`, `entitlement_refused` |
| Revoke grant | An open manual grant is on record, or a store record. | `not_manual`, `rail_error`, `entitlement_refused` |
| Extend trial | The Stripe subscription is trialing. | `no_subscription`, `not_trialing`, `date_in_past`, `rail_error` |
| End trial | The Stripe subscription is trialing. | `no_subscription`, `not_trialing`, `rail_error` |
| Cancel subscription | The subscription runs and is not cancelled. | `no_subscription`, `already_cancelled`, `rail_error` |
| Resume subscription | Cancelled and still inside its paid period. | `no_subscription`, `not_on_grace_period`, `rail_error` |
| Refund last invoice | A Stripe customer with a local subscription. | `no_subscription`, `invalid_reason`, `nothing_refundable`, `rail_error` |
| Sync now | A record any rail or operator wrote, or a local subscription. | `nothing_to_sync`, `unmapped_price`, `rail_error` |

The sentences are `magic-starter::admin_billing.refusals.*` in `en` and `tr`. A refusal is recorded as a `request_refused` row (source `admin`, the key as `reason`) **before** it is thrown, outside any transaction the action opened, and the panel halts the action without rolling its own transaction back, so the row survives. `rail_error` keeps the rail's own message on the row.

### Manual grants

A grant gives the billable a plan with no payment behind it, until an optional expiry or a revoke. The plan is a tier of `tier_order`, the expiry must be in the future, and the reason is kept on the grant.

- A grant is refused while a paid rail grants: the record names Stripe or a store on a granting status, or the local Cashier `default` subscription grants and has not ended. The check runs again under a row lock inside the write, because a checkout can land in between, and the grant is written as a projection that yields to anybody paying.
- There is **one open grant per billable**. A new grant closes the previous one as `superseded`. The grant's row id travels in `plan_product_id` as `grant:{id}`, which is how a revoke or an expiry knows the billable is still on it.
- Revoke ends only a **manual grant**, or a **store record** whose RevenueCat subscriber holds no production subscription (sandbox purchases only), which the store job never revokes on its own. Anything else is `not_manual`: a paid rail's record is cancelled through that rail.
- `magic-starter:billing:expire-grants` runs **hourly** while billing is on (`withoutOverlapping()` and `onOneServer()`, so the application's scheduler must run). It visits every open grant: an expired one the billable is still on is revoked and closed as `expired`, one the billable has moved off (a paid rail or a newer grant took the record) is closed as `superseded` with no write. A table not migrated yet is skipped with a line.
- After a grant ends, by revoke or expiry, the paid rails are **re-projected**: the Stripe `default` subscription through the reconciler, and the store through the authoritative RevenueCat re-read where the rail is configured. A checkout made during the comp was dropped while the comp held the record, and the reconciler never walks a manual record to find it.

`billing_grants` keeps one row per grant (`plan`, `reason`, `expires_at`, `granted_by`, `ended_at`, `end_reason` of `expired`, `revoked` or `superseded`) and outlives the operator and the billable: neither has a cascading foreign key. A fresh `magic-starter:install --features=billing` publishes `create_billing_grants_table.php`; an existing application copies it in, see [Upgrading](#upgrading). `billing:doctor` reports it missing as `schema.billing_grants`.

### Stripe operations

Trial, cancel, resume and refund act on the billable's **local `default` Cashier subscription**, whatever `plan_provider` says: a checkout over a comp leaves the record naming somebody else while Cashier bills the card. Without one they refuse `no_subscription`. They change Stripe and Cashier's row and leave the entitlement to the webhook that follows, as the customer's own billing endpoints do. A Stripe or Cashier failure is a recorded `rail_error`.

- **Extend trial and End trial** work only while the subscription is trialing. Extend moves the trial end to a future date. End trial ends the trial now, so Stripe **bills the customer immediately**.
- **Resume** sends `cancel_at_period_end=false` and nothing else. It is deliberately not Cashier's `resume()`, which off trial also sends `trial_end: now` and can invoice the customer and move their billing date when all the operator asked for was to lift a cancellation.
- **Refund** returns the **newest invoice with `amount_paid` above zero** whose payment is a paid payment intent, **in full**, with the reason `requested_by_customer` or `duplicate` (`fraudulent` is not offered: it feeds Stripe's fraud signals and is not a support decision). That invoice or nothing: if its payment was recorded out of band, an older invoice is never refunded in its place (`nothing_refundable`). The refund is created under the idempotency key `admin-refund:{invoice}`, so a second click or a retry after a timeout is answered with the first refund instead of refunding twice, and the `invoice_refunded` row is written once per refund id. The subscription is left as it is. The modal reads Stripe once when it opens to name the amount and the invoice.
- **Sync now** reads the rail **live**: for Stripe, the subscription from the API, which heals the local Cashier row (status, price, quantity, trial end, end date) and then writes the claim it makes as an **authoritative** write, like a webhook, so Stripe may take the record over from a comp or a store. A granting subscription on a price the catalogue does not map is refused `unmapped_price` and nothing is written. For a store record it runs the store job's authoritative re-read under the operator. Each rail leaves an `entitlement_synced` row saying whether the entitlement changed.

<a name="billing-authorization"></a>
### Billing authorization

Billing actions are narrower than panel access: the panel gate has already admitted the admin, and billing authorization only takes actions away.

- `MAGIC_STARTER_ADMIN_BILLING_EMAILS` (`admin.billing_emails`), a comma-separated list. Empty, the default, lets every panel admin run the actions. Otherwise only the listed addresses do, compared trimmed and lowercased.
- `MagicStarterPlugin::authorizeBillingUsing(?Closure $callback)` replaces the list entirely. The callback receives the panel user and the panel, and only a literal `true` allows.

```php
MagicStarterPlugin::make()
    ->authorizeBillingUsing(fn (Authenticatable $admin): bool => $admin->can('manage-billing'));
```

A hidden action cannot be mounted or called, so the check also holds against a crafted Livewire request. Reading the tab is not restricted by it.

### Billing events and webhook deliveries

The plugin registers two read-only resources while billing is on, under the keys `billing_events` and `webhook_deliveries`, which `MagicStarterPlugin::resource()` can replace like any other.

- **Billing events** lists `billing_events` with its filters (type, source, provider, date range) and a view page with the properties. A row is a reading and is never edited or deleted from the panel; retention is the [prune command](#logs-and-retention)'s job.
- **Webhook deliveries** lists the `processed_webhook_events` claims. The table has no provider column, so RevenueCat is derived from the claim prefix and everything else is Stripe. The view page lists the billing events recorded under the **delivery id**. That join has limits: a delivery that changed nothing has no rows, and rows keyed on a Checkout session or a Stripe subscription id (`checkout_started`, `trial_recorded`) never join.

---

<a name="account-deletion"></a>
## Account Deletion

A user who owns a team that a rail is still billing, or who is billed directly under user billing, is refused until the subscription ends. The 422 body names the rail per team in `team_providers`. See [Account Deletion](account-deletion.md#refusals).

---

<a name="upgrading"></a>
## Upgrading

The catalogue replaces `plans`, `prices` and `store_products`, and checkout and swap take `product` instead of `plan` and `cycle`. The [changelog](../../CHANGELOG.md) carries the migration steps.

An application that installed before trials existed and wants to sell one copies `vendor/fluttersdk/magic-starter-laravel/database/migrations/create_billing_trials_table.php` into `database/migrations/` under a timestamp later than your latest migration and runs `php artisan migrate`, before it sets a `trial_days`. Do not re-run `magic-starter:install` for this. An application that sets no `trial_days` never reads the table and needs neither.

The audit log needs two migrations. Copy `create_billing_events_table.php` and `add_processed_at_index_to_processed_webhook_events_table.php` from `vendor/fluttersdk/magic-starter-laravel/database/migrations/` into `database/migrations/` under timestamps later than your latest migration (or re-run the install command's billing publish) and run `php artisan migrate`. Until you do, billing keeps working and each worker logs one warning that `billing_events` is missing; `billing:doctor` reports it as `schema.billing_events`.

The admin panel's manual grants need one more migration. Copy `create_billing_grants_table.php` from `vendor/fluttersdk/magic-starter-laravel/database/migrations/` into `database/migrations/` under a timestamp later than your latest migration (or re-run the install command's billing publish) and run `php artisan migrate`. Until you do, a grant cannot be recorded and `magic-starter:billing:expire-grants` skips with a line; `billing:doctor` reports it as `schema.billing_grants`. The command is scheduled hourly by the package, so your scheduler has to be running.

The operator side is overridable: `Contracts\AdministersBilling` is bound to `Actions\AdministerBilling` unconditionally, so an application binds its own class over the contract to change what the panel's billing actions do. The expiry command resolves `Actions\AdministerBilling` itself (settling a grant is the sweep's step, not an operator's act, and is not on the contract), so bind a subclass over that class as well when the sweep must use yours.

`Filament\Support\ContractAction` now catches `BillingAdministrationRefused`: it shows the refusal as a danger notification and halts the action **without** rolling back the surrounding transaction, since the `request_refused` row is the only record of the refusal. A custom panel action that runs an `AdministersBilling` call through `ContractAction::run()` gets the notification instead of an exception.

Code that builds on the package internals changes in these places:

- `EntitlementWrite` now requires `source:` (a `BillingSource`), and `eventId:` is optional. An adopter-built `EntitlementWrite` without `source:` throws an `ArgumentCountError`.
- A custom `WritesEntitlement` records through `BillingEventRecorder` itself, or the writes it makes leave no row.
- A subclass of `StripeWebhookController` that overrides `subscriptionClaim`, `revokeEntitlement`, `reaffirmEntitlementFromInvoice` or `warnUnmappedPrice` adds the `string $eventId` parameter those methods gained. Its constructor, like `BillingController`'s, now takes a `BillingEventRecorder`.
- `CheckTrialCard::handle` takes a `BillingEventRecorder` parameter, and `ReconcileBillingEntitlements::reconcileStripeSubject` takes an optional `BillingSource`.
- `BillingController::abortWithBillingConflict()` takes `Model $billable` first; `StripeWebhookController::existingUserKey()` is now `existingUser()` and returns `?Model`; a `WriteEntitlement` subclass with its own constructor calls `parent::__construct()` so the recorder is set.
