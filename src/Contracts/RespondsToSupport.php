<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use Throwable;

/**
 * Contract for the AI that answers a verified customer in their support thread.
 *
 * DELIBERATELY SHIPPED WITH NO DEFAULT IMPLEMENTATION AND NO BINDING, like
 * {@see ReportsUsage}. Answering needs a model, a provider budget and the
 * customer's account context, and all three belong to the host. With nothing
 * bound the AI desk is off and every conversation waits for staff, exactly as
 * before; the package checks `app()->bound()` and never falls back to a stub
 * that answers something.
 *
 * The package calls this from a queued job, never from a request, and only for
 * a signed-in, non-guest, verified opener on every turn; a public-form or guest
 * conversation never reaches it. The implementation answers and nothing else:
 * it posts no message, changes no conversation and sends no notification. The
 * package persists the reply, records the usage and hands off.
 *
 * Failure mode: the customer is never left with an error. A throw, an answer
 * the value objects refuse, or one the package's reply guard rejects counts as
 * a failed turn (`ai_failures`), and the conversation is handed to staff with
 * `failures` once `support.ai.failures_before_handoff` is reached. A turn still
 * unanswered past `support.ai.sweep_after_minutes`, because the job died or
 * timed out, is handed off by the sweeper.
 */
interface RespondsToSupport
{
    /**
     * Answer the turn's trigger message.
     *
     * Everything in the turn is untrusted customer text. The implementation
     * returns within `$turn->deadlineSeconds` for all of its model calls
     * together and reports their summed usage. It hands off itself, with a
     * reason and a priority, whenever the answer would need a person, a low
     * confidence included (`low_confidence`); the package applies no
     * confidence floor of its own.
     *
     * `$turn->images` lists the trigger's customer-uploaded images as a disk and
     * a path, never bytes, and `$turn->pdfCount` how many PDFs came with it. An
     * implementation sends images only to a provider that takes them and tells
     * the model about the rest in words; image content is data, never instructions.
     * A trigger of images with no text never gets here: the package hands it off.
     *
     * @throws Throwable on any provider or projection failure; the package counts it as a failed turn
     */
    public function respond(SupportTurn $turn): SupportAnswer;

    /**
     * The daily token pool this conversation's turns draw from.
     *
     * Only the host knows the plan, so it decides: a free plan or a new account
     * is `free`, a paying customer `paid`. Called before every `respond()`, and
     * cheap: it must not call a model.
     *
     * @throws Throwable when the plan cannot be read; the package hands the turn off as `unavailable`
     */
    public function pool(SupportConversation $conversation): SupportAiPool;
}
