<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\SubmitSupportFeedback;
use FlutterSdk\MagicStarter\Http\Requests\SubmitSupportFeedbackRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The in-app feedback card of a signed-in customer.
 */
class SupportFeedbackController
{
    /**
     * Whether the card may be shown now.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'eligible' => app(SubmitSupportFeedback::class)->eligible($request->user()),
        ]);
    }

    /**
     * Record the answer or dismissal; a low score answers with the conversation it opened or continued.
     */
    public function store(SubmitSupportFeedbackRequest $request): JsonResponse
    {
        $conversation = app(SubmitSupportFeedback::class)->submit(
            $request->user(),
            $request->validated('score'),
            $request->validated('comment'),
        );

        return response()->json([
            'conversation_id' => $conversation?->getKey(),
        ]);
    }
}
