<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Support\MailLocale;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * The one-click newsletter unsubscribe door, opened from a mail without signing in.
 *
 * The token in the url is the whole credential, so an unknown one is a 404 that
 * names nothing. `show` only renders a page: mail scanners and link previews
 * follow every link, and a GET that withdrew would unsubscribe people who never
 * clicked. `unsubscribe` is the POST a mail provider sends for the
 * `List-Unsubscribe-Post` header (RFC 8058): it accepts any body, needs no
 * cookie, and answers 200 rather than a redirect. The routes join no middleware
 * group, so there is no route model binding and the token arrives as a string.
 */
class PublicNewsletterUnsubscribeController
{
    /**
     * Ask to confirm the withdrawal, or say it already happened. Changes nothing.
     */
    public function show(string $token): Response
    {
        $subscriber = $this->resolve($token);

        if ($subscriber === null) {
            return $this->page('not_found', status: 404);
        }

        $this->useOwnerLocale($subscriber);

        if ($subscriber->unsubscribed_at !== null) {
            return $this->page('done');
        }

        return $this->page('confirm', action: route('magic-starter.newsletter.unsubscribe.store', [
            'token' => $token,
        ]));
    }

    /**
     * Withdraw the consent the token stands for. Repeating it changes nothing.
     */
    public function unsubscribe(string $token): Response
    {
        $subscriber = $this->resolve($token);

        if ($subscriber === null) {
            return $this->page('not_found', status: 404);
        }

        $this->useOwnerLocale($subscriber);

        // The request url carries the token; the audit row must not.
        $subscriber->unsubscribe('one_click', [
            'url' => null,
        ]);

        return $this->page('done');
    }

    /**
     * The row the token opens, or null.
     *
     * The comparison is repeated in constant time because a database collation
     * may match the token case-insensitively, and case is part of the secret.
     */
    private function resolve(string $token): ?NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()->where('unsubscribe_token', $token)->first();

        if ($subscriber === null || ! hash_equals((string) $subscriber->unsubscribe_token, $token)) {
            return null;
        }

        return $subscriber;
    }

    /**
     * The request has no session or user to take a language from, so the owner's preference decides.
     */
    private function useOwnerLocale(NewsletterSubscriber $subscriber): void
    {
        $owner = $subscriber->user;

        if ($owner !== null) {
            App::setLocale(MailLocale::for($owner));
        }
    }

    /**
     * @param  'confirm'|'done'|'not_found'  $state
     * @param  string|null  $action  The store url, only in `confirm`: the one place the token may appear.
     */
    private function page(string $state, ?string $action = null, int $status = 200): Response
    {
        return response()
            ->view('magic-starter::newsletter.unsubscribe', [
                'state' => $state,
                'action' => $action,
            ], $status)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
