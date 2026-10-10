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

Without `--remote` it checks the configuration: the catalogue validates, the rail secrets that a sold product needs are set, `REVENUECAT_WEBHOOK_SECRET` exists while the store rail is on, every web-sold product has a Stripe price id, store ids are listed, billable keys are UUIDs, the subscription tables are keyed the way their models write them (`schema.subscription_keys`), the `billing_trials` table exists while a product offers a trial (`schema.billing_trials`), and the reconcile cadence suits the store rail. With `--remote` it also reads Stripe and RevenueCat and diffs them against the manifest: a Stripe price per lookup key (id, interval, amounts), and the RevenueCat apps, products, entitlements, `default` offering, packages and webhook (that one delivers to this application's URL). RevenueCat returns a webhook's signing secret only when it is rotated, so whether HMAC signing is on cannot be read: the doctor reports it as an `agent_check` for a person to confirm in the dashboard.

`REVENUECAT_API_V2_KEY` is a separate secret key scoped to `project_configuration:{apps,products,entitlements,offerings,packages,integrations}:read`, and `REVENUECAT_PROJECT_ID` names the project. Neither is needed to sell.

The JSON is `{"schema_version": 1, "ok": true, "checks": [...]}`. Each check has a stable `id`, a `status` of `ok`, `warning`, `error` or `agent_check`, and a `message`. `agent_check` is vendor state this package cannot read (App Store Connect, Play Console) and names the command to run in `command`. Any `error` exits 1.

---

<a name="account-deletion"></a>
## Account Deletion

A user who owns a team that a rail is still billing, or who is billed directly under user billing, is refused until the subscription ends. The 422 body names the rail per team in `team_providers`. See [Account Deletion](account-deletion.md#refusals).

---

<a name="upgrading"></a>
## Upgrading

The catalogue replaces `plans`, `prices` and `store_products`, and checkout and swap take `product` instead of `plan` and `cycle`. The [changelog](../../CHANGELOG.md) carries the migration steps.

An application that installed before trials existed and wants to sell one copies `vendor/fluttersdk/magic-starter-laravel/database/migrations/create_billing_trials_table.php` into `database/migrations/` under a timestamp later than your latest migration and runs `php artisan migrate`, before it sets a `trial_days`. Do not re-run `magic-starter:install` for this. An application that sets no `trial_days` never reads the table and needs neither.
