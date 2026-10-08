# Billing Setup (agent reference)

Apply the billing catalogue to the vendors with their own CLIs. The package describes what each vendor must hold and checks that it does; it never writes to one.

## Where to Find It

- Catalogue: `config/magic-starter.php`, `billing` block (`tier_order`, `tiers`, `products`, `pricing`)
- Manifest: `src/Support/BillingManifest.php`, `src/Console/BillingManifestCommand.php`
- Doctor: `src/Console/BillingDoctorCommand.php`
- Docs for people: `doc/basics/billing.md`

## Rules

- **Writes only on the owner's request.** Every command below that creates or changes something at a vendor (`asc`, `gplay`, `rc`, `stripe`) runs only when the owner asked for it in this session. Reading (`billing:manifest`, `billing:doctor`, `--help`, `list`, `show`, `rc schema`) needs no request. Preview a Stripe write with `--dry-run` first.
- Never print, log or commit a secret. The manifest and the doctor report a variable as `present` or `absent`; keep it that way.
- Stripe defaults to test mode. Use `--live` only when the owner names live mode.
- Stop at every `requires_human` step below and hand it to the owner with the exact thing to do.
- Re-run `billing:manifest` after a catalogue change and apply only the difference. Create is not idempotent at most vendors, so list first.

## Steps

### 0. Read the manifest

```bash
php artisan billing:manifest --json
php artisan billing:doctor --json
```

The manifest has one section per vendor (`app_store`, `play`, `revenuecat`, `stripe`, `env`). Take every identifier from it, never from memory. Only subscriptions appear, and a product with `sellable: false` is left out.

### 1. App Store Connect (`asc`)

`requires_human`: the Paid Applications Agreement, banking and tax forms. Without them nothing below can go on sale.

Find or create the subscription group named by `app_store.subscription_group.reference_name`:

```bash
asc subscriptions groups list --app APP_ID
asc subscriptions groups create --app APP_ID --reference-name "My App"
```

Then one `setup` per entry of `app_store.products`. Map `product_id`, `reference_name` and `period` from the entry, and the price from its `prices` (the manifest's `price_point_rule` is "nearest at or above target", so Apple's nearest price point may differ slightly):

```bash
asc subscriptions setup --app APP_ID --group-id GROUP_ID \
  --reference-name "Pro Monthly" --product-id com.example.app.pro.monthly \
  --subscription-period ONE_MONTH --price 29.00 --price-territory "United States"
```

The first product of a new group may pass `--group-reference-name NAME` instead of `--group-id`. `--subscription-period` is `ONE_MONTH` or `ONE_YEAR`. Without a review screenshot and localizations Apple reports `MISSING_METADATA`; add `--review-screenshot` and the localization flags, or pass `--no-verify` and finish with `asc validate subscriptions`.

Set the ordering inside the group from `group_level` (level 1 is the highest tier):

```bash
asc subscriptions update --id SUB_ID --group-level 1
```

### 2. Google Play (`gplay`)

`requires_human`: the Play payments profile, and the first app upload that makes subscriptions available.

One subscription per entry of `play.subscriptions`, with a base plan per `base_plans` entry (`base_plan_id`, `billing_period` `P1M` or `P1Y`). Build the JSON from the manifest:

```bash
gplay subscriptions create --package com.example.app --product-id pro \
  --json @pro.json
```

Regional prices need a regions version. Either pass `--regions-version` from `gplay pricing regions-version --package com.example.app --price-json '{"currencyCode":"USD","units":"29","nanos":0}'`, or let Google convert one base price:

```bash
gplay subscriptions create --package com.example.app --product-id pro \
  --json @pro.json --auto-convert-regional-prices \
  --base-price-json '{"currencyCode":"USD","units":"29","nanos":0}'
```

Auto-convert replaces any `regionalConfigs` in the JSON with Google's own conversion, so an explicit store price in the catalogue is not honoured by it. Use `--regions-version` with explicit `regionalConfigs` when the figures matter. Then activate each base plan:

```bash
gplay baseplans activate --package com.example.app --product-id pro --base-plan-id monthly
```

The ref in the catalogue is `pro:monthly`, the subscription id and the base plan id joined by a colon.

### 3. RevenueCat (`rc`)

`requires_human`: `rc setup apple` and `rc setup google` (store credential uploads), the App Store Server Notifications V2 URL RevenueCat shows (paste it into App Store Connect), and the Play real-time developer notifications Pub/Sub topic. These move secrets between dashboards.

Discover the exact flags first, since they change between releases:

```bash
rc commands --schemas --json
rc schema products create --json
```

Use `--no-input` and, when more than one project exists, `--project-id`. Then, in this order:

1. **Apps.** One per `revenuecat.apps` entry (`app_store`, `play_store`): `rc apps create --type app_store --name "My App" --bundle-id com.example.app`, and `--type play_store --package-name com.example.app`. List first with `rc apps list`.
2. **Products.** One per `revenuecat.products` entry, with `store_identifier` as the store id exactly (for Play, `subscription:basePlan`): `rc products create --store-id com.example.app.pro.monthly --type subscription --app-id APP_ID --title "Pro Monthly"`.
3. **Entitlements.** One per `revenuecat.entitlements` entry; the `lookup_key` is the tier id: `rc entitlements create --lookup-key pro --display-name "Pro"`, then `rc entitlements attach pro PRODUCT_ID [PRODUCT_ID...]` with the RevenueCat product ids of that entry's `products`.
4. **Offering.** `rc offerings create --lookup-key default --display-name "Default"`, then `rc offerings set-current OFFERING_ID --yes` with the id it returned.
5. **Packages.** One per `revenuecat.offering.packages` entry; the `lookup_key` is the product key and must match it exactly: `rc packages create OFFERING_ID --lookup-key pro_monthly --display-name "Pro Monthly"`, then `rc packages attach PACKAGE_ID PRODUCT_ID [PRODUCT_ID...]` (the iOS and the Android product together).
6. **Webhook.** `rc webhooks create --name "Backend" --url <revenuecat.webhook.url>`.

`requires_human`: **the HMAC toggle.** Open the webhook in the RevenueCat dashboard and turn on HMAC signing. The signing secret is shown once; the owner puts it into `REVENUECAT_WEBHOOK_SECRET`. The endpoint refuses a static `Authorization` header. Never ask for the secret in chat; the owner sets the variable.

Also for the owner: the RevenueCat transfer behaviour should be "Transfer if there are no active subscriptions", and `REVENUECAT_SECRET_API_KEY` (v1 secret key) and, for the doctor, `REVENUECAT_API_V2_KEY` (read scopes on apps, products, entitlements, offerings, packages and integrations) and `REVENUECAT_PROJECT_ID` are theirs to create.

### 4. Stripe (`stripe`)

One product per `stripe.products` entry, then one price per `prices` entry. The price's lookup key is the product key, so it can be found again without an id:

```bash
stripe products create --name "Pro" --dry-run
stripe products create --name "Pro"

stripe prices create --product prod_X --currency usd --unit-amount 2900 \
  --lookup-key pro_monthly \
  -d "currency_options[try][unit_amount]=49900" \
  -d "recurring[interval]=month"
```

Take `currency`, `unit_amount`, `currency_options` and `recurring.interval` from the manifest, never recomputed. Run `stripe prices list --lookup-keys pro_monthly` first: a lookup key already in use is refused unless you pass `--transfer-lookup-key`, which moves it from the old price and is a decision for the owner.

Then ask the owner to set each price id in the env variable the manifest names as `env_key` (`CASHIER_PRICE_<KEY uppercased>`, for example `CASHIER_PRICE_PRO_MONTHLY`). The Stripe webhook endpoint (`<app.url>/stripe/webhook`, Cashier's own path) and its signing secret in `STRIPE_WEBHOOK_SECRET` are the owner's to create and set.

### 5. Verify

```bash
php artisan billing:doctor --json --remote
```

`ok: true` and exit 0 mean no `error`. Fix each `error` by its check id, which is stable (`stripe.remote.price.pro_monthly`, `revenuecat.package.pro_monthly`, `revenuecat.webhook`). Re-run until it passes. An `agent_check` is vendor state the package cannot read: run its `command` yourself and compare the output with the manifest section it names (`asc subscriptions groups list --app <app-id>`, `gplay subscriptions list --package <package-name>`). Report the remaining `warning` entries to the owner.

## What to Watch For

- The product key is `<tier>_<cycle>` and is the Stripe lookup key and the RevenueCat package lookup key. Do not invent another.
- A Play id in a ref is always `<subscription_id>:<base_plan_id>`, and one Play subscription sells one tier.
- Amounts in the manifest are minor units; `display` is the human figure.
- The free floor (the first `tier_order` entry) has no product, entitlement or price anywhere.
- Writes are never part of verifying: the doctor and the manifest only read.
