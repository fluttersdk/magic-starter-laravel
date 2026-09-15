<?php

return [

    /*
     * The account-owner surfaces that are not authentication and not teams:
     * the password change, the avatar upload and the newsletter toggle.
     *
     * Small on purpose. Everything a person does to their own credentials lives
     * in `auth.php` beside the sentences the login endpoints raise, because a
     * reader translating "the current password is incorrect" wants the other
     * password sentences in front of them, not two files away.
     */
    'password_updated' => 'Password updated successfully.',

    /*
     * The size ceiling is stated in the sentence rather than interpolated,
     * because the rule that raises it is a literal `max:1024` in
     * UpdateProfilePhotoRequest. If that number ever moves, this line moves
     * with it, in both locales.
     */
    'photo' => [
        'max' => 'The photo may not be greater than 1MB.',
    ],

    /*
     * The newsletter subscription is keyed to an email address, so a guest or a
     * phone-only account has nothing to subscribe. That is a 400 rather than a
     * validation error: the request is well formed, the account is not eligible.
     */
    'newsletter' => [
        'email_required' => 'Email address required for newsletter subscription.',
    ],

];
