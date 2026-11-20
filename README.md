# PixelForge

A Laravel 13 + Inertia 3 + Vue 3 (TypeScript, Tailwind 4) app where signed-in users generate images through
[Pixelforge](https://pixelforge.promptedit.dev/docs), an asynchronous image API, and pay Pixelforge's cost + 25 %,
exactly.

- **Generate**: prompt, size, quality and up to 4 reference URLs, with a live cost range computed in the browser
  (BigInt, the same math as the server).
- **History**: live status (Waiting → Queued → Generating… → Done / Failed), estimated vs actual cost with a per-token
  breakdown, the image, readable errors, Retry.
- **Credits**: balance, fake credit packs, the full ledger (holds, releases, charges, purchases).
- **Admin report**: credit spend, provider cost, profit and margin per UTC day and per user, reconciled
  to the nano-dollar against Pixelforge's `GET /v1/usage`.

Auth is the official Laravel Vue starter kit (Fortify), with 2FA, passkeys and email verification switched off.

## Setup

### Docker (recommended)

```bash
cp .env.example .env            # then set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET
docker compose up -d            # composer install, APP_KEY, migrate --seed, queue, scheduler, Vite
```

Open http://localhost:8000. Seeded users (local only): `demo@example.com` / `password` ($11 of credit) and
`admin@example.com` / `password` (admin, $26). `make` wraps the common commands (`make test`, `make check`, `make fresh`, …).

> Upgrading from the old JWT version of this repo? Its tables are still in the local MySQL volume and the app
> container's startup migration fails on them. Reset the local database once:
> `docker compose run --rm --entrypoint "" app php artisan migrate:fresh --seed` (or `docker compose down -v`, which
> deletes the MySQL volume), then `docker compose up -d --build`.

### Without Docker

PHP 8.4 (bcmath, intl, pdo_sqlite), Node 24:

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # set DB_CONNECTION=sqlite, remove the other DB_ lines
php artisan migrate --seed
composer run dev     # server, queue:listen, scheduler (schedule:work), pail and Vite, all in one
```

The queue worker and the scheduler are required: submissions run on the queue, and the scheduler runs the polling
fallback every minute. On their own: `php artisan queue:work` and `php artisan schedule:work`.

### Testing webhooks locally

Pixelforge refuses `localhost` and private addresses as a webhook URL, so expose the app with a tunnel:

```bash
cloudflared tunnel --url http://localhost:8000     # or: ngrok http 8000 / expose share http://localhost:8000
```

In the Pixelforge dashboard, on **your local API key**, set the webhook URL to `https://<tunnel>/webhooks/pixelforge`,
copy the signing secret into `PIXELFORGE_WEBHOOK_SECRET`, and press "Send test event". The delivery log should show
`200`. Use **one API key per environment** (local, staging, production): each key has its own webhook URL, signing
secret and usage report, so environments never receive (or reconcile against) each other's generations. Without a
tunnel everything still works, a bit slower: the poller picks up results after about a minute.

### Checks

```bash
php artisan test               # Pest: 189 tests, all with Http::fake(), no network
npm test                       # Vitest: the BigInt money/pricing mirror
vendor/bin/phpstan analyse     # Larastan level 7, 0 errors
vendor/bin/pint --test
npm run check && npm run types:check && npm run build
```

Deploying to Laravel Cloud: [docs/production-deploy.md](docs/production-deploy.md). The plan written before
coding: [docs/PLAN.md](docs/PLAN.md).

## Architecture

```
Browser (Inertia/Vue) ── POST /generations ──> GenerationController ─> GenerationService::submit
                                                                      │ lock user, balance ≥ max?, pending row + hold
                                                                      └─> SubmitGenerationJob (queue)
SubmitGenerationJob ─> GenerationSubmissionService ── slot gate (≤3, FIFO) ── PixelforgeClient (Idempotency-Key = uuid)
        │ 202 ────────────────────────────────┐
Pixelforge ── POST /webhooks/pixelforge ──> WebhookService (verify signature, dedupe by event id)
        │                                     │
pixelforge:reconcile ─> ReconcileService (every minute) ── GET /v1/generations/{id}
                                              ▼
                                   GenerationStateMachine  (one place)
                                              │ on success: CreditLedger::settle (release hold, charge exact)
                                              │ on failure: CreditLedger::release
```

| Piece | Where |
|---|---|
| HTTP client, typed errors, request budget | `app/Services/Pixelforge/PixelforgeClient.php`, `Exceptions/*`, DTOs in `Data/*` |
| Money value object (integer nano-dollars) | `app/Support/Money.php` |
| Pricing math (cost, markup, estimate) | `app/Services/Pricing/PricingCalculator.php`; mirrored in `resources/js/lib/pricing.ts` |
| Current pricing (cached `/v1/pricing`, documented fallback) | `app/Services/Pricing/PricingCatalogProvider.php` |
| Ledger | `app/Services/Credits/CreditLedger.php`, `credit_transactions` |
| Submit | `app/Services/Generations/GenerationService.php`, `GenerationSubmissionService.php`, `GenerationSlots.php`, `app/Jobs/SubmitGenerationJob.php` |
| State machine | `app/Services/Generations/GenerationStateMachine.php` |
| Webhooks | `routes/webhooks.php`, `PixelforgeWebhookController`, `app/Services/Webhooks/WebhookService.php`, `WebhookSignatureVerifier` |
| Polling fallback | `app/Services/Generations/ReconcileService.php`, `pixelforge:reconcile` in `routes/console.php` |
| Admin report | `app/Services/Reports/AdminReportService.php`, `/admin/report` (gate `viewAdminReport`) |
| Queries | `app/Repositories/*`: every query and write; controllers and services never touch Eloquent directly |
| Config | `config/pixelforge.php` (markup, limits, retry policy, packs, signup bonus) |

### Generation states

```
                 202 accepted          generation.processing       generation.succeeded
  pending ─────────────────> queued ─────────────────> processing ─────────────────> succeeded
     │  (ours: waiting for      │                          │           (charge exact amount)
     │   a slot / retrying)     │                          │
     │                          └──────────────┬───────────┘
     │ 400 / 422 / 401 / 409 / daily limit /   │ generation.failed
     │ gave up after retries                   ▼
     └──────────────────────────────────────> failed  (hold released: free)

rank: pending 0 < queued 1 < processing 2 < succeeded = failed 3 (terminal)
An update is applied only if it raises the rank. Terminal states never change.
```

## Decisions & trade-offs

**Integer nano-dollars.** Every amount (rates, costs, holds, charges, balances) is a `BIGINT` of nano-dollars
(10⁻⁹ USD). Pixelforge's rates ("12.50" USD per 1M tokens) are parsed from strings, never floats, and are a whole
number of nanos per token (12.50 per 1M = 12 500 nanos). So `Σ tokens × rate` is exact, and Pixelforge's 9-decimal
`cost_usd` is exactly our integer. The 25 % markup (`× 125 / 100`) stays whole too, since every per-token rate is a
multiple of 2 500 nanos. If a future rate broke that, the cost is rounded down with a warning and the markup is rounded
down: in the user's favour, never against them. The browser receives nanos as **strings** and formats them with `BigInt`
(`resources/js/lib/money.ts`). Actual costs are shown exactly ("$0.0707"), with "≈ $0.07" alongside. Estimate ranges show 4
decimals, rounded outwards so the displayed range always contains the real one.

**Estimate range, holding the maximum.** Text and reasoning tokens are only known afterwards. The estimate uses
Pixelforge's own published token rules (from `/v1/pricing`, not hardcoded): text = ⌈chars/4⌉ ± 15 %, images 765 each,
exact output tokens, a reasoning range per quality. *min* = −15 % text (floor) + min reasoning, *expected* = ⌈chars/4⌉ +
midpoint reasoning, *max* = +15 % text (ceil) + max reasoning. On submit we **hold the max**, so the final charge can
never exceed what the user agreed to. It is asserted, and if it ever happened the charge is capped at the hold and
logged as `critical`. The hold is released and replaced by the exact charge when the image is done. Failures release
it entirely.

**Ledger, not just a number.** `credit_transactions` is append-only (bonus, purchase, hold, hold_release, charge) and
`users.balance_nanos` is its running sum, written in the same transaction under `SELECT … FOR UPDATE` on the user row.
Double-charging is impossible **at the database level**: `UNIQUE(generation_id, type)` allows one hold, one release
and one charge per generation, and every entry carries a unique idempotency key (`generation:{id}:charge`,
`purchase:{user}:{client uuid}`). The state machine already avoids a second settlement. These constraints are the
backstop if it ever had a bug. Credit purchases are idempotent per click (the browser sends a UUID). New users get a
configurable $1.00 welcome credit (`PIXELFORGE_SIGNUP_BONUS_USD`, 0 to disable) so they can try it immediately.
Generations and ledger rows survive account deletion (`user_id` → NULL): they're financial history, and the admin
report must still reconcile with Pixelforge.

**Idempotency keys.** A generation's own uuid is its `Idempotency-Key`. The job can safely retry after a timeout with
an unknown outcome: Pixelforge returns the existing generation (`Idempotent-Replayed`) instead of billing a second
one. The parameters never change between retries, so `409 idempotency_key_reused` would mean a bug (logged as an
error). "Retry" on a failed generation creates a *new* generation with a new key and a new hold: a failed generation is
terminal. I chose an explicit Retry (plus "Edit & retry") over an automatic resubmit: it keeps the money model simple
(one generation = one hold = at most one charge), and the user decides whether to spend again.

**Never calling Pixelforge in the request.** The controller only validates, holds and queues. A slow or overloaded
provider can't hold up a page, and every retry happens in one place (`SubmitGenerationJob`).

**Concurrency and rate limits, proactively.** Pixelforge allows 3 generations in flight per account and 60 requests a
minute (and retries count). Two guards:
1. A **slot gate** (a cache lock plus a count of queued/processing rows and in-flight claims) lets at most 3
   generations be submitted at once, **first come, first served**. Everyone else stays `pending` ("Waiting for a free
   slot") and the job is released for 5 s. Waiting doesn't use up the retry budget.
2. A shared **request budget** (50/min, below Pixelforge's 60) in front of every API call, so polling and submitting
   together never hit the 429.

Pixelforge's own 429/503 answers stay the source of truth: `Retry-After` is honoured, with exponential backoff and
jitter, capped at 8 real errors. After that, or after 30 minutes pending, the generation fails for free with a friendly
message. A content-policy violation or validation error fails immediately, free, with the provider's message.
The daily limit fails with "try again after 00:00 UTC". A 401 fails and logs `critical`. Network errors and 5xx are
retried. Submissions are throttled to 10/min per user.

**Webhook verification and dedup.** `POST /webhooks/pixelforge` is registered outside the `web` group (no session, no
CSRF), and the path is also excluded from CSRF in case it's ever moved. The HMAC-SHA256 of `"{t}.{raw body}"` is checked
with `hash_equals` against every `v1=` in the header, within ±300 s. The raw bytes are used, never re-encoded JSON
(a test sends pretty-printed JSON to prove it). The processing transaction does `insertOrIgnore` on the event id,
locks the row, returns `duplicate` if `processed_at` is set, applies the update, then sets `processed_at`. Concurrent
duplicate deliveries serialize on the row. If applying throws, the insert rolls back with everything else, so
Pixelforge's retry processes the event again: an event is never stuck "recorded but not applied". Unknown generations
and `ping` get a 200 and are stored.

**A monotonic state machine, in one place.** `GenerationStateMachine` is used by the create response, webhooks and
polling. It locks the generation row and only applies a higher-ranked status. A late `processing` after `succeeded` is
a no-op, and `failed` followed by `succeeded` keeps `failed` (logged as a warning). Settlement only happens on the
transition into a terminal state. Facts that never change (Pixelforge id, rates, created_at) are filled whenever
they arrive. A webhook can arrive **before** the create response. It is matched through
`metadata.generation_id` (our uuid, sent with every create), and the create response uses the id the job already knows.

**Polling fallback.** Webhooks aren't guaranteed. Every minute, `pixelforge:reconcile` (with `withoutOverlapping`)
polls up to 15 queued/processing generations not updated for 60 s, oldest-polled first, and applies them through the
same state machine. `[slow]` generations (60–90 s) are simply polled again next minute. It also re-dispatches `pending`
generations whose job got lost; the job is `ShouldBeUnique` per generation, so this never duplicates a live one.

**Billing day = the UTC day of completion.** Pixelforge bills a generation on the UTC day it *completed*, so the
report groups by `pixelforge_completed_at` (in UTC), not by our created_at. A generation started at 23:59 and done at
00:01 counts on the next day, on both sides. Totals are summed in PHP with integers (exact, database-agnostic).
`/v1/usage` is fetched in ≤ 92-day chunks, cached 60 s, and compared per day on cost, count and every token type. Any
nano of difference is shown.

**Expired images.** `image_url` expires 24 h after completion and GET doesn't extend it. We store the URL and its
expiry and show an "Image link expired" placeholder afterwards (also when the image fails to load earlier). The record,
cost and breakdown stay.

**Pricing source.** Estimates use `/v1/pricing` (cached for an hour, with the documented values as a fallback when it's
down). Actual costs always use the `rates` frozen on each generation.

### Known limitations and what I'd do next

- **Images aren't stored.** Next: on `succeeded`, a job copies the PNG to S3/R2 and serves it through our own URL.
- **Polling the UI every 3 s** (an Inertia partial reload, only while something on the page is running, paused in
  background tabs). Next: Laravel Reverb / websockets, broadcasting from the state machine.
- **Fake payments.** Next: Stripe Checkout, crediting the ledger from its webhook with the same idempotency pattern.
- **Giving up on an accepted-but-unconfirmed generation.** If every create attempt times out (outcome unknown) and we
  give up, Pixelforge may still have created and billed it, and we'd ignore the late `succeeded`. That cost shows up as
  a reconciliation difference. A dead-letter step could `GET` by idempotency (Pixelforge has no lookup by key) or
  keep polling.
- **The slot gate counts this app only.** The limits are per *account*, and each environment has its own key. If
  several environments share an account, the 429 path still handles it, at the cost of some extra requests.
- **Admin report** sums in PHP: fine for a year of a small app. At scale: daily rollup rows written at settlement.
- **No cancellation**: Pixelforge has no cancel endpoint.

### Where the Pixelforge docs differ from (or add to) the brief

All the brief's facts matched the docs. Things the docs add or say differently:

- The **300 s tolerance** is the value in the docs' Laravel example ("reject old timestamps"), not a stated limit.
- `/v1/pricing` publishes the **token rules** too (characters per token, `text_token_variance: 0.15` as a JSON
  number, tokens per reference image, output and reasoning tables). They're used instead of hardcoding.
- An idempotent replay doesn't count against the concurrency or daily limits.
- Every authenticated response carries `X-RateLimit-Limit` / `X-RateLimit-Remaining`.
- More error codes: `not_found` (404, also for other accounts' generations) and `date_range_too_long` (422, usage
  over 92 days).
- `GET /v1/generations` takes `limit` (1–100), `status` and `cursor`. `GET /v1/generations/{id}` returns a fresh
  image URL with the *same* expiry.
- `/v1/usage` includes zero days and a `total`, and is per API key.
- Webhook URLs can't be localhost/private (a tunnel is needed locally). The dashboard can resend events, and
  `Pixelforge-Delivery-Attempt` counts those too. Redirects aren't followed.
- Test keywords are case-insensitive and count as prompt text tokens.

## Manual verification with the test keywords

Sign in as `demo@example.com`, open **Generate**, and use the "Test keywords" panel (click a keyword to insert it).
Keep **History** open: it updates by itself.

| Prompt contains | What you should see |
|---|---|
| `[succeed]` | Waiting → Queued → Generating… → **Done** within ~30 s. "Charged $0.0…" sits inside the estimated range. "Cost details" shows tokens × rate, the Pixelforge cost, the 25 % fee and the total. **Credits** shows hold → hold released → charge, and the balance went down by exactly the charge. |
| `[fail]` | Ends **Failed**, "Nothing, it's free", a readable message, **Retry** / **Edit & retry**. Credits: hold and an equal hold release, balance unchanged. |
| `[slow]` | Stays **Generating…** for 60–90 s, then Done. Without a tunnel, the poller completes it within a minute of it finishing. |
| `[duplicate]` | Done, charged **once**. In the DB, each `evt_` appears once in `webhook_events`, and the log has "Duplicate webhook ignored". |
| `[blocked]` | Immediately **Failed** with the content-policy message; never charged (hold released). |
| `[overloaded]` | **Waiting**: "The image provider is busy, retrying automatically" (Pixelforge answers 503 every time). After 8 attempts it fails for free with "too busy right now". |
| 4+ at once | The 4th and later show "Waiting for a free generation slot" and start, in order, as slots free up. No 429s in the log. |
| A balance below the max | The cost panel shows "Not enough credits" with a **Buy credits** button instead of Generate. Buy a pack on Credits and come back. |

Admin: sign in as `admin@example.com` → **Admin report**. Each day with completed generations shows ✓ Match against
Pixelforge's usage (with the same API key, and no generations made outside the app).
