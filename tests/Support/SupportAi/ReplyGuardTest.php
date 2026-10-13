<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use FlutterSdk\MagicStarter\Support\SupportAi\ReplyGuard;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Locks the last gate before an AI reply is persisted: what is never allowed
 * to reach a customer, whatever the model wrote.
 */
class ReplyGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://app.example.test');
        config()->set('magic-starter.support.ai.allowed_hosts', [
            'docs.example.test',
        ]);
        config()->set('magic-starter.support.ai.reply_max_length', 300);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedReplies(): array
    {
        return [
            'plain steps' => [
                'Open Monitors, tap Add and paste the URL you want to watch.',
            ],
            'a link to the app host' => [
                'You can manage it at https://app.example.test/settings/billing.',
            ],
            'a link to the app host in capitals, in markdown' => [
                'See [billing](HTTPS://APP.EXAMPLE.TEST/billing) for details.',
            ],
            'a link to an allowed host' => [
                'The guide is at https://docs.example.test/monitors (it is short).',
            ],
            'a bare allowed host with a path' => [
                'Read docs.example.test/monitors first.',
            ],
            'advice about passwords' => [
                'Never share your password with anyone, including us.',
            ],
            'turkish advice about passwords' => [
                'Şifrenizi kimseyle paylaşmayın.',
            ],
            'a refusal to act' => [
                'I cannot cancel plans myself, a teammate will do it.',
            ],
            'a negated action' => [
                'I have not cancelled anything yet.',
            ],
            'an abbreviation is not a link' => [
                'Use an interval such as 1 min, e.g. for a checkout page.',
            ],
            'an error code is not a credential' => [
                'The error code in the log tells us which check failed.',
            ],
            'the customer did the action' => [
                'If you changed the interval, the next check uses it.',
            ],
            'a version number is not a link' => [
                'This works since version 1.2 of the agent.',
            ],
            'a file name is not a link' => [
                'Open config.php and set the interval there.',
            ],
        ];
    }

    #[DataProvider('acceptedReplies')]
    public function test_a_safe_reply_passes(string $reply): void
    {
        $this->assertNull((new ReplyGuard)->check($this->answer($reply)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedReplies(): array
    {
        return [
            'completed cancel' => [
                'I cancelled your plan.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'completed cancel, american spelling and apostrophe' => [
                'I’ve just canceled your subscription for you.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'completed refund' => [
                'I have refunded the last payment.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'completed deletion by we' => [
                'We deleted your account as asked.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'completed change' => [
                'I already changed your plan to Pro.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'passive claim about the customer plan' => [
                'Your subscription has been cancelled.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish cancel' => [
                'Aboneliğinizi iptal ettim.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish cancel in capitals, dotless I' => [
                'ABONELİĞİNİZİ IPTAL ETTİM.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish refund' => [
                'Ödemenizi iade ettim.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish deletion' => [
                'Hesabınızı sildim.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish change' => [
                'Planınızı değiştirdim.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish formal past cancel' => [
                'Aboneliğiniz iptal edilmiştir.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish formal past refund in capitals' => [
                'İADENİZ YAPILMIŞTIR.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'turkish formal past deletion' => [
                'Hesabınız silinmiştir.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'a passive claim about the subscription' => [
                'Done! The subscription is cancelled.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'went ahead and' => [
                'I went ahead and refunded the charge.',
                ReplyGuard::ACTION_CLAIM,
            ],
            'foreign host' => [
                'Please sign in at https://evil.example/x to continue.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'foreign host in markdown' => [
                'See [billing](https://evil.example/billing).',
                ReplyGuard::FOREIGN_LINK,
            ],
            'userinfo trick' => [
                'Open https://app.example.test@evil.example/login now.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'lookalike suffix' => [
                'Open https://app.example.test.evil.example/login now.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'subdomain of an allowed host is not allowed' => [
                'Open https://evil.docs.example.test/page.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'scheme-less www' => [
                'Go to www.evil.example/login.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'scheme-less host with a path' => [
                'Go to evil.example/x for the fix.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'protocol-relative' => [
                'Go to //evil.example/x for the fix.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'javascript scheme' => [
                'Click [here](javascript:alert(1)).',
                ReplyGuard::FOREIGN_LINK,
            ],
            'ip address' => [
                'Open http://203.0.113.9/login.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'a foreign mail address' => [
                'Write to billing@evil.com for that.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'a bare host on an unlisted top-level domain' => [
                'Please visit evil.zip to restore access.',
                ReplyGuard::FOREIGN_LINK,
            ],
            'password ask' => [
                'send me your password',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'password ask, polite' => [
                'To check, please share your current password with me.',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'password question' => [
                'What is your password?',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'verification code ask' => [
                'Please reply with the verification code we sent you.',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'card number ask' => [
                'Can you type your card number here?',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'api key ask' => [
                'Paste your API key and I will look.',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'turkish password ask' => [
                'Şifrenizi bize gönderin.',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'turkish password ask in capitals' => [
                'ŞİFRENİZİ BANA YAZIN',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'turkish card ask' => [
                'Kart numaranızı paylaşır mısınız?',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'a negation in the sentence before the ask' => [
                "Don't worry about it. Please send me your password so I can check.",
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'a never in the sentence before the ask' => [
                'We never share data. Send me your password.',
                ReplyGuard::CREDENTIAL_REQUEST,
            ],
            'over the length cap' => [
                str_repeat('a', 301),
                ReplyGuard::TOO_LONG,
            ],
        ];
    }

    #[DataProvider('rejectedReplies')]
    public function test_an_unsafe_reply_is_rejected_with_its_code(string $reply, string $code): void
    {
        $this->assertSame($code, (new ReplyGuard)->check($this->answer($reply)));
    }

    public function test_a_reply_exactly_at_the_length_cap_passes(): void
    {
        $this->assertNull((new ReplyGuard)->check($this->answer(str_repeat('a', 300))));
    }

    public function test_the_length_cap_counts_characters_not_bytes(): void
    {
        $this->assertNull((new ReplyGuard)->check($this->answer(str_repeat('ş', 300))));
    }

    public function test_a_link_to_the_app_host_is_rejected_when_the_app_url_is_unset(): void
    {
        config()->set('app.url', null);

        $this->assertSame(
            ReplyGuard::FOREIGN_LINK,
            (new ReplyGuard)->check($this->answer('Open https://app.example.test/billing.')),
        );
    }

    public function test_an_empty_reply_is_rejected_even_when_the_value_object_was_bypassed(): void
    {
        $this->assertSame(ReplyGuard::EMPTY_REPLY, (new ReplyGuard)->check($this->unchecked('   ', 0.5)));
    }

    /**
     * @return array<string, array{float}>
     */
    public static function outOfRangeConfidences(): array
    {
        return [
            'below zero' => [
                -0.1,
            ],
            'above one' => [
                1.1,
            ],
            'not a number' => [
                NAN,
            ],
        ];
    }

    #[DataProvider('outOfRangeConfidences')]
    public function test_a_confidence_outside_zero_to_one_is_rejected_even_when_the_value_object_was_bypassed(
        float $confidence,
    ): void {
        $this->assertSame(
            ReplyGuard::CONFIDENCE_RANGE,
            (new ReplyGuard)->check($this->unchecked('Fine.', $confidence)),
        );
    }

    private function answer(string $reply): SupportAnswer
    {
        return new SupportAnswer(
            reply: $reply,
            confidence: 0.9,
            usage: new SupportUsage(promptTokens: 10, completionTokens: 10),
        );
    }

    /**
     * An answer that skipped the constructor, the only way to reach the guard's own range checks.
     */
    private function unchecked(string $reply, float $confidence): SupportAnswer
    {
        $answer = (new ReflectionClass(SupportAnswer::class))->newInstanceWithoutConstructor();
        $usage = new SupportUsage(promptTokens: 1, completionTokens: 1);

        (function () use ($reply, $confidence, $usage): void {
            $this->reply = $reply;
            $this->confidence = $confidence;
            $this->usage = $usage;
            $this->handoffReason = null;
            $this->handoffPriority = null;
        })->bindTo($answer, SupportAnswer::class)();

        return $answer;
    }
}
