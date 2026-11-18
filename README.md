# PixelForge Studio (PromptEdit assessment)

A small Laravel 12 + Vue 3 (Inertia) app where users generate images through the **Pixelforge** API and are charged **exactly** what each generation cost (+25%). Built for the PromptEdit Senior Product Engineer take-home.

* Live: https://promptedit.51-255-44-145.sslip.io
* Stack: Laravel 12, Breeze (Vue 3 + Inertia), Tailwind, PostgreSQL, database queue, Laravel scheduler, Pest.

## What it does

| Area | Behaviour |
|---|---|
| Auth | Breeze registration / login. |
| Generate | Prompt (≤ 4,000 chars), size, quality, up to 4 reference image URLs. A **live min–max price** is shown before submitting, with an explanation of why it is a range. |
| Pricing | Pixelforge cost × 1.25. Estimate up front, real cost recorded when the generation finishes, failed/rejected generations cost **$0**. |
| History | Live status (queued → processing → succeeded / failed), estimated and actual cost, the image, and a plain-language reason when something failed. Polls with Inertia partial reloads every 2.5 s while anything is active (no websockets). |
| Webhooks | `POST /webhooks/pixelforge`: HMAC signature check, deduplication, safe against duplicate / out-of-order / early events. |
| Credits (stretch) | Per-user balance, simulated “Buy credits”, generation blocked when the user can't afford the *maximum* estimate. Reserve max → charge real → release the rest, all through a ledger. |
| Admin report (stretch) | `/admin/report` (users with `is_admin`): per day and per user, charged vs Pixelforge cost vs profit, plus a live reconciliation against `GET /v1/usage`, green/red per UTC day. |

## Run it locally

Requirements: PHP 8.3+, Composer, Node 20+, PostgreSQL.

```bash
composer install
npm install && npm run build           # or `npm run dev`
cp .env.example .env && php artisan key:generate
# create a database, put credentials in .env, then:
php artisan migrate
# .env: PIXELFORGE_API_KEY=pf_...  PIXELFORGE_WEBHOOK_SECRET=whsec_...
php artisan queue:work                 # in one terminal
php artisan schedule:work              # in another (polling fallback)
php artisan serve
```

Webhooks need a public URL (Expose / ngrok / Cloudflare Tunnel): set the key's webhook URL to `https://<host>/webhooks/pixelforge` in the Pixelforge dashboard. Without it the app still works: the scheduled poller picks up results after ~2 minutes.

Tests (use PostgreSQL, because correctness relies on row locks and unique indexes; create `promptedit_test` and an `.env.testing` with its credentials):

```bash
vendor/bin/pest
```

Admin user + the demo generations used to verify production:

```bash
php artisan demo:generations --email=you@example.test --password=secret   # creates admin + credits, submits 9 generations via GenerationService
php artisan demo:status
```

Deployment to an Ubuntu/Apache box is in `deploy/` (`deploy.sh`, vhost, supervisor and cron files).

## Design decisions

### Money is integer nano-dollars, never a float
Pixelforge's `cost_usd` has 9 decimals, so one nano-dollar (1e-9 USD) is the natural unit. `App\Support\Money` parses decimal strings with string/integer arithmetic only. A rate quoted per 1M tokens becomes an integer per-token price (`$5.00` → 5000, `$10.00` → 10000, `$40.00` → 40000, `$12.50` → 12500 nano-$ per token), and ×1.25 on those is also an integer. Rounding happens **only when formatting for display** (`Money::format`); the admin report prints full 9-decimal values. If a future rate had sub-nano precision, `tokenCost` rounds half up deterministically.

Real cost uses the `rates` carried by the generation object (fixed by Pixelforge at creation), never hard-coded numbers. The *estimate* uses `/v1/pricing`, cached 5 minutes (with a stale fallback). The documented worst case (high, 1536x1024, 400-char prompt, 2 references) is a unit test and equals exactly `$0.373875`.

### Estimate = a range, and the user is protected by the maximum
Output tokens and reference-image tokens are exact; text tokens (±15%) and reasoning tokens (quality-dependent range) are not. The UI shows min–max and explains why. The **maximum** is what gets reserved, so a generation can never overdraw the balance.

### Ledger instead of a balance column
`ledger_entries` holds signed deltas to the available balance: `purchase +X`, `reserve −max`, then on success `release +max` and `charge −real`, on failure `release +max`. Balance is `SUM(amount_nano)`. A **unique index on `(generation_id, type)`** makes it physically impossible to reserve, release or charge a generation twice, even if every application-level guard failed. Creating a generation locks the user row, checks balance ≥ max, inserts the generation and the reservation in one transaction, so two concurrent requests cannot spend the same money.

### Creating a generation: local-first, idempotent
1. In a transaction: create the local row (UUID, `pending`) and reserve credits.
2. A queued job `SubmitGeneration` calls `POST /v1/generations` with `Idempotency-Key = local UUID` (retries and replays never create or bill twice) and `metadata.local_id = local UUID` (so a webhook that beats the HTTP response can still be matched).
3. Errors are decided on the `code` field, not the HTTP status. `rate_limit_exceeded` and `overloaded` are retried honouring `Retry-After` with exponential backoff and a cap (6); `concurrency_limit_exceeded` uses `release()` and keeps waiting for a slot (up to 10 min). `content_policy_violation`, `validation_failed`, `daily_limit_exceeded` (a 429 that must *not* be retried), a bad key, etc. end in `rejected`: free, reservation released.

### One state machine for every source of truth
`GenerationStateApplier::apply()` is the only code that changes a generation's status or money. The create response, the webhook and the polling fallback all call it. Inside a DB transaction it `lockForUpdate`s the row, never leaves a final state (`succeeded` / `failed` / `rejected`), ignores a late `processing`/`queued`, and settles money exactly once. Whoever delivers a state first wins and the rest are no-ops.

### Webhooks
Signature = HMAC-SHA256 of `"{t}.{raw body}"`, constant-time compare, timestamps older than 300 s rejected. Then, in **one transaction**: `INSERT … ON CONFLICT DO NOTHING` into `webhook_events` (unique `event_id`; a duplicate returns 200 and stops), then apply. If applying throws, the event row rolls back with it and Pixelforge's retry is processed normally instead of being swallowed. The endpoint only does DB work; the image download is a queued job, so it answers well under 10 s. `ping` events are accepted and recorded.

### Polling fallback
`generations:reconcile` runs every minute from the scheduler: generations still queued/processing after 2 minutes are fetched with `GET /v1/generations/{id}` and passed to the *same* applier. It also re-queues image downloads that failed.

### Images
Pixelforge URLs expire after 24 h, so on success a job downloads the PNG to the private `local` disk and the app serves it through an authorised route (`/generations/{id}/image`).

### Admin report and reconciliation
Days are UTC and a generation belongs to the day it **completed** (`completed_at` from Pixelforge), exactly as `/v1/usage` attributes it. The reconciliation section calls `/v1/usage` live and compares cost to the nano-dollar, generation count and the four token totals for each day.

## Ambiguities and what I decided

* **Credits = USD.** 1 credit = $1; balances are shown in dollars. Purchase buttons add $5/$10/$25/$50.
* **What the user sees as status.** Internally there are `pending` (not yet accepted by Pixelforge) and `rejected` (refused at submission). The UI maps them to *queued* and *failed* so users only see the four documented states.
* **Estimate bounds for text tokens.** `ceil(chars/4)` ± `ceil(15%)`; the upper bound reproduces the documented 115 for a 400-char prompt.
* **Rounding of the 25% markup.** Rounded up to the nano-dollar (never below cost); with current rates it is always exact.
* **Retry policy.** Caps and delays are constants in `SubmitGeneration`. After the cap, a throttled/overloaded request is `rejected` (Pixelforge documents that nothing was created). A pure network failure is also retried under the idempotency key; there is a theoretical window where a request that timed out *did* reach Pixelforge and then exhausted retries; the webhook/poller cannot revive a `rejected` row on purpose (final states are final).
* **Who can see images.** Owner or admin; images are not public.
* **Email verification** is not required (not asked for), the demo needs to be easy to try.
* **No Redis**: the server has one but it belongs to other apps; the `database` queue is enough for this load.
* **Server specifics.** The shared server runs Apache + `mod_php` (not nginx) and Supervisor, so I added a separate vhost, supervisor program, cron file, directory and Postgres role/database; nothing existing was edited. Installing `php8.4-pgsql` required PHP 8.4's consistent patch upgrade (8.4.24 → 8.4.27) on the shared box; all other sites were checked afterwards.

## What I would do with more time

* Run the daily aggregation in SQL (currently PHP) and cache the report; paginate users.
* Re-dispatch `pending` generations whose queue job was lost (the idempotency key already makes it safe).
* Browser tests (Dusk/Playwright) for the polling UI and a property-based test for the state machine (random event orders must converge to one state and one charge).
* Real payments behind the same ledger `purchase` entry; refunds as a fourth entry type.
* Per-user rate limiting of submissions and an admin view of webhook events with a “replay” button.
* Rotate/verify with both the current and previous webhook secret during key rotation.
* Encrypt/expire stored images according to a retention policy.

## Demo account (production)

DEMO_CREDENTIALS_PLACEHOLDER
