<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Schemas;

use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Facades\FilamentTimezone;
use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Contracts\DescribesSupportCustomer;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The "Customer context" section beside the thread: what the app sent with the
 * conversation, what the host app says about the customer through
 * {@see DescribesSupportCustomer} when it binds one, and the opener's latest
 * audit rows.
 *
 * Everything shown is read for the conversation's own opener and no one else.
 * An audit row is the opener's when the opener acted (a user actor with the
 * opener's key: the key alone is shared with every other actor type) or when
 * its subject belongs to the opener. Only the changed keys are listed; the
 * values were redacted by the Auditor when the row was written and are not
 * re-read here. Every string reaches the page escaped.
 */
final class CustomerContextPanel
{
    /**
     * How many audit rows the section lists, newest first.
     */
    public const ACTIVITY_LIMIT = 20;

    public static function make(): Section
    {
        return Section::make((string) __('magic-starter::admin_support.context.title'))
            ->visible(static fn (SupportConversation $record): bool => $record->user_id !== null
                || filled($record->client_context)
                || app()->bound(DescribesSupportCustomer::class))
            ->schema([
                ViewEntry::make('customer_context')
                    ->hiddenLabel()
                    ->view(self::view())
                    ->viewData(static fn (SupportConversation $record): array => self::data($record)),
            ]);
    }

    /**
     * What the view draws; a null block is left out.
     *
     * @return array{
     *     client: array<string, string>,
     *     customer: array<string, string>|null,
     *     activity: list<array{event: string, subject: string, time: string, keys: list<string>}>|null,
     * }
     */
    public static function data(SupportConversation $conversation): array
    {
        return [
            'client' => self::client($conversation),
            'customer' => self::customer($conversation),
            'activity' => self::activity($conversation),
        ];
    }

    /**
     * The view, registered by the service provider as `magic-starter`.
     *
     * Larastan boots no package provider, so a literal at the call site is rejected as a `view-string`.
     */
    protected static function view(): string
    {
        return 'magic-starter::filament.support.customer-context';
    }

    /**
     * The client info the app sent, its known keys labelled.
     *
     * @return array<string, string>
     */
    protected static function client(SupportConversation $conversation): array
    {
        $client = [];

        foreach ($conversation->client_context ?? [] as $key => $value) {
            $label = "magic-starter::admin_support.context.client_keys.{$key}";

            $client[trans()->has($label) ? (string) __($label) : (string) $key] = (string) $value;
        }

        return $client;
    }

    /**
     * The host app's description, or null while nothing is bound or the binding failed.
     *
     * The contract allows a throw; it costs the section its host block and nothing else, and is reported.
     *
     * @return array<string, string>|null
     */
    protected static function customer(SupportConversation $conversation): ?array
    {
        if (! app()->bound(DescribesSupportCustomer::class)) {
            return null;
        }

        try {
            $described = app(DescribesSupportCustomer::class)->describe($conversation);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return array_map(
            static fn (mixed $value): string => $value === null ? '-' : (string) $value,
            $described,
        );
    }

    /**
     * The opener's latest audit rows, or null for a visitor or with the audit trail off.
     *
     * @return list<array{event: string, subject: string, time: string, keys: list<string>}>|null
     */
    protected static function activity(SupportConversation $conversation): ?array
    {
        if ($conversation->user_id === null || ! Features::hasAuditFeatures()) {
            return null;
        }

        $opener = (string) $conversation->user_id;
        $userModel = MagicStarter::userModel();
        /** @var Model $user */
        $user = new $userModel;
        $userType = $user->getMorphClass();
        $timezone = FilamentTimezone::get();

        return Audit::query()
            ->where(static function (Builder $query) use ($opener, $userType): void {
                $query
                    ->where(static fn (Builder $actedBy): Builder => $actedBy
                        ->where('actor_type', $userType)
                        ->where('actor_id', $opener))
                    ->orWhere('related_user_id', $opener);
            })
            ->latest('created_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(static fn (Audit $audit): array => [
                'event' => $audit->event,
                'subject' => $audit->auditable_type === null ? '-' : class_basename($audit->auditable_type),
                'time' => (string) $audit->created_at?->copy()->setTimezone($timezone)->format('M j, Y H:i'),
                'keys' => array_values(array_unique(array_map(strval(...), [
                    ...array_keys($audit->old_values ?? []),
                    ...array_keys($audit->new_values ?? []),
                ]))),
            ])
            ->values()
            ->all();
    }
}
