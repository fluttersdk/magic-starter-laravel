<?php

return [

    /*
     * Social sign-in outcomes and error messages for OAuth flows.
     *
     * These sentences travel in the `message` key of API responses and are
     * rendered verbatim by the client, so they stay here rather than inline.
     * Each key names a specific refusal condition that the client handles
     * differently.
     */

    /*
     * Email address conflict: an account already holds this email and the
     * social provider returned it. The client presents this when a social
     * user tries to sign up but the email is already in use.
     */
    'social_email_taken' => 'An account with this email already exists. Sign in and link the provider from your profile.',

    /*
     * Social account already linked: the same provider returned a different
     * identity than the account's existing link for that provider, which means
     * an attacker or a multi-account user got this far and needs to be told.
     */
    'social_account_taken' => 'This provider account is already linked to another account.',

    /*
     * Last login method: the account is password-only and the user tried to
     * sign in with a provider that was never linked, or tried to unlink it
     * after deleting the password (making provider-only login impossible).
     * The client is told which method is the last one, so it can offer to
     * re-add the password or link another provider.
     */
    'last_login_method' => 'This is your only login method. Add a password or link another provider before removing this one.',

    /*
     * Provider not in the allowlist: the client sent a provider name that the
     * server has not configured. This prevents a client from discovering the
     * server's provider set; it is the same refusal whether the provider
     * exists on the server and is disabled, or never existed.
     */
    'provider_not_supported' => 'This provider is not supported.',

    /*
     * Provider is enabled but not configured: the server allows it but lacks
     * the credentials or platform configuration to complete the flow. This is
     * a deployment config gap, and the client is told to try again later
     * rather than offering retry UI that cannot help.
     */
    'platform_not_configured' => 'This provider is not configured on this platform. Try again later.',

    /*
     * OAuth state or code token expired: the flow took longer than allowed
     * or the tokens were not stored. The client treats this as "you took too
     * long, try signing in again".
     */
    'flow_expired' => 'Your sign-in took too long. Try again.',

    /*
     * Identity data mismatch: the provider returned a different subject than
     * expected, or no subject at all. This is rare and usually means the
     * provider's response was corrupted or a replay attack was attempted.
     */
    'invalid_identity' => 'Could not verify your identity. Try again.',

    /*
     * Provider did not return an email: the account was created with a
     * provider that yields no email address, or the email is required to link
     * one that was missing. The client is told this is a provider limitation,
     * not a network failure.
     */
    'provider_email_missing' => 'Your provider did not provide an email address. Try another provider or sign up with email.',

    /*
     * Password already set: the account has a password and the user tried to
     * set one again (possibly through a flow that should have prevented it).
     * The client is told the password is already set, not to add one again.
     */
    'password_already_set' => 'Your account already has a password set.',

    /*
     * User owns shared teams: the account tried to delete itself but is the
     * sole owner of one or more teams. Teams cannot be orphaned, so the user
     * must transfer ownership or delete the teams first.
     */
    'owns_shared_teams' => 'You own teams that you cannot delete. Transfer ownership or delete them before deleting your account.',

    /*
     * Team has active subscription: a team owned by this account has an active
     * subscription and cannot be deleted. The user must cancel the subscription
     * and wait for it to expire or downgrade to a free plan first.
     */
    'team_has_active_subscription' => 'One of your teams has an active subscription. Cancel it first.',

    /*
     * Account deletion scheduled: the account deletion was initiated, and the
     * account is now in a grace period. The client is told the deletion is
     * pending and shows the user their cancellation window.
     */
    'deletion_scheduled' => 'Your account will be deleted in :days days. You can cancel this anytime.',

    /*
     * Deletion cancellation successful: the user cancelled a pending account
     * deletion during the grace period, and the account is restored to active
     * status. The client is told the cancellation succeeded.
     */
    'deletion_cancelled' => 'Account deletion cancelled. Your account is active again.',

    /*
     * Step-up required: a sensitive action requires re-authentication or a
     * second factor (e.g., confirming a password, 2FA code, or a link from
     * email). The client is told which credential is required.
     */
    'step_up_required' => 'Please confirm your identity to continue.',

];
