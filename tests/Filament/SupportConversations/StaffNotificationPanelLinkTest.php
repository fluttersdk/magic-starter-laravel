<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\SupportConversations;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Notifications\SupportConversationNeedsStaffNotification;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use ReflectionClass;

/**
 * The staff "needs staff" mail links to the conversation in the panel, and
 * only when that link can be built from a queue worker, which has no current
 * panel, no request and no tenant to read it from.
 *
 * The fixture panels are built inside methods and the registry is swapped
 * per case, so the real `admin` panel is the control for the happy path.
 */
class StaffNotificationPanelLinkTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [Features::support()]);
    }

    public function test_the_mail_links_to_the_conversation_in_the_panel(): void
    {
        $conversation = $this->conversation();

        // A worker has no current panel; the link must come from the plugin's own panel.
        Filament::setCurrentPanel(null);

        $mail = $this->mailFor($conversation);

        $this->assertStringStartsWith('http', (string) $mail->actionUrl);
        $this->assertStringEndsWith('/admin/support-conversations/' . $conversation->getKey(), $mail->actionUrl);
        $this->assertSame(
            Lang::get('magic-starter::admin_support.notifications.staff.open_action'),
            $mail->actionText,
        );
    }

    public function test_the_mail_carries_no_customer_text_beyond_the_subject_header(): void
    {
        $conversation = $this->conversation();
        $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'SENTINEL-customer-words',
        ]);

        $mail = $this->mailFor($conversation);

        $this->assertNotNull($mail->actionUrl);
        $this->assertStringContainsString('Checks fail', $mail->subject);

        foreach ([...$mail->introLines, $mail->actionText] as $line) {
            $this->assertStringNotContainsString('SENTINEL-customer-words', $line);
            $this->assertStringNotContainsString('Checks fail', $line);
        }
    }

    public function test_there_is_no_button_when_the_support_resource_is_excluded(): void
    {
        $this->panels(Panel::make()->id('slim')->plugin(MagicStarterPlugin::make()->withoutResources(['support'])));

        $this->assertNoAction($this->mailFor($this->conversation()));
    }

    public function test_there_is_no_button_when_the_replacement_resource_has_no_view_page(): void
    {
        $replacement = (new class extends MagicStarterResource
        {
            protected static function resolveModel(): string
            {
                return SupportConversation::class;
            }
        })::class;

        $this->panels(Panel::make()->id('bare')->plugin(MagicStarterPlugin::make()->resource('support', $replacement)));

        $this->assertNoAction($this->mailFor($this->conversation()));
    }

    public function test_there_is_no_button_when_no_panel_carries_the_plugin(): void
    {
        $this->panels(Panel::make()->id('plain'));

        $this->assertNoAction($this->mailFor($this->conversation()));
    }

    public function test_there_is_no_button_on_a_panel_with_tenancy(): void
    {
        $this->panels(Panel::make()->id('tenanted')->tenant(ConcreteTeam::class)->plugin(MagicStarterPlugin::make()));

        $this->assertNoAction($this->mailFor($this->conversation()));
    }

    public function test_there_is_no_button_on_a_panel_with_two_domains(): void
    {
        $this->panels(
            Panel::make()
                ->id('split')
                ->domains(['admin.example.test', 'ops.example.test'])
                ->plugin(MagicStarterPlugin::make()),
        );

        $this->assertNoAction($this->mailFor($this->conversation()));
    }

    /**
     * A panel on one domain is how an app serves its panel from its own host, and the worker has to link to that
     * host: the domain is the one thing the panel's routes know that a queue worker's request cannot supply.
     */
    public function test_a_panel_on_one_domain_links_on_that_domain(): void
    {
        $conversation = $this->conversation();
        $this->panels(
            Panel::make()
                ->id('single')
                ->domain('admin.example.test')
                ->plugin(MagicStarterPlugin::make()),
        );
        $this->registerPanelRoutes();

        $mail = $this->mailFor($conversation);

        $this->assertMatchesRegularExpression('#^https?://admin\.example\.test/#', (string) $mail->actionUrl);
        $this->assertStringEndsWith('/support-conversations/' . $conversation->getKey(), (string) $mail->actionUrl);
    }

    public function test_there_is_no_button_when_filament_is_installed_but_its_provider_is_not_registered(): void
    {
        // A host with filament in vendor and no panel provider: the classes load, the container binding is absent.
        unset($this->app['filament']);
        Filament::clearResolvedInstance('filament');

        $this->assertNoAction($this->mailFor($this->conversation()));
    }

    /**
     * Replace the registry's panels, so "which panels carry the plugin" is what the case says.
     */
    private function panels(Panel ...$panels): void
    {
        $registry = app(PanelRegistry::class);
        $registry->panels = [];

        foreach ($panels as $panel) {
            $registry->panels[$panel->getId()] = $panel;
        }
    }

    /**
     * The routes of the panels now in the registry, which Filament registers once at boot for the panels it had then.
     */
    private function registerPanelRoutes(): void
    {
        Route::group([], dirname((new ReflectionClass(Panel::class))->getFileName(), 2) . '/routes/web.php');

        app('router')->getRoutes()->refreshNameLookups();
    }

    private function assertNoAction(MailMessage $mail): void
    {
        $this->assertNull($mail->actionUrl);
        $this->assertNull($mail->actionText);
        $this->assertCount(2, $mail->introLines);
    }

    private function mailFor(SupportConversation $conversation): MailMessage
    {
        return (new SupportConversationNeedsStaffNotification($conversation, 'opened'))->toMail($this->staff());
    }

    private function staff(): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }

    private function conversation(): SupportConversation
    {
        return SupportConversation::query()->create([
            'email' => 'customer@example.com',
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'last_activity_at' => now(),
        ]);
    }
}
