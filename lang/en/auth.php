<?php

return [

    /*
     * Outcome sentences the session endpoints answer with.
     *
     * They travel in the `message` key of an otherwise machine-readable body,
     * so a client that renders them renders them verbatim. That is why they
     * live here rather than inline: the package cannot know the language its
     * consumer displays, and a literal in the controller is English frozen into
     * every app that installs it.
     *
     * `invalid_credentials` deliberately names neither the identifier nor the
     * password as the wrong one. Telling a caller which half failed turns the
     * login endpoint into an account-existence oracle, and the refusal is the
     * same 401 either way.
     */
    'login_successful' => 'Login successful',
    'registration_successful' => 'Registration successful',
    'guest_session_started' => 'Guest session started',
    'logged_out' => 'Logged out successfully',
    'invalid_credentials' => 'Invalid credentials',

    /*
     * Social login fails as one sentence for every cause, because every cause
     * reaches it through the same `Throwable`: a rejected token, an unknown
     * provider, a provider that timed out. The detail is attached separately
     * under `app.debug` and is not translated, since it is the provider's own
     * text and a developer is its only reader.
     */
    'invalid_social_token' => 'Invalid token or provider',

    /*
     * The phone one-time-code pair. `user_not_found` is reached only AFTER the
     * code verified, so it is not an enumeration leak: it tells somebody who
     * proved they hold the number that no account carries it.
     */
    'otp' => [
        'sent' => 'OTP sent successfully',
        'invalid' => 'Invalid or expired OTP',
        'user_not_found' => 'User not found',
    ],

    /*
     * Password sentences, and the three refusals are deliberately three rather
     * than one. `incorrect` answers a deletion, `current_incorrect` answers a
     * change, and `confirmation_mismatch` answers a re-confirmation before a
     * sensitive action; a shared sentence would read as the wrong accusation in
     * two of the three places.
     *
     * `reset_link_sent` is the same sentence whether or not the address exists,
     * which is the whole point of it. It is worded so that it stays true in
     * both cases rather than claiming a mail was sent.
     */
    'password' => [
        'required' => 'Password is required.',
        'min' => 'The password must be at least 8 characters.',
        'incorrect' => 'The password is incorrect.',
        'current_incorrect' => 'The current password is incorrect.',
        'confirmation_mismatch' => 'The provided password does not match your current password.',
        'reset_link_sent' => 'If an account with that email exists, a password reset link has been sent.',
    ],

    /*
     * The one phone-format refusal, worded for somebody who typed a local
     * number rather than an international one, which is the mistake it
     * actually catches. `:attribute` is filled by the validator AFTER this line
     * resolves, so the placeholder survives translation and must stay in every
     * locale.
     */
    'phone' => [
        'e164' => 'The :attribute must be a valid E.164 international phone number (e.g., +14155552671).',
    ],

    /*
     * Email verification. `already_verified` answers 200 from both the resend
     * and the verify endpoint, so the one sentence has to read sensibly to
     * somebody who just clicked a link and to somebody who just asked for one.
     */
    'verification' => [
        'already_verified' => 'Email already verified.',
        'no_email' => 'No email address to verify.',
        'link_sent' => 'Verification link sent.',
        'invalid_link' => 'Invalid verification link.',
        'verified' => 'Email verified successfully.',
    ],

    /*
     * Two-factor authentication.
     *
     * `not_enabled` and `not_yet_enabled` say the same thing in two voices and
     * are kept apart on purpose: the first answers a request for recovery codes
     * on an account that never turned 2FA on, the second answers a confirmation
     * attempt against a secret that was never issued. They reach the client
     * from different endpoints with different next steps.
     *
     * `invalid_code` is the confirmation path, `invalid_challenge_code` the
     * login challenge. Same fact, but only one of them is somebody locked out
     * of their account, and that reader deserves a sentence that names what
     * they were doing.
     */
    'two_factor' => [
        'enabled' => 'Two-factor authentication enabled. Please confirm with your authenticator app.',
        'confirmed' => 'Two-factor authentication confirmed successfully.',
        'disabled' => 'Two-factor authentication has been disabled.',
        'not_enabled' => 'Two-factor authentication is not enabled.',
        'not_yet_enabled' => 'Two-factor authentication has not been enabled.',
        'invalid_token' => 'Invalid two-factor authentication token.',
        'expired_token' => 'Two-factor authentication token has expired.',
        'invalid_code' => 'Invalid code.',
        'invalid_challenge_code' => 'The provided two-factor authentication code was invalid.',
        'invalid_recovery_code' => 'The provided two-factor authentication recovery code was invalid.',
        'recovery_codes_retrieved' => 'Recovery codes retrieved successfully.',
        'recovery_codes_regenerated' => 'Recovery codes regenerated successfully.',
    ],

    /*
     * Token sessions. A "session" here is a Sanctum personal access token
     * carrying its own device info, so `not_found` is a 404 on a token id and
     * not a signed-out browser.
     */
    'sessions' => [
        'not_found' => 'Session not found.',
        'revoked' => 'Session revoked successfully.',
        'others_revoked' => 'Other sessions revoked successfully.',
    ],

];
