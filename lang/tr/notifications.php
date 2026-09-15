<?php

return [

    /*
     * Outcomes of the read-state writes on the in-app notification list.
     */
    'marked_as_read' => 'Bildirim okundu olarak işaretlendi.',
    'all_marked_as_read' => 'Tüm bildirimler okundu olarak işaretlendi.',
    'deleted' => 'Bildirim silindi.',

    /*
     * The three refusals the preference matrix raises. The reader is an adopter
     * whose registry and whose client have drifted apart, so the offending
     * identifier is the part that matters.
     *
     * `:type` and `:channel` carry the registry's own identifiers, so they stay
     * in their original form; only the sentence around them is translated.
     */
    'preferences' => [
        'unknown_type' => "':type' bildirim türü tanımlı değil.",
        'channel_unavailable' => "':channel' kanalı ':type' türü için kullanılamıyor.",
        'channel_locked' => "':channel' kanalı ':type' türü için kilitli ve değiştirilemez.",
    ],

    /*
     * The four refusals the self-test push endpoint raises, kept apart because
     * each sends the reader somewhere different: a config key that is off, an
     * account that may not ask, a deployment that cannot deliver push at all,
     * and a provider that did not answer.
     */
    'push_test' => [
        'disabled' => 'Test bildirimi ucu bu uygulama için etkin değil.',
        'guest_refused' => 'Misafir hesaplar test bildirimi gönderemez.',
        'not_provisioned' => 'Bu uygulama için anlık bildirim yapılandırılmamış.',
        'provider_unreachable' => 'Bildirim sağlayıcısına ulaşılamadı.',
    ],

];
