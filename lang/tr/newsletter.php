<?php

return [

    /*
     * The confirmation a person gets after subscribing. Package copy only: a mail line is Markdown.
     */
    'mail' => [
        'subject' => 'Bültene abone oldunuz',
        'intro' => 'Ürün haberlerine abone oldunuz; haberleri bu adrese göndereceğiz.',
        'consented_on' => ':date tarihinde onay verdiniz.',
        'unsubscribe_action' => 'Abonelikten çıkın',
        'outro' => 'İstediğiniz zaman ayrılabilirsiniz: yukarıdaki düğme ve posta uygulamanızdaki abonelikten çıkma '
            . 'seçeneği tek tıkla çalışır.',
    ],

    /*
     * The public unsubscribe page, opened from a mail link without signing in.
     */
    'page' => [
        'title' => 'Bülten',
        'confirm_heading' => 'Bültenden çıkmak istiyor musunuz?',
        'confirm_body' => 'Bu adrese ürün haberleri göndermeyeceğiz. Dilediğiniz zaman uygulamadan yeniden abone '
            . 'olabilirsiniz.',
        'confirm_button' => 'Abonelikten çıkın',
        'done_heading' => 'Abonelikten çıktınız',
        'done_body' => 'Artık sizden ürün haberleri almayacaksınız.',
        'not_found_heading' => 'Bağlantı bulunamadı',
        'not_found_body' => 'Bu abonelikten çıkma bağlantısı geçerli değil.',
    ],

];
