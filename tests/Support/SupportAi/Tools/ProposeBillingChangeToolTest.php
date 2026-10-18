<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Contracts\RecordsSupportProposals;
use FlutterSdk\MagicStarter\Contracts\SupportTool;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\ProposeBillingChangeTool;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolContext;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolRegistry;
use FlutterSdk\MagicStarter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the tool the support AI calls to ask for a billing change: it maps the
 * model's arguments onto the recording contract and relays its sentence, takes
 * the trigger from the turn's own conversation instance, and answers a refusal
 * sentence rather than throwing for anything the model sent.
 *
 * The contract is faked: {@see \FlutterSdk\MagicStarter\Tests\Actions\RecordSupportProposalTest}
 * owns what recording does.
 */
class ProposeBillingChangeToolTest extends TestCase
{
    private ProposeBillingChangeToolTestRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new ProposeBillingChangeToolTestRecorder;
        $this->app->instance(RecordsSupportProposals::class, $this->recorder);
    }

    public function test_it_registers_under_its_snake_case_name_and_describes_its_arguments(): void
    {
        $registry = $this->app->make(SupportToolRegistry::class);
        $registry->register(ProposeBillingChangeTool::class);
        $tool = $registry->resolve()[0];

        $this->assertInstanceOf(SupportTool::class, $tool);
        $this->assertSame('propose_billing_change', $tool->name());
        $this->assertStringContainsString('never', $tool->description());
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['extend_trial', 'cancel_at_period_end', 'resume'],
                    'description' => 'The change the customer asked for.',
                ],
                'until' => [
                    'type' => 'string',
                    'maxLength' => 10,
                    'description' => 'YYYY-MM-DD, only for extend_trial',
                ],
                'reason' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'description' => 'why the customer asked, in your words',
                ],
            ],
            'required' => ['action'],
        ], $tool->schema());
    }

    public function test_it_records_through_the_contract_with_the_turn_trigger_and_relays_the_sentence(): void
    {
        $conversation = $this->conversation(41);
        $context = SupportToolContext::forConversation($conversation);

        $sentence = $this->tool()->handle($context, [
            'action' => 'extend_trial',
            'until' => '2026-10-20',
            'reason' => 'Lost a week to an outage.',
            'team_id' => 'someone-else',
        ]);

        $this->assertSame('recorded by the fake', $sentence);
        $this->assertCount(1, $this->recorder->calls);
        $call = $this->recorder->calls[0];
        $this->assertSame($conversation, $call['conversation']);
        $this->assertSame(SupportProposalAction::EXTEND_TRIAL, $call['action']);
        $this->assertSame(['until' => '2026-10-20'], $call['params']);
        $this->assertSame('Lost a week to an outage.', $call['rationale']);
        $this->assertSame(41, $call['trigger']);
    }

    public function test_a_missing_reason_and_until_reach_the_contract_as_absent(): void
    {
        $this->tool()->handle(SupportToolContext::forConversation($this->conversation(7)), [
            'action' => 'resume',
            'reason' => ['not', 'a', 'string'],
        ]);

        $call = $this->recorder->calls[0];
        $this->assertSame(SupportProposalAction::RESUME, $call['action']);
        $this->assertSame([], $call['params']);
        $this->assertNull($call['rationale']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unknownActions(): array
    {
        return [
            'unknown value' => [['action' => 'refund_everything']],
            'wrong case' => [['action' => 'RESUME']],
            'not a string' => [['action' => 3]],
            'array' => [['action' => ['resume']]],
            'missing' => [[]],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('unknownActions')]
    public function test_an_unknown_action_is_a_refusal_and_never_reaches_the_contract(array $arguments): void
    {
        $sentence = $this->tool()->handle(SupportToolContext::forConversation($this->conversation(1)), $arguments);

        $this->assertStringContainsString('extend_trial', $sentence);
        $this->assertSame([], $this->recorder->calls);
    }

    public function test_without_a_conversation_it_refuses_and_never_reaches_the_contract(): void
    {
        $sentence = $this->tool()->handle(SupportToolContext::none(), [
            'action' => 'resume',
        ]);

        $this->assertNotSame('', $sentence);
        $this->assertNotSame('recorded by the fake', $sentence);
        $this->assertSame([], $this->recorder->calls);
    }

    private function tool(): ProposeBillingChangeTool
    {
        return $this->app->make(ProposeBillingChangeTool::class);
    }

    /**
     * Unsaved on purpose: a tool that re-read the row for a fresher trigger would find
     * nothing, so the trigger it passes can only come from the turn's own instance.
     */
    private function conversation(int $lastCustomerMessageId): SupportConversation
    {
        return (new SupportConversation)->forceFill([
            'last_customer_message_id' => $lastCustomerMessageId,
        ]);
    }
}

class ProposeBillingChangeToolTestRecorder implements RecordsSupportProposals
{
    /**
     * @var list<array{
     *     conversation: SupportConversation,
     *     action: SupportProposalAction,
     *     params: array<string, mixed>,
     *     rationale: string|null,
     *     trigger: int|string|null,
     * }>
     */
    public array $calls = [];

    public function record(
        SupportConversation $conversation,
        SupportProposalAction $action,
        array $params,
        ?string $rationale,
        int|string|null $triggerMessageId,
    ): string {
        $this->calls[] = [
            'conversation' => $conversation,
            'action' => $action,
            'params' => $params,
            'rationale' => $rationale,
            'trigger' => $triggerMessageId,
        ];

        return 'recorded by the fake';
    }
}
