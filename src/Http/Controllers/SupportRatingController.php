<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\RateSupportConversation;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Http\Requests\RateSupportConversationRequest;
use FlutterSdk\MagicStarter\Http\Resources\SupportConversationResource;

/**
 * A signed-in customer's rating of one of their own resolved conversations.
 */
class SupportRatingController
{
    /**
     * Rate the conversation and answer it as the thread read now shows it.
     */
    public function store(RateSupportConversationRequest $request, string $conversation): SupportConversationResource
    {
        $rated = app(RateSupportConversation::class)->rate(
            SupportConversationController::findOwned($request, $conversation),
            SupportCsatRating::from($request->validated('rating')),
            $request->validated('comment'),
        );

        return SupportConversationController::thread($rated);
    }
}
