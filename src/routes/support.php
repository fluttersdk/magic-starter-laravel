<?php

/**
 * Magic Starter support desk routes, for the customer side.
 *
 * Loaded by the provider only when the support feature is on, inside the same
 * `route_prefix` and `route_middleware` wrapper as `api.php` (see the comment
 * there for why the middleware default is empty). Staff work the desk from the
 * admin panel, not from here. Files are read only on the signed-in side: the
 * public thread link never reaches one. The one exception is a PDF's download,
 * whose short-lived signed url (minted for the signed-in opener) is its own
 * credential, so a system viewer holding no bearer token can follow it.
 */

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\PublicSupportThreadController;
use FlutterSdk\MagicStarter\Http\Controllers\SupportAttachmentController;
use FlutterSdk\MagicStarter\Http\Controllers\SupportAttachmentDownloadController;
use FlutterSdk\MagicStarter\Http\Controllers\SupportConversationController;
use FlutterSdk\MagicStarter\Http\Controllers\SupportFeedbackController;
use FlutterSdk\MagicStarter\Http\Controllers\SupportRatingController;
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

                if (Features::hasSupportCsatFeatures()) {
                    Route::post('conversations/{conversation}/rating', [SupportRatingController::class, 'store']);
                }

                if (Features::hasSupportFeedbackFeatures()) {
                    Route::get('feedback', [SupportFeedbackController::class, 'show']);
                    Route::post('feedback', [SupportFeedbackController::class, 'store']);
                }

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

        if (Features::hasSupportAttachmentsFeatures()) {
            // The `/attachments/` path keeps the download in the file bucket of the support limiter.
            Route::get('support/attachments/{attachment}/download', SupportAttachmentDownloadController::class)
                ->middleware([
                    'signed',
                    'throttle:magic-starter-support',
                ])
                ->name(SupportAttachmentDownloadController::ROUTE);
        }

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
