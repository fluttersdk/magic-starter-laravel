<?php

return [

    /*
     * The account-owner surfaces that are not authentication and not teams:
     * the password change, the avatar upload and the newsletter toggle.
     *
     * Everything a person does to their own credentials lives in `auth.php`
     * instead, beside the sentences the login endpoints raise.
     */
    'password_updated' => 'Şifreniz güncellendi.',

    /*
     * The size ceiling is stated in the sentence rather than interpolated,
     * because the rule that raises it is a literal `max:1024`. If that number
     * moves, this line moves with it, in both locales.
     */
    'photo' => [
        'max' => 'Fotoğraf 1 MB boyutunu aşamaz.',
    ],

    /*
     * The newsletter subscription is keyed to an email address, so a guest or a
     * phone-only account has nothing to subscribe.
     */
    'newsletter' => [
        'email_required' => 'Bültene abone olmak için bir e-posta adresi gerekli.',
    ],

];
