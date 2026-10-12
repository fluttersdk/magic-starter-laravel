<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Http\Requests\StorePublicSupportConversationRequest;
use FlutterSdk\MagicStarter\Http\Requests\StoreSupportMessageRequest;
use FlutterSdk\MagicStarter\Http\Resources\SupportConversationResource;
use FlutterSdk\MagicStarter\Http\Resources\SupportMessageResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * The public support door, for a visitor with no account.
 *
 * A submission always opens a new conversation and answers without a token;
 * the thread link reaches the visitor only by mail. The token is the whole
 * credential of the thread routes, so an unknown, replaced, revoked or expired
 * one is a plain 404, and their responses forbid a Referer that would carry
 * the token's url onward.
 */
class PublicSupportThreadController
{
    /**
     * Open a new conversation from the public form.
     */
    public function store(StorePublicSupportConversationRequest $request): JsonResponse
    {
        $conversation = app(OpensSupportConversations::class)->openPublic($request->validated());

        return (new SupportConversationResource(SupportConversationResource::forThread($conversation)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show the thread the token opens.
     */
    public function show(string $token): JsonResponse
    {
        return (new SupportConversationResource(SupportConversationResource::forThread($this->resolve($token))))
            ->response()
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Add the visitor's message to the thread the token opens.
     */
    public function reply(StoreSupportMessageRequest $request, string $token): JsonResponse
    {
        $message = app(PostsCustomerSupportMessages::class)->post(
            $this->resolve($token),
            $request->validated('body'),
            null,
        );

        return (new SupportMessageResource($message))
            ->response()
            ->setStatusCode(201)
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * The conversation a live token opens, or a 404 that names nothing.
     *
     * @throws ModelNotFoundException<SupportConversation>
     */
    private function resolve(string $token): SupportConversation
    {
        return SupportThreadToken::resolve($token)
            ?? throw (new ModelNotFoundException)->setModel(SupportConversation::class);
    }
}
