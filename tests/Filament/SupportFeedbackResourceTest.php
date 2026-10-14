<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Panel;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\Filament\Resources\SupportFeedback\Pages\ListSupportFeedback;
use FlutterSdk\MagicStarter\Filament\Resources\SupportFeedback\SupportFeedbackResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportFeedback;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The read-only list of in-app feedback answers.
 *
 * Both support features are on while the application boots, because a
 * resource's routes are registered at that moment; the "feature off" case
 * builds its own panel the way PluginTest does.
 */
class SupportFeedbackResourceTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
            Features::supportFeedback(),
        ]);
    }

    public function test_the_resource_is_mounted_only_with_the_feedback_feature(): void
    {
        config()->set('magic-starter.features', [Features::support()]);
        $off = Panel::make()->id('feedback-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::support(), Features::supportFeedback()]);
        $on = Panel::make()->id('feedback-on')->plugin(MagicStarterPlugin::make());

        $this->assertNotContains(SupportFeedbackResource::class, $off->getResources());
        $this->assertContains(SupportFeedbackResource::class, $on->getResources());
    }

    public function test_the_list_shows_each_answer_with_its_user_and_conversation(): void
    {
        $user = $this->user('ada@example.com');
        $conversation = SupportConversation::query()->create([
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'subject' => 'Feedback',
            'source' => SupportConversationSource::FEEDBACK,
            'last_activity_at' => now(),
        ]);
        $low = $this->feedback($user, 3, 'Too slow <b>today</b>', $conversation);
        $high = $this->feedback($user, 10, null);

        Livewire::actingAs($this->staff())->test(ListSupportFeedback::class)
            ->assertCanSeeTableRecords([$low, $high])
            ->assertTableColumnStateSet('score', 3, $low)
            ->assertSeeHtml('Too slow &lt;b&gt;today&lt;/b&gt;')
            ->assertSee('ada@example.com')
            ->assertSeeHtml(e(SupportConversationResource::getUrl('view', ['record' => $conversation])));
    }

    public function test_the_list_filters_by_score_band_and_dismissal(): void
    {
        $user = $this->user('ada@example.com');
        $detractor = $this->feedback($user, 4, null);
        $passive = $this->feedback($user, 7, null);
        $promoter = $this->feedback($user, 9, null);
        $dismissed = $this->feedback($user, null, null);

        Livewire::actingAs($this->staff())->test(ListSupportFeedback::class)
            ->filterTable('score', 'detractor')
            ->assertCanSeeTableRecords([$detractor])
            ->assertCanNotSeeTableRecords([$passive, $promoter, $dismissed])
            ->filterTable('score', 'passive')
            ->assertCanSeeTableRecords([$passive])
            ->assertCanNotSeeTableRecords([$detractor, $promoter, $dismissed])
            ->filterTable('score', 'promoter')
            ->assertCanSeeTableRecords([$promoter])
            ->assertCanNotSeeTableRecords([$detractor, $passive, $dismissed])
            ->filterTable('score', 'dismissed')
            ->assertCanSeeTableRecords([$dismissed])
            ->assertCanNotSeeTableRecords([$detractor, $passive, $promoter]);
    }

    public function test_the_resource_writes_nothing(): void
    {
        $this->assertSame(['index'], array_keys(SupportFeedbackResource::getPages()));
        $this->assertFalse(SupportFeedbackResource::canCreate());
    }

    public function test_a_user_outside_the_panel_gate_cannot_reach_the_list(): void
    {
        $this->actingAs($this->staff())->get('/admin/support-feedback')->assertOk();
        $this->actingAs($this->user('member@example.com'))->get('/admin/support-feedback')->assertForbidden();
    }

    private function feedback(
        ConcreteAdminUser $user,
        ?int $score,
        ?string $comment,
        ?SupportConversation $conversation = null,
    ): SupportFeedback {
        return SupportFeedback::query()->create([
            'user_id' => $user->getKey(),
            'score' => $score,
            'comment' => $comment,
            'conversation_id' => $conversation?->getKey(),
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

    private function user(string $email): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
