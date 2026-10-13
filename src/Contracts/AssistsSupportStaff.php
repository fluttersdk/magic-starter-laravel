<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssist;
use Throwable;

/**
 * Contract for the AI that helps staff answer: a thread summary and a reply draft.
 *
 * DELIBERATELY SHIPPED WITH NO DEFAULT IMPLEMENTATION AND NO BINDING, like
 * {@see RespondsToSupport}: with nothing bound the panel shows the thread
 * without a summary or a draft. The package never calls it on the path that
 * opens the Reply modal, so a slow model never blocks a staff reply.
 *
 * The implementation reads the thread through `customerVisible()`: staff notes
 * never go into a prompt, even one only staff will read the output of. It
 * posts nothing; staff decide what is sent.
 *
 * Failure mode: a throw, or an answer {@see SupportStaffAssist} refuses,
 * leaves the panel without a summary and draft for that conversation; the
 * thread and the Reply action work as they do with nothing bound.
 */
interface AssistsSupportStaff
{
    /**
     * Summarise the conversation and draft the next staff reply.
     *
     * @throws Throwable on any provider failure; the panel shows no assist
     */
    public function assist(SupportConversation $conversation): SupportStaffAssist;
}
