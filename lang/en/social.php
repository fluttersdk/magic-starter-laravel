<?php

return [

    /*
     * Social sign-in and account-deletion messages. Each travels in the
     * `message` key beside a stable `code` the client switches on, and is
     * rendered verbatim.
     */

    'social_email_taken' => 'An account with this email already exists. Sign in and link the provider from your profile.',

    'social_account_taken' => 'This provider account is already linked to another account.',

    'last_login_method' => 'This is your only login method. Add a password or link another provider before removing this one.',

    'provider_not_supported' => 'This provider is not supported.',

    'platform_not_configured' => 'This provider is not configured on this platform. Try again later.',

    'flow_expired' => 'Your sign-in took too long. Try again.',

    'invalid_identity' => 'Could not verify your identity. Try again.',

    'provider_email_missing' => 'Your provider did not provide an email address. Try another provider or sign up with email.',

    'password_already_set' => 'Your account already has a password set.',

    // Owned teams other people still belong to block an account deletion.
    'owns_shared_teams' => 'You own teams that other people belong to. Hand them over or delete them before deleting your account.',

    'team_has_active_subscription' => 'One of your teams has an active subscription. Cancel it first.',

    // Under user billing, the account's own subscription blocks its deletion.
    'subscription_active' => 'Your account has an active subscription. Cancel it before deleting your account.',

    // Every session was just signed out, so signing in again is the only way to cancel.
    'deletion_scheduled' => 'Your account will be deleted in :days days. Sign in again before then to cancel the deletion.',

    // The purge is queued at once; signing in can no longer be relied on to cancel it.
    'deletion_immediate' => 'Your account is being deleted. This cannot be undone.',

    'deletion_cancelled' => 'Account deletion cancelled. Your account is active again.',

    'step_up_required' => 'Please confirm your identity to continue.',

    'password_not_set' => 'This account has no password yet. Set one instead of changing it.',

    'provider_unavailable' => 'The sign-in provider could not be reached. Please try again later.',

];
