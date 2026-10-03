<?php

/**
 * Magic Starter social callback route.
 *
 * A separate file beside `api.php` for the reason `webhooks.php` is one: the
 * callback url is registered in each provider's console (Google, Apple,
 * GitHub, Microsoft) and cannot move with a deploy, so it must not inherit
 * `magic-starter.route_prefix`, which an adopter is free to change.
 *
 * Loaded by the provider through its own `loadRoutesFrom`, gated on the
 * social feature, so it joins no middleware group: Apple answers with a
 * cross-site form_post that carries no CSRF token, and a `web` registration
 * would refuse every Apple sign-in with a 419. The callback holds no session
 * either; the flow it finishes lives in the state the redirect recorded.
 */

use FlutterSdk\MagicStarter\Http\Controllers\SocialCallbackController;
use FlutterSdk\MagicStarter\Social\ProviderIdentity;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST'], ProviderIdentity::CALLBACK_PATH, SocialCallbackController::class)
    ->middleware('throttle:magic-starter-auth-social')
    ->name('magic-starter.social.callback');
