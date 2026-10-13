<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

/**
 * The last gate before an AI reply is saved for a customer.
 *
 * A model can write anything, and the desk cannot let it claim an account
 * change nobody made, send the customer to a stranger's site, or ask for a
 * secret in a chat thread. Each rule here is a hard no with a fixed failure
 * code, not a score: the caller counts a rejection as a failed turn and never
 * posts the reply.
 *
 * Text rules run on {@see HandoffClassifier::fold()}, so the Turkish casing
 * and spelling variants of a phrase are one phrase. The rules are blunt on
 * purpose (a refused safe reply costs a retry or a human answer, a passed
 * unsafe one costs trust), and the Turkish and English tables cover the same
 * three claims: a completed action, a foreign link, a credential request.
 *
 * Links: every web link, bare or scheme-less address and mail address, on any
 * top-level domain of two letters or more, must have a host that equals
 * `app.url`'s host or is listed in `support.ai.allowed_hosts`, compared exactly
 * (a subdomain is a different host). Only a name ending in a file extension
 * that is no top-level domain (`config.php`) is read as a file; a version such
 * as `1.2` never looks like a host.
 *
 * A credential ask is advice rather than a request only when a negation sits
 * in the same sentence.
 */
final class ReplyGuard
{
    /** The reply is blank. */
    public const EMPTY_REPLY = 'empty_reply';

    /** The reply is longer than `support.ai.reply_max_length` characters. */
    public const TOO_LONG = 'too_long';

    /** The reply says the AI already cancelled, refunded, deleted or changed something. */
    public const ACTION_CLAIM = 'action_claim';

    /** The reply links to a host that is neither the app's nor allow-listed. */
    public const FOREIGN_LINK = 'foreign_link';

    /** The reply asks the customer for a password, a code or a card number. */
    public const CREDENTIAL_REQUEST = 'credential_request';

    /** The answer's confidence is outside 0 to 1. */
    public const CONFIDENCE_RANGE = 'confidence_range';

    private const ACTION_VERBS = 'cancell?ed|refunded|deleted|removed|changed|upgraded|downgraded|reset|disabled'
        . '|closed';

    private const ACTION_ADVERBS = '(?:(?:just|already|now|also|successfully|(?:gone|went)\s+ahead\s+and)\s+)*';

    /**
     * The Turkish completed forms of the action stems, plain past (`edildi`) and formal past (`edilmiştir`).
     */
    private const ACTION_PARTICIPLES_TR = '(?:iptal|iade)\s+edil(?:di|mis(?:tir)?)|iade\s+yapil(?:di|mis(?:tir)?)'
        . '|silin(?:di|mis(?:tir)?)|degistiril(?:di|mis(?:tir)?)|sifirlan(?:di|mis(?:tir)?)'
        . '|kaldiril(?:di|mis(?:tir)?)';

    /**
     * File extensions that are not top-level domains, so `config.php` is a file name rather than a host.
     */
    private const FILE_EXTENSIONS = [
        'php',
        'html',
        'htm',
        'js',
        'json',
        'yaml',
        'yml',
        'xml',
        'txt',
        'csv',
        'log',
        'ini',
        'env',
        'conf',
        'dart',
    ];

    private const ASK_VERBS_EN = 'send|share|give|provide|tell|type|enter|paste|forward|submit|input|read\s+out'
        . '|reply\s+with|respond\s+with|what\s+is|what\'?s|whats|what\s+are';

    private const ASK_VERBS_TR = 'gonder|paylas|yaz|verin|veriniz|verir|bildir|soyle|girin|giriniz|ilet|belirt'
        . '|lazim|gerek';

    private const SECRET_NOUNS_EN = 'passwords?|passcode|passphrase|pin|otp|cvv|cvc|(?:seed|recovery)\s+phrase'
        . '|(?:verification|security|login|log-in|sign-in|signin|sms|2fa|two-factor|authenticat\w*|confirmation'
        . '|recovery|backup|one-time|activation|reset)\s+(?:code|number)'
        . '|code\s+(?:that\s+)?(?:we|i)\s+(?:sent|texted|emailed|mailed)'
        . '|(?:credit\s+|debit\s+|payment\s+|bank\s+)?card\s+(?:number|details|info|information|digits)'
        . '|(?:api|secret|private|access|bearer)\s+(?:key|token)';

    private const SECRET_NOUNS_TR = 'sifre|parola|(?:dogrulama|guvenlik|sms|onay|giris|tek\s+kullanimlik)\s+kod'
        . '|kart\s+(?:numara|bilgi)|kredi\s+kart|(?:api|gizli)\s+anahtar';

    private const NEGATION = '/\b(?:never|not|no\s+need|without|don\'?t|doesn\'?t|won\'?t|shouldn\'?t|asla|sakin'
        . '|kimseyle|kimseye)\b|(?:ma|me)(?:yin|yiniz)\b/';

    /**
     * The code of the first rule the answer breaks, or null when it may be posted.
     */
    public function check(SupportAnswer $answer): ?string
    {
        $reply = $answer->reply;
        $folded = HandoffClassifier::fold($reply);

        return match (true) {
            $this->confidenceOutOfRange($answer->confidence) => self::CONFIDENCE_RANGE,
            trim($reply) === '' => self::EMPTY_REPLY,
            $this->exceedsLength($reply) => self::TOO_LONG,
            $this->claimsCompletedAction($folded) => self::ACTION_CLAIM,
            $this->linksToForeignHost($reply) => self::FOREIGN_LINK,
            $this->asksForSecret($folded) => self::CREDENTIAL_REQUEST,
            default => null,
        };
    }

    private function confidenceOutOfRange(float $confidence): bool
    {
        return is_nan($confidence) || $confidence < 0.0 || $confidence > 1.0;
    }

    /**
     * A non-positive cap turns the length check off.
     */
    private function exceedsLength(string $reply): bool
    {
        $max = (int) config('magic-starter.support.ai.reply_max_length', 0);

        return $max > 0 && mb_strlen($reply) > $max;
    }

    /**
     * Whether the reply says, in the first person or about the customer's account, that something was done.
     */
    private function claimsCompletedAction(string $folded): bool
    {
        $verbs = self::ACTION_VERBS;
        $adverbs = self::ACTION_ADVERBS;
        $patterns = [
            // "I cancelled", "we've already refunded".
            '/\b(?:i|we)(?:\'ve|\s+have|\s+had)?\s+' . $adverbs . '(?:' . $verbs . ')\b/',
            // "I have issued a refund".
            '/\b(?:i|we)(?:\'ve|\s+have)?\s+' . $adverbs
                . '(?:issued|processed|initiated|started)\s+(?:\w+\s+){0,2}(?:refund|cancell?ation|deletion)/',
            // "Your subscription has been cancelled", "The subscription is cancelled".
            '/\b(?:your|the)\s+(?:\w+\s+){0,3}?(?:(?:has|have)\s+been|was|were|got|(?:is|are)(?:\s+now)?)\s+'
                . '(?:\w+ly\s+)?(?:' . $verbs . ')\b/',
            // "iptal ettim", "iade edildi", "iadeniz yapılmıştır".
            '/\b(?:iptal|iade)\w*\s+(?:ettim|ettik|edildi|edilmis(?:tir)?|yapildi|yapilmis(?:tir)?|yaptim'
                . '|yaptik)\b/',
            // "sildim", "değiştirdim".
            '/\b(?:sildim|sildik|degistirdim|degistirdik|kaldirdim|kaldirdik|sifirladim|sifirladik|yukselttim'
                . '|dusurdum)\b/',
            // "Hesabınız silindi", "Aboneliğiniz iptal edilmiştir".
            '/\b(?:hesabiniz|aboneliginiz|planiniz|paketiniz|odemeniz|kaydiniz)\s+(?:\w+\s+){0,2}'
                . '(?:' . self::ACTION_PARTICIPLES_TR . ')\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern . 'u', $folded) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any link or address in the reply points outside the allowed hosts.
     */
    private function linksToForeignHost(string $reply): bool
    {
        $allowed = $this->allowedHosts();

        // 1. Schemed links are parsed, so `app.test@evil.test` is read as evil.test.
        preg_match_all('~(?:https?|ftp)://[^\s<>"\')\]]+~i', $reply, $matches);

        foreach ($matches[0] as $url) {
            $host = parse_url(rtrim($url, '.,;:!?'), PHP_URL_HOST);

            if (! is_string($host) || ! in_array($this->normaliseHost($host), $allowed, true)) {
                return true;
            }
        }

        $rest = preg_replace('~(?:https?|ftp)://[^\s<>"\')\]]+~i', ' ', $reply) ?? $reply;

        // 2. Script schemes have no host to allow.
        if (preg_match('~\b(?:javascript|vbscript):~i', $rest) === 1) {
            return true;
        }

        // 3. Protocol-relative links and bare addresses.
        preg_match_all('~(?<![:\w/])//([a-z0-9.-]+)~i', $rest, $relative);

        foreach ($relative[1] as $host) {
            if (! in_array($this->normaliseHost($host), $allowed, true)) {
                return true;
            }
        }

        preg_match_all(
            '~(?<![\w.-])((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+([a-z]{2,24}))(?![\w-])~i',
            $rest,
            $bare,
            PREG_SET_ORDER,
        );

        // 4. Any other `label.tld` is a host a customer can type in, whatever its top-level domain.
        foreach ($bare as $match) {
            $host = $this->normaliseHost($match[1]);
            $isFileName = in_array(strtolower($match[2]), self::FILE_EXTENSIONS, true);

            if (! $isFileName && ! in_array($host, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hosts a reply may link to: the app's own and `support.ai.allowed_hosts`, lowercase.
     *
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $entries = (array) config('magic-starter.support.ai.allowed_hosts', []);
        $entries[] = config('app.url');
        $hosts = [];

        foreach ($entries as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            $entry = trim($entry);
            $host = str_contains($entry, '://') ? parse_url($entry, PHP_URL_HOST) : $entry;

            if (is_string($host) && $host !== '') {
                $hosts[] = $this->normaliseHost($host);
            }
        }

        return $hosts;
    }

    private function normaliseHost(string $host): string
    {
        return strtolower(rtrim($host, '.'));
    }

    /**
     * Whether the reply asks for a password, a code or a card number.
     *
     * A sentence that tells the customer NOT to share one (`never`, `kimseyle`,
     * `paylaşmayın`) is advice, not a request.
     */
    private function asksForSecret(string $folded): bool
    {
        // English words end at a word boundary; Turkish ones are stems that take any suffix.
        $verbs = '\b(?:(?:' . self::ASK_VERBS_EN . ')\b|' . self::ASK_VERBS_TR . ')';
        $nouns = '\b(?:(?:' . self::SECRET_NOUNS_EN . ')\b|' . self::SECRET_NOUNS_TR . ')';
        $window = '[^.?!\n]{0,60}?';
        $pattern = '/' . $verbs . $window . $nouns . '|' . $nouns . $window . $verbs . '/';

        if (preg_match_all($pattern, $folded, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }

        foreach ($matches[0] as [$text, $offset]) {
            if (preg_match(self::NEGATION, $this->sentenceAround($folded, $offset, strlen($text))) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sentence a match sits in, cut at `.`, `?`, `!` or a line break on either side.
     *
     * A negation only turns the ask it shares a sentence with into advice: "We never share data. Send me
     * your password." is still a request.
     *
     * @param  int  $offset  Byte offset of the match in `$folded`.
     * @param  int  $length  Byte length of the match.
     */
    private function sentenceAround(string $folded, int $offset, int $length): string
    {
        $before = substr($folded, 0, $offset);
        $start = preg_match('/.*[.?!\n]/s', $before, $head) === 1 ? strlen($head[0]) : 0;
        $end = strcspn($folded, ".?!\n", $offset + $length) + $offset + $length;

        return substr($folded, $start, $end - $start);
    }
}
