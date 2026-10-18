<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\SupportConversations;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ViewSupportConversation;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Staff audit the AI desk's stored summary of earlier messages on the conversation page: read-only, shown only when
 * the desk has written one, and always as escaped text.
 */
class SupportConversationSummaryTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [Features::support()]);
    }

    public function test_the_view_shows_the_stored_summary_with_its_label_and_helper_line(): void
    {
        $conversation = $this->conversation([
            'ai_summary' => 'The customer reported failing checks and was asked for the monitor URL.',
        ]);

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee('AI summary of earlier messages')
            ->assertSee('The customer reported failing checks and was asked for the monitor URL.')
            ->assertSee('Written by the AI to keep the thread short. It is a paraphrase, not a record.');
    }

    public function test_the_view_hides_the_summary_entry_while_none_is_stored(): void
    {
        $conversation = $this->conversation();

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertDontSee('AI summary of earlier messages')
            ->assertDontSee('It is a paraphrase, not a record.');
    }

    public function test_the_summary_is_rendered_as_escaped_text_never_html(): void
    {
        $conversation = $this->conversation([
            'ai_summary' => '<script>alert("x")</script><b>bold</b>',
        ]);

        Livewire::actingAs($this->staff())->test(ViewSupportConversation::class, ['record' => $conversation->getKey()])
            ->assertSee('<script>alert("x")</script><b>bold</b>')
            ->assertDontSee('<script>alert("x")</script>', false)
            ->assertDontSee('<b>bold</b>', false);
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function conversation(array $attributes = []): SupportConversation
    {
        $opener = ConcreteAdminUser::query()->create([
            'name' => 'opener',
            'email' => 'opener@example.com',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);

        return SupportConversation::query()->create([
            'user_id' => $opener->getKey(),
            'email' => $opener->email,
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'last_activity_at' => now(),
            ...$attributes,
        ]);
    }
}
