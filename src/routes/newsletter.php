<?php

/**
 * Magic Starter public newsletter unsubscribe routes.
 *
 * A separate file beside `api.php` for the reason `webhooks.php` is one: the url
 * is baked into every mail already sent, so it must not move with
 * `magic-starter.route_prefix`. Loaded by the provider through its own
 * `loadRoutesFrom`, gated on the newsletter feature, so it joins no middleware
 * group: a mail provider's one-click POST (RFC 8058) carries no cookie and no
 * CSRF token, and a `web` registration would refuse it with a 419.
 *
 * The token is the only credential. The GET page changes nothing, because mail
 * scanners and link previews follow every link; only the POST withdraws.
 */

use FlutterSdk\MagicStarter\Http\Controllers\PublicNewsletterUnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::get('newsletter/unsubscribe/{token}', [PublicNewsletterUnsubscribeController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]{20,64}')
    ->middleware('throttle:magic-starter-newsletter-unsubscribe-page')
    ->name('magic-starter.newsletter.unsubscribe');

Route::post('newsletter/unsubscribe/{token}', [PublicNewsletterUnsubscribeController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9_-]{20,64}')
    ->middleware('throttle:magic-starter-newsletter-unsubscribe')
    ->name('magic-starter.newsletter.unsubscribe.store');
