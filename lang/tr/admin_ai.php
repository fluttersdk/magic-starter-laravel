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

];
