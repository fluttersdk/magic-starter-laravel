<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Panel;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Widgets\SupportResolutionWidget;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;

/**
 * The support resolution widget: how many conversations the AI desk settled without a person.
 *
 * The support feature is on while the application boots; the "feature off" case builds its
 * own panel the way PluginTest does.
 */
class SupportResolutionWidgetTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
        ]);
    }

    public function test_the_widget_is_mounted_only_with_the_support_feature(): void
    {
        config()->set('magic-starter.features', []);
        $off = Panel::make()->id('resolution-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::support()]);
        $on = Panel::make()->id('resolution-on')->plugin(MagicStarterPlugin::make());

        $this->assertNotContains(SupportResolutionWidget::class, $off->getWidgets());
        $this->assertContains(SupportResolutionWidget::class, $on->getWidgets());
    }

    public function test_the_widget_renders_the_resolved_and_handed_off_counts_of_a_seeded_mix(): void
    {
        // Three the AI settled: two resolved, one idle for two days.
        $this->conversation(['status' => SupportConversationStatus::RESOLVED]);
        $this->conversation([
            'status' => SupportConversationStatus::RESOLVED,
            'csat_rating' => SupportCsatRating::DOWN,
        ]);
        $this->conversation(['last_activity_at' => now()->subDays(2)]);

        // One handed off for billing, and answered by staff afterwards.
        $handedOff = $this->conversation([
            'status' => SupportConversationStatus::OPEN,
            'handoff_reason' => SupportHandoffReason::BILLING,
        ]);
        $this->message($handedOff, SupportSenderType::STAFF);

        $stats = $this->stats();

        $this->assertSame(4, $stats[$this->label('routed')]['value']);
        $this->assertSame(3, $stats[$this->label('resolved')]['value']);
        $this->assertSame(1, $stats[$this->label('handed_off')]['value']);
        $this->assertSame('0% up, 100% down', $stats[$this->label('csat')]['value']);

        $html = Livewire::actingAs($this->staff())
            ->test(SupportResolutionWidget::class)
            ->assertSee('Billing: 1')
            ->html();

        $this->assertMatchesRegularExpression($this->renderedValue('resolved', '3'), $html);
        $this->assertMatchesRegularExpression($this->renderedValue('handed_off', '1'), $html);
    }

    public function test_a_conversation_a_person_answered_or_still_waiting_is_not_resolved_without_a_human(): void
    {
        $answered = $this->conversation(['status' => SupportConversationStatus::RESOLVED]);
        $this->message($answered, SupportSenderType::STAFF);
        $this->conversation(['last_activity_at' => now()->subHour()]);

        $stats = $this->stats();

        $this->assertSame(2, $stats[$this->label('routed')]['value']);
        $this->assertSame(0, $stats[$this->label('resolved')]['value']);
    }

    public function test_a_conversation_nobody_routed_to_the_ai_is_left_out(): void
    {
        $this->conversation(['status' => SupportConversationStatus::RESOLVED], withAiMessage: false);

        $this->assertSame(0, $this->stats()[$this->label('routed')]['value']);
    }

    public function test_the_previous_week_is_the_comparison_and_older_conversations_are_left_out(): void
    {
        $this->conversation(['status' => SupportConversationStatus::RESOLVED]);
        $this->conversation(['status' => SupportConversationStatus::RESOLVED], createdAt: now()->subDays(10));
        $this->conversation(['status' => SupportConversationStatus::RESOLVED], createdAt: now()->subDays(11));
        $this->conversation(['status' => SupportConversationStatus::RESOLVED], createdAt: now()->subDays(20));

        $resolved = $this->stats()[$this->label('resolved')];

        $this->assertSame(1, $resolved['value']);
        $this->assertSame(
            __('magic-starter::admin_support.resolution.previous', ['days' => 7, 'value' => 2]),
            $resolved['description'],
        );
    }

    public function test_the_top_three_handoff_reasons_are_listed_by_count(): void
    {
        foreach ([
            SupportHandoffReason::SECURITY,
            SupportHandoffReason::BILLING,
            SupportHandoffReason::BILLING,
            SupportHandoffReason::OUTAGE,
            SupportHandoffReason::OUTAGE,
            SupportHandoffReason::OUTAGE,
            SupportHandoffReason::ANGER,
        ] as $reason) {
            $this->conversation([
                'status' => SupportConversationStatus::OPEN,
                'handoff_reason' => $reason,
            ]);
        }

        $handedOff = $this->stats()[$this->label('handed_off')];

        $this->assertSame(7, $handedOff['value']);
        // A tie on count falls to the reason's stored value, so `anger` outranks `security`.
        $this->assertStringStartsWith(
            'Outage reported: 3, Billing: 2, Customer upset: 1 | ',
            $handedOff['description'],
        );
        $this->assertStringNotContainsString('Security', $handedOff['description']);
    }

    public function test_an_empty_desk_shows_zeros_and_a_dash_for_the_rating(): void
    {
        $stats = $this->stats();

        $this->assertSame(0, $stats[$this->label('routed')]['value']);
        $this->assertSame(0, $stats[$this->label('resolved')]['value']);
        $this->assertSame(0, $stats[$this->label('handed_off')]['value']);
        $this->assertSame('-', $stats[$this->label('csat')]['value']);
    }

    public function test_the_widget_does_not_poll(): void
    {
        $widget = new SupportResolutionWidget;

        $this->assertNull((new ReflectionMethod($widget, 'getPollingInterval'))->invoke($widget));
    }

    /**
     * The widget's figures by label.
     *
     * @return array<string, array{value: mixed, description: string|null}>
     */
    private function stats(): array
    {
        $widget = new SupportResolutionWidget;
        $figures = [];

        foreach ((new ReflectionMethod($widget, 'getStats'))->invoke($widget) as $stat) {
            $figures[(string) $stat->getLabel()] = [
                'value' => $stat->getValue(),
                'description' => $stat->getDescription() === null ? null : (string) $stat->getDescription(),
            ];
        }

        return $figures;
    }

    private function label(string $key): string
    {
        return (string) __("magic-starter::admin_support.resolution.{$key}", ['days' => 7]);
    }

    /**
     * The pattern for a stat card that shows [$value] under the label of [$key].
     */
    private function renderedValue(string $key, string $value): string
    {
        return '/' . preg_quote($this->label($key), '/') . '.*?stat-value">\s*' . $value . '\s*</s';
    }

    /**
     * A conversation the AI desk answered, unless told otherwise, created inside the current week.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function conversation(
        array $attributes = [],
        bool $withAiMessage = true,
        ?Carbon $createdAt = null,
    ): SupportConversation {
        $conversation = new SupportConversation([
            'subject' => 'Help',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
            'last_activity_at' => now(),
            ...$attributes,
        ]);

        $conversation->forceFill([
            'created_at' => $createdAt ?? now()->subHour(),
            'updated_at' => $createdAt ?? now()->subHour(),
        ])->save();

        if ($withAiMessage) {
            $this->message($conversation, SupportSenderType::AI);
        }

        return $conversation;
    }

    private function message(SupportConversation $conversation, SupportSenderType $sender): SupportMessage
    {
        return SupportMessage::query()->create([
            'conversation_id' => $conversation->getKey(),
            'sender_type' => $sender,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Hello',
        ]);
    }

    private function staff(): ConcreteAdminUser
    {
        config()->set('magic-starter.admin.emails', ['staff@example.com']);

        return ConcreteAdminUser::query()->firstOrCreate(['email' => 'staff@example.com'], [
            'name' => 'Staff',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
