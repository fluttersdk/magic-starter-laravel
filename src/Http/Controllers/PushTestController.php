<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Requests\PushTestRequest;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use onesignal\client\model\LanguageStringMap;
use onesignal\client\model\Notification as OneSignalNotification;
use Throwable;

/**
 * Sends the authenticated caller a push notification, to their own devices.
 *
 * This endpoint exists so a person can find out whether push actually reaches
 * the phone in their hand, which on an on-call product is a question worth
 * answering before an incident rather than during one.
 *
 * The safety property is the addressing, and it is a property of the ROUTE
 * rather than of any check inside it: the request carries no recipient, so the
 * target is derived from the Sanctum session and the worst a caller can do is
 * page themselves. Adding a recipient parameter later, even an optional or an
 * ignored one, breaks that property rather than extending the feature.
 *
 * It ships SWITCHED OFF, behind `magic-starter.onesignal.self_test_enabled`,
 * and an absent key is off. See that key's comment in the published config for
 * why, and for the Flutter client's matching switch: either half alone is a
 * half-measure, since a client that refuses locally leaves this endpoint
 * reachable by anything holding a token.
 */
class PushTestController
{
    /**
     * Send a test push to the authenticated caller.
     *
     * Answers 202 once the send is accepted, 501 while the surface is switched
     * off, 409 when this deployment cannot deliver push at all, 403 to a guest,
     * 422 on validation, and 429 when the named limiter refuses.
     */
    public function store(PushTestRequest $request): JsonResponse
    {
        $user = $request->user();

        // 1. Refuse before reading anything about the caller while this
        //    deployment has not switched the surface on. The whole endpoint is
        //    a capability rather than a detail: it makes the platform emit a
        //    real push because a client asked it to, so it stays cold until an
        //    application offers the button. An absent key is off, which is what
        //    a config published before the key existed carries.
        //
        //    501 because none of the other refusals means this. 403 says the
        //    caller may not, 409 says push is not provisioned here, 404 says
        //    the notifications feature is off; a switched-off endpoint is a
        //    server that does not offer the functionality, and answering with
        //    one of the others would send an adopter hunting a guest flag or a
        //    missing app id over a config key.
        //
        //    First, so the answer is the same one whoever asks. The request
        //    body is still validated ahead of this, because a FormRequest
        //    validates as it resolves; that costs a 422 to a malformed call on
        //    a switched-off endpoint and nothing else, and it sends nothing.
        if (! (bool) config('magic-starter.onesignal.self_test_enabled', false)) {
            return response()->json([
                'message' => 'The push test endpoint is not enabled for this application.',
            ], 501);
        }

        // 2. Refuse a guest. Guest authentication hands a Sanctum token to
        //    anybody who asks for one, so for an adopter running that feature
        //    this endpoint would otherwise be an anonymously reachable way to
        //    spend somebody else's OneSignal quota.
        if ((bool) ($user->is_guest ?? false)) {
            return response()->json([
                'message' => 'A guest account cannot send a test push notification.',
            ], 403);
        }

        // 3. Refuse before touching the channel when push is not provisioned.
        //    Both halves are the shipped default for an adopter who has not set
        //    push up: without the feature the channel manager has no `onesignal`
        //    driver, and without the app id `OneSignalChannel::send()` throws.
        //    Either one reaching the send path is a 500 on a default install.
        //    409 is the same signal the preference matrix already publishes as
        //    `meta.push_provisioned`.
        if (! Features::hasOnesignalFeatures() || MagicStarter::onesignalAppId() === null) {
            return response()->json([
                'message' => 'Push notifications are not provisioned for this application.',
            ], 409);
        }

        // 4. Stamp the subject the client's receive-side guard reads, over
        //    anything the caller supplied for that key. A subject a caller can
        //    choose is not a subject, it is a suggestion, and the client drops a
        //    notification whose subject disagrees with the account it is signed
        //    in as. It carries the `user_` prefix because that is the external
        //    id `routeNotificationForOneSignal()` addresses.
        $data = (array) $request->validated('data', []);
        $data['subject'] = 'user_' . $user->getKey();

        // 5. Send inside a guard, because this one leaves the request path.
        //    `OneSignalChannel::send()` reports a transport `Throwable` and then
        //    rethrows it "so the ShouldQueue job can retry the delivery", and
        //    that assumption does not hold here: this notification is anonymous
        //    and carries no state a queue could restore, so it is sent inline
        //    and there is no job to retry it. Unguarded, a OneSignal timeout, a
        //    rejected app id or a 4xx over a bad `data` payload answered the
        //    caller with an unshaped 500. `.claude/rules/http.md` asks for the
        //    opposite: report, then a user-friendly payload carrying the detail
        //    only under `app.debug`.
        //
        //    502 rather than the rule's example 500, chosen the way
        //    `AuthController` chooses 401 for a refused Socialite token: the
        //    caller's request was well-formed and this server is working, the
        //    provider it depends on did not answer. A person testing whether
        //    push reaches their phone has to be able to tell those apart.
        try {
            NotificationFacade::send($user, $this->notification(
                (string) $request->validated('title'),
                (string) $request->validated('body'),
                $data,
            ));
        } catch (Throwable $exception) {
            report($exception);

            $payload = ['message' => 'The push provider could not be reached.'];

            if (config('app.debug')) {
                $payload['error'] = $exception->getMessage();
            }

            return response()->json($payload, 502);
        }

        // 6. `accepted`, not `delivered`, because delivery is not what this
        //    knows. `NotificationFacade::send()` discards the channel's return
        //    value, and a zero-recipient send is an HTTP 200 with an empty
        //    notification id that the channel reports without throwing
        //    (`OneSignalChannel::send()`), deliberately, since it is not
        //    retryable. Somebody with no registered device is exactly the person
        //    this endpoint is for, and telling them their push was delivered is
        //    the one answer that sends them looking in the wrong place.
        return response()->json(['accepted' => true], 202);
    }

    /**
     * Build the one-off notification this endpoint sends.
     *
     * Anonymous on purpose. It carries no state a queue would need to restore,
     * nothing outside this controller may construct it, and it stays out of the
     * `NotificationPreferenceRegistry`, so the preference matrix never gates a
     * diagnostic the person is standing in front of.
     *
     * @param  array<string, mixed>  $data
     */
    private function notification(string $title, string $body, array $data): Notification
    {
        return new class($title, $body, $data) extends Notification
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function __construct(
                private readonly string $title,
                private readonly string $body,
                private readonly array $data,
            ) {}

            /**
             * @return array<int, string>
             */
            public function via(mixed $notifiable): array
            {
                return ['onesignal'];
            }

            /**
             * Build the OneSignal payload.
             *
             * The channel forces `app_id` and applies the notifiable's
             * `external_id` alias, so this carries only what to show and what to
             * hand the app. The text is caller-authored in whatever language the
             * caller wrote it, so it is not translated; it goes in the `en` slot
             * because OneSignal requires that entry as the fallback.
             *
             * `data` crosses as an object rather than as a map, because that is
             * what the SDK's field is typed as and what an empty one has to
             * serialize to: an empty PHP array would reach the device as `[]`.
             *
             * @return OneSignalNotification<string, mixed>
             */
            public function toOneSignal(mixed $notifiable): OneSignalNotification
            {
                $payload = new OneSignalNotification;

                $payload->setHeadings(new LanguageStringMap(['en' => $this->title]));
                $payload->setContents(new LanguageStringMap(['en' => $this->body]));
                $payload->setData((object) $this->data);

                return $payload;
            }
        };
    }
}
