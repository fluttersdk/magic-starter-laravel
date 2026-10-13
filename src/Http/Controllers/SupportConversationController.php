<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Http\Requests\StoreSupportConversationRequest;
use FlutterSdk\MagicStarter\Http\Requests\StoreSupportMessageRequest;
use FlutterSdk\MagicStarter\Http\Resources\SupportConversationResource;
use FlutterSdk\MagicStarter\Http\Resources\SupportMessageResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A signed-in customer's own support conversations.
 *
 * Every lookup is scoped to `user_id` of the caller, so a conversation someone
 * else opened, a team mate's included, answers 404 exactly like one that does
 * not exist.
 */
class SupportConversationController
{
    /**
     * List the caller's conversations, latest activity first, `per_page`
     * (clamped to 1..50, default 15) at a time from `page`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(
            1,
            min($request->integer('per_page', 15), 50),
        );

        $conversations = SupportConversationResource::forList(
            SupportConversation::query()->where('user_id', $request->user()->getAuthIdentifier()),
        )
            ->orderByDesc('last_activity_at')
            ->paginate($perPage);

        return SupportConversationResource::collection($conversations);
    }

    /**
     * Open a conversation with its first message.
     */
    public function store(StoreSupportConversationRequest $request): JsonResponse
    {
        $conversation = app(OpensSupportConversations::class)->open(
            $request->user(),
            $request->validated(),
        );

        return (new SupportConversationResource(SupportConversationResource::forThread($conversation)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show one of the caller's conversations with its visible thread.
     */
    public function show(Request $request, string $conversation): SupportConversationResource
    {
        return new SupportConversationResource(
            SupportConversationResource::forThread($this->findOwned($request, $conversation)),
        );
    }

    /**
     * Add the caller's message to one of their conversations.
     */
    public function storeMessage(StoreSupportMessageRequest $request, string $conversation): JsonResponse
    {
        $message = app(PostsCustomerSupportMessages::class)->post(
            $this->findOwned($request, $conversation),
            $request->validated('body'),
            $request->user(),
        );

        return (new SupportMessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Record that the caller has read the thread up to now, clearing `has_unread`.
     *
     * Saved quietly: an open thread sends one on every refresh, and a model event would put each in the audit trail.
     */
    public function seen(Request $request, string $conversation): Response
    {
        $this->findOwned($request, $conversation)
            ->forceFill([
                'opener_seen_at' => now(),
            ])
            ->saveQuietly();

        return response()->noContent();
    }

    /**
     * The caller's conversation by key, or a 404.
     *
     * The key's shape is checked first: PostgreSQL refuses to compare a uuid
     * column with a string that is not one, which would surface as a 500.
     *
     * @throws ModelNotFoundException<SupportConversation>
     */
    private function findOwned(Request $request, string $id): SupportConversation
    {
        if (! TeamKey::looksLikeOne($id)) {
            throw (new ModelNotFoundException)->setModel(SupportConversation::class, [
                $id,
            ]);
        }

        return SupportConversation::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->whereKey($id)
            ->firstOrFail();
    }
}
