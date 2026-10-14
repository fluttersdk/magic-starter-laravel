<?php

/**
 * Magic Starter support desk routes, for the customer side.
 *
 * Loaded by the provider only when the support feature is on, inside the same
 * `route_prefix` and `route_middleware` wrapper as `api.php` (see the comment
 * there for why the middleware default is empty). Staff work the desk from the
 * admin panel, not from here. Files are read only on the signed-in side: the
 * public thread link never reaches one.
 */

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\PublicSupportThreadController;
use FlutterSdk\MagicStarter\Http\Controllers\SupportAttachmentController;
use FlutterSdk\MagicStarter\Http\Controllers\SupportConversationController;
use Illuminate\Support\Facades\Route;

Route::prefix((string) config('magic-starter.route_prefix', ''))
    ->middleware((array) config('magic-starter.route_middleware', []))
    ->group(function (): void {
        Route::prefix('support')
            ->middleware(['auth:sanctum', 'throttle:magic-starter-support'])
            ->group(function (): void {
                Route::get('conversations', [SupportConversationController::class, 'index']);
                Route::post('conversations', [SupportConversationController::class, 'store']);
                Route::get('conversations/{conversation}', [SupportConversationController::class, 'show']);
                Route::post('conversations/{conversation}/messages', [
                    SupportConversationController::class,
                    'storeMessage',
                ]);
                Route::post('conversations/{conversation}/seen', [SupportConversationController::class, 'seen']);

                if (Features::hasSupportAttachmentsFeatures()) {
                    Route::get('conversations/{conversation}/attachments/{attachment}', [
                        SupportAttachmentController::class,
                        'show',
                    ]);
                    Route::post('conversations/{conversation}/attachments/{attachment}/link', [
                        SupportAttachmentController::class,
                        'link',
                    ]);
                }
            });

        // No account here: the address given is the only identity, and the
        // thread token mailed to it is the only credential.
        Route::prefix('support/public')
            ->middleware(['throttle:magic-starter-support-public'])
            ->group(function (): void {
                Route::post('conversations', [PublicSupportThreadController::class, 'store']);
                Route::get('thread/{token}', [PublicSupportThreadController::class, 'show']);
                Route::post('thread/{token}', [PublicSupportThreadController::class, 'reply']);
            });
    });
