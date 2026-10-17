# AI Run Log

- [Introduction](#introduction)
- [Enabling](#enabling)
- [Configuration](#configuration)
- [Recording a Run](#recording-a-run)
- [Tokens and Cost](#tokens-and-cost)
- [Masking](#masking)
- [Pruning](#pruning)
- [Admin Panel](#admin-panel)

---

<a name="introduction"></a>
## Introduction

The AI run log records every model call your application makes: one run per logical call, one attempt per provider leg (a retry or a fallback is its own attempt), with the usage and cost each reported and, until the prune blanks them, the prompt and the answer. It answers "what did this feature spend, and why did it fail" after the request is gone.

The package never calls a model and has no `laravel/ai` dependency. Your code makes the call and hands the log plain values through one contract, `FlutterSdk\MagicStarter\Contracts\RecordsAiRuns`.

**Feature:** `Features::aiRuns()`. While it is off, `start()` answers `null`, nothing is written, and no prune, panel resource or widget exists.

---

<a name="enabling"></a>
## Enabling

```php
// config/magic-starter.php
'features' => [
    Features::aiRuns(),
],
```

Publish the two migrations, `create_ai_runs_table.php` and then `create_ai_run_attempts_table.php`, and run them:

```bash
php artisan magic-starter:install --features=ai-runs
php artisan migrate
```

The installer stamps the runs table first because the attempts table references it. A host that upgrades without migrating loses nothing: the recorder skips a missing table and logs one warning per process.

---

<a name="configuration"></a>
## Configuration

| Key | Default | Meaning |
|-----|---------|---------|
| `ai_runs.retention_days` | `30` | Days a run and its attempts keep their bodies before the prune blanks them; at least 1 |
| `ai_runs.abandon_after_minutes` | `30` | Minutes after which a run still `running` is closed as `abandoned`; at least 1 |
| `ai_runs.max_body_bytes` | `262144` | Bytes one stored string may hold; the rest is cut |
| `ai_runs.mask.extra_patterns` | `[]` | Your own regular expressions, applied after the built-in ones |
| `ai_runs.mask.extra_keys` | `[]` | Your own key names, masked like the built-in ones |

Every key is optional. The package merges its config shallowly, so a published `ai_runs` block replaces the package one whole, and the three numbers fall back to the defaults above when the block leaves them out. The masking defaults are not config at all: they live in `SecretMasker` and always apply, so a published block can add to them but never switch them off.

---

<a name="recording-a-run"></a>
## Recording a Run

`RecordsAiRuns` has three methods, called in this order around one model call:

| Method | Purpose |
|--------|---------|
| `start(AiRunStart $start): int\|string\|null` | Opens a `running` run and answers its key |
| `attempt(int\|string\|null $run, AiRunAttemptData $attempt): void` | Records one finished provider call; `attempt` counts from 1 and is unique within the run |
| `finish(int\|string\|null $run, AiRunOutcome $outcome): void` | Closes the run and folds provider, model, usage and cost from its attempts |

```php
namespace App\Services;

use FlutterSdk\MagicStarter\Contracts\RecordsAiRuns;
use FlutterSdk\MagicStarter\Enums\AiCostSource;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunAttemptData;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunOutcome;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunStart;
use FlutterSdk\MagicStarter\Support\AiRuns\AiUsage;
use Throwable;

class IncidentAnalyst
{
    // ModelGateway and the answer it returns are your own; the package never sees a model.
    public function __construct(
        private readonly RecordsAiRuns $runs,
        private readonly ModelGateway $gateway,
    ) {}

    public function analyse(Incident $incident, string $instructions, string $question): string
    {
        $began = hrtime(true);

        // Open the run before the call, so a crash leaves a `running` row for the prune to close.
        $run = $this->runs->start(new AiRunStart(
            feature: 'incident.analysis',
            teamId: $incident->team_id,
            subjectType: $incident->getMorphClass(),
            subjectId: $incident->getKey(),
            promptHash: hash('sha256', $instructions),
            model: 'claude-sonnet-4-5',
            instructions: $instructions,
            input: $question,
        ));

        try {
            $answer = $this->gateway->ask($instructions, $question);
        } catch (Throwable $exception) {
            $this->runs->attempt($run, new AiRunAttemptData(
                attempt: 1,
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                status: AiRunStatus::FAILED,
                errorClass: $exception::class,
                errorMessage: $exception->getMessage(),
            ));
            $this->runs->finish($run, new AiRunOutcome(
                status: AiRunStatus::FAILED,
                errorClass: $exception::class,
                errorMessage: $exception->getMessage(),
                durationMs: $this->elapsedMs($began),
            ));

            throw $exception;
        }

        $this->runs->attempt($run, new AiRunAttemptData(
            attempt: 1,
            provider: 'anthropic',
            model: $answer->model,
            status: AiRunStatus::SUCCEEDED,
            finishReason: $answer->finishReason,
            usage: new AiUsage(
                inputTokens: $answer->inputTokens,
                outputTokens: $answer->outputTokens,
                cacheReadTokens: $answer->cacheReadTokens,
                cacheWriteTokens: $answer->cacheWriteTokens,
            ),
            costUsd: $answer->costUsd,
            costSource: AiCostSource::PROVIDER,
        ));
        $this->runs->finish($run, new AiRunOutcome(
            status: AiRunStatus::SUCCEEDED,
            output: $answer->text,
            durationMs: $this->elapsedMs($began),
        ));

        return $answer->text;
    }

    private function elapsedMs(int $began): int
    {
        return (int) ((hrtime(true) - $began) / 1_000_000);
    }
}
```

The run is opened as `running`, and `finish()` writes the status you pass: `succeeded` or `failed`. `AiRunOutcome` and `AiRunAttemptData` accept only those two and throw an `InvalidArgumentException` for any other status.

The host never branches on the recorder. A `null` run key (feature off, table missing, or a failed write) makes `attempt()` and `finish()` no-ops, so the three calls stay the same. The recorder never throws into your call path:

- Every value is made storable before it is written. Invalid UTF-8 is scrubbed and NUL bytes are stripped from every string, `NAN` and `INF` become `null`, an object becomes the array its JSON form decodes to, and `feature`, `provider`, `model`, `finishReason`, `errorClass` and the other names are cut to the width of their column.
- Every write builds its row and runs in its own savepoint, so a failed log row cannot roll back your transaction on PostgreSQL. A `QueryException`, a `JsonEncodingException` or a `DeadlockException` loses that one row and is logged at error level; a missing table is skipped with one warning. Anything else is a bug and propagates.
- The one failure that does throw is configuration: an `extra_patterns` entry that does not compile stops the application from booting, with a message naming the pattern, instead of failing every recorded call.

`AiRunStart` also takes `source` (`AiPromptSource::CODE` unless an override supplied the prompt, then `AiPromptSource::OVERRIDE`), `context` (named blocks sent with the prompt) and `tools` (the tool names offered). An attempt takes `toolCalls`, a list of `name`, `arguments` and `result`.

The `ai_runs` table also holds a `cache_hit` status and a `source_run_id` column for a response cache of your own, which would record a run that reused another run's answer. No package API writes them yet.

---

<a name="tokens-and-cost"></a>
## Tokens and Cost

`AiUsage::$inputTokens` includes the cache reads and cache writes: they are subsets of it, the convention `laravel/ai` and the OpenTelemetry GenAI conventions use. A total is `input + output`, never `input + cacheRead`. A count the provider did not report stays `null` instead of becoming `0`, and a negative count throws.

`finish()` folds the run from its attempts:

- Token counts are summed, and a total stays `null` when no attempt reported it.
- `provider` and `model` come from the last attempt that succeeded. When none did, `model` keeps the value from `start()` and `provider` stays `null`.
- `attempts_count` is the number of recorded attempts.

`cost_usd` is a decimal string in US dollars with eight places at most, such as `0.00042`; never pass a float. The run's cost is the sum of its attempts, added in whole units of 1e-8 dollars so no float rounds it.

`costSource` is an `AiCostSource`: `PROVIDER` when the provider reported the charge, `COMPUTED` when you priced the reported tokens yourself, `UNPRICED` when the call had no known price. The run keeps the source its costed attempts share and reads `MIXED` when they differ.

---

<a name="masking"></a>
## Masking

Every body is masked and then capped when it is written, so a prompt that quoted a header never reaches the log in clear. Bodies are the run's `instructions`, `input`, `context`, `output`, `structured` and `errorMessage`, and an attempt's `errorMessage` and tool call `arguments` and `result`. Tool names and the other metadata are stored as given.

`SecretMasker` works in two passes:

1. On an array, every entry whose key is a sensitive name (compared case-insensitively) has its whole value replaced, whatever its type. The other entries are walked.
2. On every string, each pattern replaces its named group `secret` when it has one, and its whole match otherwise.

The replacement is `[redacted]`. The defaults live in code, as `SecretMasker::DEFAULT_PATTERNS` and `SecretMasker::DEFAULT_KEYS`, and always apply:

- The patterns cover OpenAI and Anthropic style `sk-` keys, Stripe `sk_live_`, `sk_test_` and `rk_live_` keys, GitHub, AWS, Google and Slack keys, JWTs, `Bearer` and `Basic` values (also written `Bearer:`), credentials inside a url (an empty user and a password holding `@` included), secret query parameters (`api_key`, `access_token`, `refresh_token`, `id_token`, `client_secret`, `password` and more) and PEM, OpenSSH and PGP private keys. A private key pasted without its `END` line is masked to the end of the text.
- The key names are `authorization`, `proxy-authorization`, `password`, `passwd`, `secret`, `token`, `auth_token`, `x-auth-token`, `api_key`, `api-key`, `apikey`, `x-api-key`, `access_token`, `refresh_token`, `id_token`, `client_secret`, `private_key`, `cookie` and `set-cookie`. They apply to array keys and to text: `password=hunter2`, `"token": "..."`, `X-Api-Key: ...` and `Authorization: Token ...` all keep the name and lose the value. A name glued to a longer word, such as `tokens:`, is not one.

The masker fails closed: a string the regex engine gives up on is replaced whole, because returning it would leak exactly the oversized body most likely to carry a pasted secret.

Add your own through `extra_patterns` and `extra_keys`, which extend the defaults and can never shorten them. Each pattern is a PCRE pattern with its delimiters; use a `(?<secret>...)` group to keep the surrounding text. A pattern that does not compile stops the application from booting, with a message naming it:

```php
// config/magic-starter.php
'ai_runs' => [
    'mask' => [
        'extra_patterns' => [
            '/\bacme_live_[A-Za-z0-9]{24}\b/',
            '/customer-token:\s*(?<secret>\S+)/i',
        ],
        'extra_keys' => [
            'x-tenant-secret',
        ],
    ],
],
```

Each string is first cut to `max_body_bytes` plus 64 KiB, so masking a multi-megabyte body stays fast, then masked, then cut to `max_body_bytes` on a character boundary with a `[truncated N bytes]` marker that counts every byte missing from the original. Masking comes before the final cut, and the extra 64 KiB keeps a secret that crosses the cap whole while the patterns read it. In an array body this applies to each string in it.

---

<a name="pruning"></a>
## Pruning

```bash
php artisan magic-starter:ai-runs:prune
php artisan magic-starter:ai-runs:prune --days=7
```

The command exists, and is scheduled hourly without overlapping, only while the feature is on, so the application needs the Laravel scheduler running. Hourly, because a daily sweep would leave a dead run `running` for up to a day. It does two things:

- **Blanks old bodies.** For runs and attempts older than `retention_days` (or `--days`, at least 1), it nulls the body columns and stamps `bodies_pruned_at`. A row is never deleted and its metadata (feature, status, usage, cost, durations, timestamps) is never touched, so spend and failure history outlive the prompts. It writes in chunks of 1000 keys.
- **Closes abandoned runs.** A run still `running` after `abandon_after_minutes` (at least 1) has lost its caller, for example a worker that was killed between `start()` and `finish()`. It becomes `abandoned`, `finished_at` is stamped, and its provider, model, tokens and cost are folded from the attempts it did record, exactly as `finish()` would have, so its spend still counts. The stale runs are read in chunks of 1000 and each is closed with its own update, which only applies while the run is still `running`.

A table that does not exist is skipped, since the command can run before the migrations did.

---

<a name="admin-panel"></a>
## Admin Panel

With the [admin panel](basics/admin-panel.md) installed and the feature on, the plugin adds two parts behind the panel's own access gate:

- **`AiRunResource`**, a read-only list and view of every run: started time, feature, status, team, provider, model, attempts, tokens, cost and duration, with filters for feature, status, team and a started date range. Status badges and the status filter use translated labels (`admin_ai.statuses.*`, English and Turkish). A run is a reading of what a call did, so there is no create, edit or delete.
- **`AiRunStatsWidget`**, on the dashboard: for the last 7 and 30 days, the run count, the failed share, the cost and the cache hit rate. It does not poll; reload the page for fresh figures. The rate is `cache_read_tokens` over `input_tokens`, taken only over the runs that reported both figures; with no such run, or no run at all for the failed share, it shows a dash.

The resource is registered under the key `ai_runs`, so `MagicStarterPlugin::make()->withoutResources(['ai_runs'])` drops it like any other.
