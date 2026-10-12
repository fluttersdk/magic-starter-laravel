<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Models\SupportConversation;

/**
 * The secret in a public support thread link, for a visitor with no account.
 *
 * The plaintext exists only in the return value of {@see self::mint()}, in the
 * encrypted queued reply notification and in the mail it goes into; the row
 * keeps its sha256. One conversation holds one
 * live token: a new mint replaces the hash, so the link in an older mail stops
 * working, and a revoke clears it. Both bump `thread_token_version`. A token
 * also stops working after `magic-starter.support.public.link_ttl_days`.
 */
final class SupportThreadToken
{
    /**
     * Mint a new token for the conversation, replacing any live one.
     *
     * @return string the url-safe plaintext; returned once and never stored, so a caller puts it in the
     *                link and nowhere else
     */
    public static function mint(SupportConversation $conversation): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');

        $conversation->forceFill([
            'thread_token_hash' => self::hash($token),
            'thread_token_version' => $conversation->thread_token_version + 1,
            'thread_token_expires_at' => now()->addDays(
                (int) config('magic-starter.support.public.link_ttl_days', 14),
            ),
        ])->save();

        return $token;
    }

    /**
     * The conversation a token opens, or null for a token that is unknown,
     * replaced, revoked or expired. The caller answers all four the same way.
     */
    public static function resolve(string $token): ?SupportConversation
    {
        if ($token === '') {
            return null;
        }

        $hash = self::hash($token);
        $conversation = SupportConversation::query()
            ->where('thread_token_hash', $hash)
            ->first();

        // The database comparison depends on the column's collation and is not
        // constant time; this one is the comparison that decides.
        if ($conversation === null || ! hash_equals((string) $conversation->thread_token_hash, $hash)) {
            return null;
        }

        if ($conversation->thread_token_expires_at === null || ! $conversation->thread_token_expires_at->isFuture()) {
            return null;
        }

        return $conversation;
    }

    /**
     * Close the conversation's public link without issuing a new one.
     */
    public static function revoke(SupportConversation $conversation): void
    {
        $conversation->forceFill([
            'thread_token_hash' => null,
            'thread_token_version' => $conversation->thread_token_version + 1,
            'thread_token_expires_at' => null,
        ])->save();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
