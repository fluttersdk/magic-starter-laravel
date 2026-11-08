<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportPageContext;
use Throwable;

/**
 * Contract for turning the page refs a customer's message carries into facts the AI desk may read.
 *
 * DELIBERATELY SHIPPED WITH NO DEFAULT IMPLEMENTATION AND NO BINDING, like
 * {@see DescribesSupportCustomer} and {@see RespondsToSupport}: with nothing
 * bound a turn carries no page context, and the package never reads the refs
 * or sends them anywhere itself.
 *
 * The refs are customer-supplied and untrusted, only shape-checked by
 * {@see SupportPageContext}. An implementation resolves every id inside the
 * conversation's own account scope (its `team_id`, else its `user_id`) and
 * answers nothing for an unknown or foreign one. The result reaches the
 * responder as {@see SupportTurn::$pageContext}: data for a model, which the
 * host puts inside its own untrusted fence, never instructions.
 *
 * Failure mode: a throw is reported and the turn is answered without page
 * context.
 */
interface DescribesSupportPage
{
    /**
     * Describe the pages the customer wrote from.
     *
     * @param  list<array{kind: string, id: string}>  $refs  At least one, of the configured kinds, deduplicated.
     * @return list<array<string, mixed>> one entry per resolved page, JSON-encodable and kept small
     *
     * @throws Throwable on any failure; the turn goes on without page context
     */
    public function describe(SupportConversation $conversation, array $refs): array;
}
