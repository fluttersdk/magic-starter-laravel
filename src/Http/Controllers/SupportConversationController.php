<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\StoreSupportAttachments;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Features;
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
use Illuminate\Support\Facades\DB;

/**
 * A signed-in customer's own support conversations.
 *
 * Every lookup is scoped to `user_id` of the caller, so a conversation someone
 * else opened, a team mate's included, answers 404 exactly like one that does
 * not exist. With the `support-attachments` feature every message this
 * controller returns carries its files; the public thread never does.
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
            // The column holds whole seconds, so a burst of turns ties; without the key the database may hand the
            // same row to two pages and skip another.
            ->orderByDesc('id')
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

        return $this->thread($conversation)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show one of the caller's conversations with its visible thread.
     */
    public function show(Request $request, string $conversation): SupportConversationResource
    {
        return $this->thread(self::findOwned($request, $conversation));
    }

    /**
     * Add the caller's message, with any files, to one of their conversations.
     *
     * The files are checked and admitted before the message is posted, so a
     * refused file posts nothing and alerts nobody; the message and its files
     * then commit together.
     */
    public function storeMessage(StoreSupportMessageRequest $request, string $conversation): JsonResponse
    {
        $owned = self::findOwned($request, $conversation);
        $user = $request->user();
        $attachments = app(StoreSupportAttachments::class);
        $files = $attachments->prepare($user, (array) $request->validated('attachments', []));

        $message = DB::transaction(function () use ($owned, $request, $user, $attachments, $files) {
            $message = app(PostsCustomerSupportMessages::class)->post(
                $owned,
                (string) $request->validated('body'),
                $user,
            );
            $attachments->store($message, $files, $user);

            return $message;
        });

        $withAttachments = Features::hasSupportAttachmentsFeatures();

        return (new SupportMessageResource($withAttachments ? $message->load('attachments') : $message))
            ->withAttachments($withAttachments)
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
        self::findOwned($request, $conversation)
            ->forceFill([
                'opener_seen_at' => now(),
            ])
            ->saveQuietly();

        return response()->noContent();
    }

    /**
     * The thread as the signed-in customer reads it, with each message's files when the feature is on.
     */
    private function thread(SupportConversation $conversation): SupportConversationResource
    {
        $withAttachments = Features::hasSupportAttachmentsFeatures();
        $resource = new SupportConversationResource(
            SupportConversationResource::forThread($conversation, attachments: $withAttachments),
        );

        return $withAttachments ? $resource->withAttachments() : $resource;
    }

    /**
     * The caller's conversation by key, or a 404.
     *
     * The key's shape is checked first: PostgreSQL refuses to compare a uuid
     * column with a string that is not one, which would surface as a 500.
     * {@see SupportAttachmentController} reaches a file through it too.
     *
     * @throws ModelNotFoundException<SupportConversation>
     */
    public static function findOwned(Request $request, string $id): SupportConversation
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
