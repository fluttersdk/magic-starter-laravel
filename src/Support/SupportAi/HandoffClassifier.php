<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportPriority;

/**
 * The deterministic pre-classifier: decides, before any model is called, that a
 * customer message belongs to a person.
 *
 * It matches a Turkish and English phrase table against a folded form of the
 * text (see {@see self::fold()}), so `İADE`, `IADE`, `iade` and `ıade` are one
 * word and a Turkish keyboard or caps lock changes nothing. A phrase matches
 * from a word start and may carry any suffix, because Turkish inflects at the
 * end of a word (`iade` finds `iadesini`). When several reasons match, the most
 * serious wins: security, outage, billing, anger, then a plain request for a
 * person.
 *
 * Hosts extend the table, never replace it, with
 * `support.ai.handoff_terms`: a map of {@see SupportHandoffReason} value to a
 * list of extra phrases, folded the same way, for the reasons this table
 * decides (`security`, `outage`, `billing`, `anger`, `human_requested`); any
 * other key is ignored. A phrase ending in `$` matches only up to a word end.
 * The application's own name (`app.name`) being down or crashed (`çöktü`) is
 * an outage too.
 *
 * It over-matches on purpose. A false positive costs one human answer; a false
 * negative lets the AI field a refund, a breach or an outage.
 */
final class HandoffClassifier
{
    /**
     * Phrases per reason, already in folded form (lowercase, ASCII Turkish letters), most serious reason first.
     *
     * Security and outage entries are claims about the customer's account or
     * this service, not topics: "how do I get notified about an outage" is a
     * question for the AI. A trailing `$` ends a phrase at a word end, so
     * `hacked$` does not find `hackathon`.
     *
     * @var array<string, list<string>>
     */
    private const TERMS = [
        'security' => [
            'hacked$',
            'hacker$',
            'hackers$',
            'hackle',
            'compromised$',
            'breach$',
            'breached$',
            'stolen account',
            'someone logged in',
            'unauthorized access',
            'unauthorised access',
            'phishing$',
            'reset my password',
            'reset password',
            'password reset',
            'change my password',
            'delete my account',
            'delete account',
            'close my account',
            'locked out',
            'lost access',
            'sifre sifirla',
            'sifremi sifirla',
            'sifreyi sifirla',
            'parola sifirla',
            'parolami sifirla',
            'hesabimi sil',
            'hesabi sil',
            'hesap sil',
            'hesabimi kapat',
            'hesabim calindi',
            'hesabima izinsiz',
            'dolandir',
        ],
        'outage' => [
            'outage on your end',
            'outage on your side',
            'you have an outage',
            'you are having an outage',
            'are you having an outage',
            'site down for me',
            'site is down for me',
            'is down for me',
            'down for everyone',
            'your service is down',
            'your platform is down',
            'your site is down',
            'your app is down',
            'your servers are down',
            'the platform is down',
            'the service is down',
            'you are down',
            'everything is down',
            'nothing is working',
            'lost all my data',
            'lost my data',
            'data loss',
            'data is gone',
            'data disappeared',
            'my data is missing',
            'all my monitors are gone',
            'servis coktu',
            'sistem coktu',
            'platform coktu',
            'hizmet verilmiyor',
            'kesinti var',
            'kesinti yasaniyor',
            'kesinti yasiyor',
            'hicbir sey calismiyor',
            'veri kaybi',
            'verilerim kayb',
            'verilerim gitti',
            'verilerim silindi',
            'tum verilerim',
            'monitorlerim kayb',
            'monitorlerim silindi',
        ],
        'billing' => [
            'refund',
            'money back',
            'chargeback',
            'charged twice',
            'charged me twice',
            'double charge',
            'overcharg',
            'wrongly charged',
            'unauthorized charge',
            'unexpected charge',
            'dispute',
            'cancel',
            'downgrade',
            'change my plan',
            'change plan',
            'switch my plan',
            'switch plan',
            'iade',
            'iptal',
            'cift cekim',
            'yanlis ucret',
            'haksiz ucret',
            'faturaya itiraz',
            'faturama itiraz',
            'fatura itiraz',
            'plan degis',
            'plani degis',
            'planimi degis',
            'paket degis',
            'paketimi degis',
        ],
        'anger' => [
            'unacceptable',
            'ridiculous',
            'furious',
            'outrageous',
            'terrible service',
            'worst service',
            'useless',
            'incompetent',
            'disgusting',
            'pathetic',
            'fed up',
            'sick of',
            'legal action',
            'lawyer',
            'fuck',
            'shit',
            'wtf',
            'rezalet',
            'rezil',
            'berbat',
            'kabul edilemez',
            'sacma',
            'igrenc',
            'ofkeli',
            'cileden',
            'avukat',
            'mahkeme',
            'sikayet',
            'gerizekali',
            'siktir',
            'amk',
        ],
        'human_requested' => [
            'a human',
            'human being',
            'human agent',
            'human support',
            'real person',
            'real human',
            'actual person',
            'actual human',
            'talk to a person',
            'talk with a person',
            'speak to a person',
            'speak with a person',
            'talk to someone',
            'speak to someone',
            'speak with someone',
            'live agent',
            'live chat',
            'representative',
            'customer service',
            'bir insan',
            'insanla',
            'insan ile',
            'gercek biri',
            'gercek bir kisi',
            'canli destek',
            'musteri hizmet',
            'temsilci',
            'yetkili biri',
            'biriyle konus',
            'biri ile konus',
            'biriyle gorus',
        ],
    ];

    /**
     * The priority staff pick each reason up at.
     *
     * @var array<string, SupportPriority>
     */
    private const PRIORITIES = [
        'security' => SupportPriority::URGENT,
        'outage' => SupportPriority::URGENT,
        'billing' => SupportPriority::HIGH,
        'anger' => SupportPriority::HIGH,
        'human_requested' => SupportPriority::NORMAL,
    ];

    /**
     * Three or more exclamation marks in a row read as shouting.
     */
    private const SHOUTING = '/!{3,}/';

    /**
     * Whether the message must go to a person, and why.
     *
     * @return array{reason: SupportHandoffReason, priority: SupportPriority}|null Null when the AI may answer.
     */
    public function classify(string $text): ?array
    {
        $folded = self::fold($text);

        if ($folded === '') {
            return null;
        }

        $shouting = preg_match(self::SHOUTING, $text) === 1;

        foreach ($this->terms() as $reason => $terms) {
            if ($this->mentions($folded, $terms) || ($shouting && $reason === 'anger')) {
                return [
                    'reason' => SupportHandoffReason::from($reason),
                    'priority' => self::PRIORITIES[$reason],
                ];
            }
        }

        return null;
    }

    /**
     * Reduce text to the one form every spelling of a word shares.
     *
     * Turkish has two letter i's, so a locale-aware lowercase turns `I` into
     * `ı`, and the default one turns `İ` into a two-codepoint `i̇`. `İ` and `ı`
     * become `i` before the lowercase (which maps `I` to `i` in any locale);
     * the Turkish letters then drop to their ASCII base, any combining mark
     * goes, curly apostrophes become straight, and runs of whitespace become
     * one space.
     */
    public static function fold(string $text): string
    {
        // 1. The two Turkish i's to plain i, before a case mapping can split them.
        $text = strtr($text, [
            'İ' => 'i',
            'ı' => 'i',
            '’' => "'",
            '‘' => "'",
            'ʼ' => "'",
        ]);

        // 2. Lowercase, then Turkish letters to their ASCII base.
        $text = strtr(mb_strtolower($text), [
            'ş' => 's',
            'ğ' => 'g',
            'ü' => 'u',
            'ö' => 'o',
            'ç' => 'c',
        ]);

        // 3. Decomposed spellings leave combining marks behind; spacing is not meaning.
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * The shipped table with the host's extra phrases merged in, keyed by reason value.
     *
     * @return array<string, list<string>>
     */
    private function terms(): array
    {
        $terms = self::TERMS;
        $app = self::fold((string) config('app.name', ''));

        if ($app !== '') {
            array_push(
                $terms['outage'],
                $app . ' is down',
                $app . ' coktu',
                $app . ' calismiyor',
            );
        }

        $extra = config('magic-starter.support.ai.handoff_terms', []);

        foreach (is_array($extra) ? $extra : [] as $reason => $phrases) {
            if (! array_key_exists($reason, $terms) || ! is_array($phrases)) {
                continue;
            }

            foreach ($phrases as $phrase) {
                $folded = is_string($phrase) ? self::fold($phrase) : '';

                if ($folded !== '') {
                    $terms[$reason][] = $folded;
                }
            }
        }

        return $terms;
    }

    /**
     * Whether any phrase starts at a word start of the folded text, and ends at a word end when it asks to.
     *
     * @param  list<string>  $terms
     */
    private function mentions(string $folded, array $terms): bool
    {
        $alternatives = implode('|', array_map(
            static fn (string $term): string => str_ends_with($term, '$')
                ? preg_quote(substr($term, 0, -1), '/') . '(?![\p{L}\p{N}])'
                : preg_quote($term, '/'),
            $terms,
        ));

        return preg_match('/(?<![\p{L}\p{N}])(?:' . $alternatives . ')/u', $folded) === 1;
    }
}
