<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\SupportConversation;
use Throwable;

/**
 * Contract for what the host app knows about a customer, shown to staff beside the thread.
 *
 * DELIBERATELY SHIPPED WITH NO DEFAULT IMPLEMENTATION AND NO BINDING, like
 * {@see AssistsSupportStaff}: with nothing bound the panel shows the client
 * info the app sent and the opener's recent audit rows, and nothing else.
 *
 * The implementation returns plain label to value pairs (plan, usage, region),
 * and reads only what the opener's own account holds: it never widens past the
 * conversation's `user_id`, and it returns nothing secret, since staff read the
 * result.
 *
 * Failure mode: a throw leaves the panel without the host's section; the
 * thread and every other panel section work as they do with nothing bound.
 */
interface DescribesSupportCustomer
{
    /**
     * Describe the customer who opened the conversation.
     *
     * @return array<string, scalar|null> label to value, in display order
     *
     * @throws Throwable on any failure; the panel shows no host section
     */
    public function describe(SupportConversation $conversation): array;
}
