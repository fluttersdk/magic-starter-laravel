<?php

return [

    /*
     * The team invitation mail. A person without an account creates one from the
     * same Accept link, so the mail carries one button and no register button.
     */
    'invitation' => [
        'subject' => 'Takım Daveti',
        'greeting' => 'Merhaba!',
        'invited' => ':team takımına katılmaya davet edildiniz!',
        'no_account' => 'Daveti kabul etmek için aşağıdaki düğmeye tıklayın. '
            . 'Henüz hesabınız yoksa aynı bağlantıdan bir hesap oluşturabilirsiniz.',
        'action' => 'Daveti kabul edin',
        'discard' => 'Bu takıma davet edilmeyi beklemiyorsanız bu e-postayı yok sayabilirsiniz.',
    ],

    /*
     * The email verification mail.
     */
    'verify' => [
        'subject' => 'E-posta Adresinizi Doğrulayın',
        'intro' => 'E-posta adresinizi doğrulamak için lütfen aşağıdaki düğmeye tıklayın.',
        'action' => 'E-postanızı doğrulayın',
    ],

];
