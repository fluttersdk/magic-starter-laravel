<?php

namespace FlutterSdk\MagicStarter\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Runs one admin write through a package contract.
 *
 * The contracts refuse with a ValidationException (or SocialSignInRefused for a
 * social unlink). In the panel that refusal is an outcome the operator reads,
 * not a crash: it becomes a danger notification and the action or page halts,
 * rolling back the surrounding transaction and staying open. Any other
 * exception is a bug and propagates.
 *
 * A {@see BillingAdministrationRefused} halts the same way but COMMITS the
 * surrounding transaction instead. The billing contract records its
 * `request_refused` row before it throws, and that row is the only record the
 * operator was refused; it wrote nothing else, so there is nothing to roll back
 * and a rollback under a panel with `databaseTransactions()` would erase the
 * audit row alone.
 */
class ContractAction
{
    /**
     * @template TResult
     *
     * @param  Action|null  $action  The Filament action to halt; null from a page save, which halts the same way.
     * @param  Closure(): TResult  $call  The contract call.
     * @param  string  $event  The stable dotted name {@see AdminActionPerformed} carries, such as `user.updated`.
     * @param  Model|null  $subject  The record the write targets; null on a create, where the
     *                               model the call returns becomes the subject.
     * @param  array<string, mixed>  $context  Extra facts the audit row should carry, such as the
     *                                         member a team action touched.
     * @return TResult
     *
     * @throws Halt when the contract refused
     * @throws AuthenticationException when no panel user is signed in
     */
    public static function run(?Action $action, Closure $call, string $event, ?Model $subject, array $context = []): mixed
    {
        $actor = Filament::auth()->user() ?? throw new AuthenticationException;

        try {
            $result = $call();
        } catch (ValidationException $exception) {
            static::refuse($action, Arr::first(Arr::flatten($exception->errors())) ?? $exception->getMessage());
        } catch (SocialSignInRefused $exception) {
            static::refuse($action, $exception->getMessage());
        } catch (BillingAdministrationRefused $exception) {
            static::refuse($action, $exception->getMessage(), rollBack: false);
        }

        $subject ??= $result instanceof Model ? $result : null;

        Event::dispatch(new AdminActionPerformed($actor, $event, $subject, $context));

        return $result;
    }

    /**
     * @param  bool  $rollBack  whether the halt rolls back the surrounding database transaction
     *
     * @throws Halt always
     */
    protected static function refuse(?Action $action, string $message, bool $rollBack = true): never
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->send();

        $action?->halt(shouldRollBackDatabaseTransaction: $rollBack);

        throw (new Halt)->rollBackDatabaseTransaction($rollBack);
    }
}
