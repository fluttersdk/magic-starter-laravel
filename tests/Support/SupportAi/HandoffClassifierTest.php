<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Support\SupportAi\HandoffClassifier;
use FlutterSdk\MagicStarter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the deterministic pre-classifier: which customer words go straight to
 * staff, in both languages, whatever the casing, and which questions stay with
 * the AI.
 */
class HandoffClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, SupportHandoffReason}>
     */
    public static function handoffs(): array
    {
        return [
            'dotted capital I, refund' => [
                'İADE istiyorum',
                SupportHandoffReason::BILLING,
            ],
            'plain capital I, english refund' => [
                'I want a refund',
                SupportHandoffReason::BILLING,
            ],
            'all caps turkish refund, undotted I' => [
                'IADE EDİN',
                SupportHandoffReason::BILLING,
            ],
            'lowercase turkish refund' => [
                'param iade edilsin lütfen',
                SupportHandoffReason::BILLING,
            ],
            'turkish cancel with suffix' => [
                'Aboneliğimi iptal etmek istiyorum',
                SupportHandoffReason::BILLING,
            ],
            'english cancel' => [
                'Please cancel my subscription.',
                SupportHandoffReason::BILLING,
            ],
            'english plan change' => [
                'I would like to change my plan',
                SupportHandoffReason::BILLING,
            ],
            'turkish plan change' => [
                'Planımı değiştirmek istiyorum',
                SupportHandoffReason::BILLING,
            ],
            'invoice dispute, turkish' => [
                'Faturaya itiraz ediyorum',
                SupportHandoffReason::BILLING,
            ],
            'double charge' => [
                'I was charged twice this month',
                SupportHandoffReason::BILLING,
            ],
            'turkish human request' => [
                'bir insanla konuşmak istiyorum',
                SupportHandoffReason::HUMAN_REQUESTED,
            ],
            'turkish human request in capitals' => [
                'BİR İNSANLA KONUŞMAK İSTİYORUM',
                SupportHandoffReason::HUMAN_REQUESTED,
            ],
            'turkish representative' => [
                'Müşteri temsilcisine bağlayın',
                SupportHandoffReason::HUMAN_REQUESTED,
            ],
            'english human request' => [
                'Can I talk to a human please?',
                SupportHandoffReason::HUMAN_REQUESTED,
            ],
            'english real person' => [
                'I need a real person.',
                SupportHandoffReason::HUMAN_REQUESTED,
            ],
            'hacked account' => [
                'I think my account was hacked',
                SupportHandoffReason::SECURITY,
            ],
            'turkish password reset' => [
                'Şifremi sıfırlamak istiyorum',
                SupportHandoffReason::SECURITY,
            ],
            'turkish password reset in capitals' => [
                'ŞİFREMİ SIFIRLA',
                SupportHandoffReason::SECURITY,
            ],
            'english password reset' => [
                'Please reset my password',
                SupportHandoffReason::SECURITY,
            ],
            'turkish account deletion' => [
                'hesabımı silmek istiyorum',
                SupportHandoffReason::SECURITY,
            ],
            'english account deletion' => [
                'Delete my account',
                SupportHandoffReason::SECURITY,
            ],
            'english outage' => [
                'Your service is down!',
                SupportHandoffReason::OUTAGE,
            ],
            'english data loss' => [
                'I lost all my data',
                SupportHandoffReason::OUTAGE,
            ],
            'turkish data loss' => [
                'Tüm verilerim kayboldu',
                SupportHandoffReason::OUTAGE,
            ],
            'turkish outage' => [
                'Sistem çöktü',
                SupportHandoffReason::OUTAGE,
            ],
            'english anger' => [
                'This is unacceptable, you are useless',
                SupportHandoffReason::ANGER,
            ],
            'turkish anger in capitals' => [
                'BU NE REZALET',
                SupportHandoffReason::ANGER,
            ],
            'shouting' => [
                'Nobody answers me!!!',
                SupportHandoffReason::ANGER,
            ],
        ];
    }

    #[DataProvider('handoffs')]
    public function test_a_critical_message_is_classified_with_its_reason(
        string $text,
        SupportHandoffReason $reason,
    ): void {
        $verdict = (new HandoffClassifier)->classify($text);

        $this->assertNotNull($verdict);
        $this->assertSame($reason, $verdict['reason']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function questions(): array
    {
        return [
            'how to add a monitor' => [
                'How do I add a monitor?',
            ],
            'turkish how to add a monitor' => [
                'Monitör nasıl eklenir?',
            ],
            'check interval' => [
                'What is the shortest check interval on my plan?',
            ],
            'alert rules in capitals' => [
                'HOW DO I SET UP ALERT RULES',
            ],
            'status page' => [
                'Durum sayfamı nasıl yayınlarım?',
            ],
            'empty' => [
                '',
            ],
        ];
    }

    #[DataProvider('questions')]
    public function test_an_ordinary_question_is_left_to_the_ai(string $text): void
    {
        $this->assertNull((new HandoffClassifier)->classify($text));
    }

    /**
     * @return array<string, array{string, SupportHandoffReason, SupportPriority}>
     */
    public static function priorities(): array
    {
        return [
            'security is urgent' => [
                'my account was hacked',
                SupportHandoffReason::SECURITY,
                SupportPriority::URGENT,
            ],
            'outage is urgent' => [
                'your platform is down',
                SupportHandoffReason::OUTAGE,
                SupportPriority::URGENT,
            ],
            'billing is high' => [
                'refund please',
                SupportHandoffReason::BILLING,
                SupportPriority::HIGH,
            ],
            'anger is high' => [
                'this is ridiculous',
                SupportHandoffReason::ANGER,
                SupportPriority::HIGH,
            ],
            'a human request is normal' => [
                'I want to speak to a human',
                SupportHandoffReason::HUMAN_REQUESTED,
                SupportPriority::NORMAL,
            ],
        ];
    }

    #[DataProvider('priorities')]
    public function test_each_reason_carries_the_priority_staff_pick_it_up_at(
        string $text,
        SupportHandoffReason $reason,
        SupportPriority $priority,
    ): void {
        $this->assertSame(
            [
                'reason' => $reason,
                'priority' => $priority,
            ],
            (new HandoffClassifier)->classify($text),
        );
    }

    public function test_the_most_serious_reason_wins_when_a_message_has_several(): void
    {
        $verdict = (new HandoffClassifier)->classify('My account was hacked, I want a refund and a human!');

        $this->assertSame(SupportHandoffReason::SECURITY, $verdict['reason'] ?? null);
    }

    public function test_the_term_lists_are_extendable_from_config(): void
    {
        config()->set('magic-starter.support.ai.handoff_terms', [
            'billing' => [
                'VERGİ',
            ],
            'not_a_reason' => [
                'banana',
            ],
        ]);
        $classifier = new HandoffClassifier;

        $this->assertSame(
            SupportHandoffReason::BILLING,
            $classifier->classify('vergi levhası lazım')['reason'] ?? null,
        );
        $this->assertNull($classifier->classify('I like a banana'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function foldedForms(): array
    {
        return [
            'dotted capital I' => [
                'İADE',
                'iade',
            ],
            'plain capital I' => [
                'ISTIYORUM',
                'istiyorum',
            ],
            'dotless small i' => [
                'sıfırla',
                'sifirla',
            ],
            'turkish letters' => [
                'ŞİFREMİ ÇÖZ, ĞÜÇ',
                'sifremi coz, guc',
            ],
            'decomposed dot above' => [
                "I\u{0307}ade",
                'iade',
            ],
            'curly apostrophe and spacing' => [
                "I’ve   cancelled\tit",
                "i've cancelled it",
            ],
        ];
    }

    #[DataProvider('foldedForms')]
    public function test_folding_makes_every_turkish_spelling_of_a_word_one_form(string $text, string $folded): void
    {
        $this->assertSame($folded, HandoffClassifier::fold($text));
    }
}
