<?php

return [

    /*
     * AI çalıştırma günlüğü: bir uygulamanın kaydettiği her model çağrısı, en
     * yeniden eskiye, salt okunur. Saklama süresi prune komutunun işi.
     */
    'navigation_label' => 'AI çalıştırmaları',
    'model_label' => 'AI çalıştırması',
    'plural_model_label' => 'AI çalıştırmaları',

    'columns' => [
        'started_at' => 'Başlangıç',
        'finished_at' => 'Bitiş',
        'feature' => 'Özellik',
        'source' => 'Prompt kaynağı',
        'status' => 'Durum',
        'team' => 'Ekip',
        'subject' => 'Konu',
        'provider' => 'Sağlayıcı',
        'model' => 'Model',
        'attempts_count' => 'Deneme',
        'input_tokens' => 'Girdi token',
        'output_tokens' => 'Çıktı token',
        'cache_read_tokens' => 'Önbellek okuma token',
        'cache_write_tokens' => 'Önbellek yazma token',
        'reasoning_tokens' => 'Akıl yürütme token',
        'cost_usd' => 'Maliyet (USD)',
        'cost_source' => 'Maliyet kaynağı',
        'duration_ms' => 'Süre (ms)',
        'error_class' => 'Hata sınıfı',
        'prompt_hash' => 'Prompt özeti',
        'source_run_id' => 'Yeniden kullanılan çalıştırma',
    ],

    'statuses' => [
        'running' => 'Çalışıyor',
        'succeeded' => 'Başarılı',
        'failed' => 'Başarısız',
        'cache_hit' => 'Önbellekten',
        'abandoned' => 'Yarım kaldı',
    ],

    'filters' => [
        'feature' => 'Özellik',
        'status' => 'Durum',
        'team' => 'Ekip',
        'started_from' => 'Başlangıç',
        'started_until' => 'Bitiş',
    ],

    'view' => [
        'metadata' => 'Üst veri',
        'bodies' => 'İçerikler',
        'bodies_pruned' => 'İçerikler :date tarihinde temizlendi.',
        'instructions' => 'Yönergeler',
        'input' => 'Girdi',
        'context' => 'Bağlam',
        'tools' => 'Araçlar',
        'output' => 'Çıktı',
        'structured' => 'Yapılandırılmış çıktı',
        'error_message' => 'Hata',
        'attempts' => 'Denemeler',
    ],

    'attempts' => [
        'attempt' => '#',
        'http_status' => 'HTTP durumu',
        'tool_calls' => 'Araç çağrıları',
        'error' => 'Hata',
    ],

    'widget' => [
        'runs' => 'AI çalıştırmaları (son :days gün)',
        'failed' => 'Başarısız payı (son :days gün)',
        'cost' => 'AI maliyeti (son :days gün)',
        'cache_hit_rate' => 'Önbellek isabet oranı (son :days gün)',
    ],

    /*
     * Bir prompt geçersiz kılma kaydedilirken çıkan dört ret; her biri gövde
     * ya da anahtar alanına bağlanır. `missing_phrases` gövdenin düşürdüğü her
     * ifadeyi sayar, çünkü uygulamanın ayrıştırıcısı ya da enjeksiyon çiti
     * onlara dayanır.
     */
    'refusals' => [
        'unknown_key' => '[:key] adıyla kayıtlı bir prompt yok.',
        'blank_body' => 'Prompt gövdesi boş olamaz.',
        'default_changed' => 'Bu promptun koddaki varsayılanı siz düzenlerken değişti. '
            . 'Yeniden yükleyip yeni varsayılanı gözden geçirin, sonra kaydedin.',
        'missing_phrases' => 'Gövde şu ifadeleri korumalıdır: :phrases.',
    ],

    /*
     * Prompt tezgahı: personel, uygulamanın kodda kaydettiği bir promptun gövdesini değiştirir.
     * `stale` koddaki varsayılanın düzenlemeden sonra değiştiğini, `orphaned` uygulamanın
     * anahtarı artık kaydetmediğini gösterir.
     */
    'prompts' => [
        'navigation_label' => 'AI promptları',
        'model_label' => 'AI promptu',
        'plural_model_label' => 'AI promptları',

        'columns' => [
            'key' => 'Anahtar',
            'label' => 'Prompt',
            'status' => 'Durum',
            'updated_at' => 'Güncellendi',
        ],

        'statuses' => [
            'current' => 'Güncel',
            'stale' => 'Eskimiş',
            'orphaned' => 'Sahipsiz',
        ],

        'fields' => [
            'key' => 'Prompt',
            'body' => 'Gövde',
            'diff' => 'Koddaki varsayılana göre değişiklikler',
            'reviewed' => 'Yeni koddaki varsayılana göre farkı gözden geçirdim',
            'default_body' => 'Koddaki varsayılan',
            'boundary' => 'Sınır bloğu (her zaman eklenir, düzenlenemez)',
        ],

        'diff' => [
            'from' => 'koddaki varsayılan',
            'to' => 'geçersiz kılma',
            'identical' => 'Gövde koddaki varsayılanla aynı.',
        ],

        'actions' => [
            'create' => 'Bir promptu geçersiz kıl',
            'download' => 'Gövdeyi indir',
            'promoted' => [
                'label' => 'Koda taşındı, sil',
                'heading' => 'Bu geçersiz kılma silinsin mi?',
                'description' => 'Gövde koddaki varsayılana taşındığında kullanın. Prompt, bir sonraki çağrıdan '
                    . 'itibaren yine koddaki varsayılanıyla yanıt verir.',
                'success' => 'Geçersiz kılma silindi.',
            ],
        ],
    ],

];
