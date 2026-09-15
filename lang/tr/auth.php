<?php

return [

    /*
     * Outcome sentences the session endpoints answer with. See the English file
     * for why they are shipped rather than inlined.
     *
     * `invalid_credentials` names neither the identifier nor the password as
     * the wrong one, so that the login endpoint does not become an
     * account-existence oracle.
     */
    'login_successful' => 'Giriş başarılı',
    'registration_successful' => 'Kayıt başarılı',
    'guest_session_started' => 'Misafir oturumu başlatıldı',
    'logged_out' => 'Çıkış yapıldı',
    'invalid_credentials' => 'Giriş bilgileri hatalı',

    /*
     * Social login fails as one sentence for every cause, because every cause
     * reaches it through the same `Throwable`. The provider's own detail rides
     * alongside under `app.debug` and stays untranslated.
     */
    'invalid_social_token' => 'Geçersiz erişim anahtarı veya sağlayıcı',

    /*
     * The phone one-time-code pair. `user_not_found` is reached only after the
     * code verified, so it tells somebody who proved they hold the number that
     * no account carries it.
     */
    'otp' => [
        'sent' => 'Doğrulama kodu gönderildi',
        'invalid' => 'Doğrulama kodu geçersiz veya süresi dolmuş',
        'user_not_found' => 'Kullanıcı bulunamadı',
    ],

    /*
     * Password sentences. The three refusals stay three rather than one: one
     * answers a deletion, one a change, one a re-confirmation before a
     * sensitive action, and a shared sentence would read as the wrong
     * accusation in two of the three places.
     *
     * `reset_link_sent` reads the same whether or not the address exists, which
     * is the point of it: it states the condition instead of claiming a mail
     * was sent.
     *
     * "Şifre" rather than "parola" throughout, matching the word the shipped
     * client puts on the same screens.
     */
    'password' => [
        'required' => 'Şifre gerekli.',
        'min' => 'Şifre en az 8 karakter olmalıdır.',
        'incorrect' => 'Şifre hatalı.',
        'current_incorrect' => 'Mevcut şifreniz hatalı.',
        'confirmation_mismatch' => 'Girdiğiniz şifre mevcut şifrenizle eşleşmiyor.',
        'reset_link_sent' => 'Bu e-posta adresine ait bir hesap varsa, şifre sıfırlama bağlantısı gönderildi.',
    ],

    /*
     * The one phone-format refusal, worded for somebody who typed a local
     * number rather than an international one. `:attribute` is filled by the
     * validator after this line resolves, so the placeholder has to survive
     * into every locale.
     */
    'phone' => [
        'e164' => ':attribute geçerli bir E.164 uluslararası telefon numarası olmalıdır (örnek: +905321234567).',
    ],

    /*
     * Email verification. `already_verified` answers both the resend and the
     * verify endpoint, so it has to read sensibly to somebody who just clicked
     * a link and to somebody who just asked for one.
     */
    'verification' => [
        'already_verified' => 'E-posta adresi zaten doğrulanmış.',
        'no_email' => 'Doğrulanacak bir e-posta adresi yok.',
        'link_sent' => 'Doğrulama bağlantısı gönderildi.',
        'invalid_link' => 'Doğrulama bağlantısı geçersiz.',
        'verified' => 'E-posta adresiniz doğrulandı.',
    ],

    /*
     * Two-factor authentication. `not_enabled` and `not_yet_enabled` say the
     * same thing in two voices and are kept apart deliberately; see the English
     * file for which endpoint raises which.
     *
     * "İki faktörlü doğrulama" is the phrase the shipped client uses on these
     * screens, so the API answers in the same words rather than introducing a
     * second name for one feature.
     */
    'two_factor' => [
        'enabled' => 'İki faktörlü doğrulama etkinleştirildi. Lütfen kimlik doğrulama uygulamanızla onaylayın.',
        'confirmed' => 'İki faktörlü doğrulama onaylandı.',
        'disabled' => 'İki faktörlü doğrulama devre dışı bırakıldı.',
        'not_enabled' => 'İki faktörlü doğrulama etkin değil.',
        'not_yet_enabled' => 'İki faktörlü doğrulama henüz etkinleştirilmemiş.',
        'invalid_token' => 'İki faktörlü doğrulama anahtarı geçersiz.',
        'expired_token' => 'İki faktörlü doğrulama anahtarının süresi doldu.',
        'invalid_code' => 'Kod geçersiz.',
        'invalid_challenge_code' => 'Girdiğiniz iki faktörlü doğrulama kodu geçersiz.',
        'invalid_recovery_code' => 'Girdiğiniz iki faktörlü doğrulama kurtarma kodu geçersiz.',
        'recovery_codes_retrieved' => 'Kurtarma kodları getirildi.',
        'recovery_codes_regenerated' => 'Kurtarma kodları yeniden oluşturuldu.',
    ],

    /*
     * Token sessions. A session here is a Sanctum access token carrying its own
     * device info, so `not_found` is a missing token rather than a signed-out
     * browser.
     */
    'sessions' => [
        'not_found' => 'Oturum bulunamadı.',
        'revoked' => 'Oturum sonlandırıldı.',
        'others_revoked' => 'Diğer oturumlar sonlandırıldı.',
    ],

];
