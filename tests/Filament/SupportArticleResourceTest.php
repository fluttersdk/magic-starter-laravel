<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Forms\Components\MarkdownEditor;
use Filament\Panel;
use FlutterSdk\MagicStarter\Contracts\TranslatesSupportArticles;
use FlutterSdk\MagicStarter\Contracts\ValidatesSupportArticleMarkdown;
use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\AdminNavigationGroup;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages\CreateSupportArticle;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages\EditSupportArticle;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\Pages\ListSupportArticles;
use FlutterSdk\MagicStarter\Filament\Resources\SupportArticles\SupportArticleResource;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Staff curate the knowledge base in the panel: the text per locale, who may read it, and the
 * approval that lets a translated locale reach a reader.
 *
 * The support feature and `support.articles.enabled` are on while the application boots, because
 * the resource's routes are registered at that moment; the "feature off" cases build their own
 * panel the way PluginTest does.
 */
class SupportArticleResourceTest extends FilamentTestCase
{
    private const DISK = 'article-images';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'magic-starter.features' => [Features::support()],
            'magic-starter.use_uuids' => false,
            'magic-starter.support.articles' => [
                'enabled' => true,
                'disk' => self::DISK,
                'image_max_kb' => 100,
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
    }

    public function test_the_resource_is_mounted_only_with_support_and_the_articles_switch(): void
    {
        config()->set('magic-starter.features', []);
        $noSupport = Panel::make()->id('articles-no-support')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::support()]);
        config()->set('magic-starter.support.articles.enabled', false);
        $switchedOff = Panel::make()->id('articles-switched-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.support.articles.enabled', true);
        $on = Panel::make()->id('articles-on')->plugin(MagicStarterPlugin::make());

        $this->assertNotContains(SupportArticleResource::class, $noSupport->getResources());
        $this->assertNotContains(SupportArticleResource::class, $switchedOff->getResources());
        $this->assertContains(SupportArticleResource::class, $on->getResources());
    }

    public function test_the_resource_sits_in_the_support_group_and_labels_in_both_locales(): void
    {
        $this->assertSame(AdminNavigationGroup::Support, SupportArticleResource::getNavigationGroup());
        $this->assertSame(30, SupportArticleResource::getNavigationSort());
        $this->assertSame(['index', 'create', 'edit'], array_keys(SupportArticleResource::getPages()));

        app()->setLocale('en');
        $this->assertSame('Knowledge base', SupportArticleResource::getNavigationLabel());

        app()->setLocale('tr');
        $this->assertSame('Bilgi bankası', SupportArticleResource::getNavigationLabel());
    }

    public function test_creating_with_english_only_stores_it_approved_and_queues_a_turkish_draft(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $translator = $this->bindTranslator();

        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload(['slug' => 'reset-password']))
            ->call('create')
            ->assertHasNoFormErrors();

        $article = SupportArticle::query()->where('slug', 'reset-password')->firstOrFail();

        $this->assertSame(['en' => 'approved', 'tr' => 'draft'], $article->locale_state);
        $this->assertSame(['en' => 'How do I reset my password?'], $article->question);
        $this->assertSame([[$article->getKey(), 'tr']], $translator->queued);

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'support_article.created',
        );
    }

    public function test_without_a_translator_an_empty_locale_stays_empty(): void
    {
        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload(['slug' => 'no-translator']))
            ->call('create')
            ->assertHasNoFormErrors();

        $article = SupportArticle::query()->where('slug', 'no-translator')->firstOrFail();

        $this->assertSame(['en' => 'approved'], $article->locale_state);
    }

    public function test_a_locale_staff_wrote_themselves_is_approved_and_asks_for_no_draft(): void
    {
        $translator = $this->bindTranslator();

        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'both-locales',
                'question' => ['en' => 'How do I reset my password?', 'tr' => 'Sifremi nasil sifirlarim?'],
                'body' => ['en' => 'Use the link.', 'tr' => 'Baglantiyi kullanin.'],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $article = SupportArticle::query()->where('slug', 'both-locales')->firstOrFail();

        $this->assertSame(['en' => 'approved', 'tr' => 'approved'], $article->locale_state);
        $this->assertSame([], $translator->queued);
    }

    public function test_a_default_locale_question_and_body_are_required(): void
    {
        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'empty',
                'question' => ['en' => ''],
                'body' => ['en' => ''],
            ]))
            ->call('create')
            ->assertHasFormErrors(['question.en' => 'required', 'body.en' => 'required']);

        $this->assertSame(0, SupportArticle::query()->count());
    }

    public function test_approve_flips_a_translated_draft_to_approved(): void
    {
        Event::fake([AdminActionPerformed::class]);

        $article = $this->translatedDraft();

        Livewire::actingAs($this->staff())->test(EditSupportArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('approve_tr')
            ->assertActionHidden('approve_en')
            ->callAction('approve_tr');

        $this->assertSame(['en' => 'approved', 'tr' => 'approved'], $article->refresh()->locale_state);

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $event): bool => $event->action === 'support_article.approved'
                && $event->context === ['locale' => 'tr'],
        );
    }

    public function test_the_list_offers_the_approve_row_action_only_for_a_draft_with_text(): void
    {
        $draft = $this->translatedDraft();
        $waiting = SupportArticle::factory()->create([
            'locale_state' => ['en' => 'approved', 'tr' => 'draft'],
        ]);

        Livewire::actingAs($this->staff())->test(ListSupportArticles::class)
            ->loadTable()
            ->assertTableActionVisible('approve_tr', $draft)
            ->assertTableActionHidden('approve_tr', $waiting)
            ->assertTableActionHidden('approve_en', $draft)
            ->callTableAction('approve_tr', $draft);

        $this->assertSame('approved', $draft->refresh()->locale_state['tr']);
        $this->assertSame('draft', $waiting->refresh()->locale_state['tr']);
    }

    public function test_saving_the_form_never_approves_a_translated_draft(): void
    {
        $article = $this->translatedDraft();

        Livewire::actingAs($this->staff())->test(EditSupportArticle::class, ['record' => $article->getKey()])
            ->fillForm([
                'section' => 'account',
                'body' => ['tr' => 'Duzenlenmis taslak.'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $article->refresh();

        $this->assertSame('account', $article->section);
        $this->assertSame('Duzenlenmis taslak.', $article->body['tr']);
        $this->assertSame(['en' => 'approved', 'tr' => 'draft'], $article->locale_state);
    }

    public function test_saving_an_article_again_does_not_queue_a_draft_twice(): void
    {
        $translator = $this->bindTranslator();

        $article = SupportArticle::factory()->create([
            'locale_state' => ['en' => 'approved', 'tr' => 'draft'],
        ]);

        Livewire::actingAs($this->staff())->test(EditSupportArticle::class, ['record' => $article->getKey()])
            ->fillForm(['section' => 'account'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], $translator->queued);
        $this->assertSame('draft', $article->refresh()->locale_state['tr']);
    }

    public function test_a_staff_only_body_with_image_syntax_is_refused(): void
    {
        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'internal',
                'visibility' => SupportArticleVisibility::STAFF_ONLY->value,
                'body' => ['en' => 'See ![diagram](https://example.com/a.png) first.'],
            ]))
            ->call('create')
            ->assertHasFormErrors(['body.en']);

        $this->assertSame(0, SupportArticle::query()->count());
    }

    public function test_an_ai_only_body_refuses_raw_html_images_too(): void
    {
        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'ai-notes',
                'visibility' => SupportArticleVisibility::AI_ONLY->value,
                'body' => ['en' => 'See <img src="https://example.com/a.png"> first.'],
            ]))
            ->call('create')
            ->assertHasFormErrors(['body.en']);
    }

    public function test_a_public_body_may_carry_an_image(): void
    {
        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'with-picture',
                'body' => ['en' => 'See ![diagram](https://example.com/a.png) first.'],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, SupportArticle::query()->count());
    }

    public function test_an_image_the_disk_serves_is_stored_by_its_disk_relative_path_in_every_locale(): void
    {
        $url = Storage::disk(self::DISK)->url('support-articles/shot.png');
        $reference = Storage::disk(self::DISK)->url('support-articles/ref.png');

        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'uploaded',
                'question' => ['en' => 'Where is it?', 'tr' => 'Nerede?'],
                'body' => [
                    'en' => "See ![shot]({$url} \"Title\") and ![ref][fig].\n\n[fig]: {$reference}",
                    'tr' => "Bakin ![goruntu](<{$url}>).",
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([
            'en' => "See ![shot](support-articles/shot.png \"Title\") and ![ref][fig].\n\n[fig]: support-articles/ref.png",
            'tr' => 'Bakin ![goruntu](<support-articles/shot.png>).',
        ], SupportArticle::query()->sole()->body);
    }

    public function test_an_image_url_the_disk_does_not_serve_is_stored_as_written(): void
    {
        $body = implode("\n", [
            '![foreign](https://example.com/support-articles/shot.png)',
            '![elsewhere](' . Storage::disk(self::DISK)->url('avatars/me.png') . ')',
            '![relative](support-articles/kept.png)',
        ]);

        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'foreign',
                'body' => ['en' => $body],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($body, SupportArticle::query()->sole()->body['en']);
    }

    public function test_the_attachment_toolbar_follows_the_visibility(): void
    {
        $offersAttachments = static fn (MarkdownEditor $field): bool => $field->hasFileAttachments()
            && in_array('attachFiles', array_merge(...$field->getToolbarButtons()), true);
        $withholdsAttachments = static fn (MarkdownEditor $field): bool => ! $field->hasFileAttachments()
            && ! in_array('attachFiles', array_merge(...$field->getToolbarButtons()), true);

        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm(['visibility' => SupportArticleVisibility::PUBLIC->value])
            ->assertFormFieldExists('body.en', checkFieldUsing: $offersAttachments)
            ->fillForm(['visibility' => SupportArticleVisibility::STAFF_ONLY->value])
            ->assertFormFieldExists('body.en', checkFieldUsing: $withholdsAttachments);
    }

    public function test_the_attachments_use_the_configured_disk_directory_types_and_size(): void
    {
        $isConfigured = static fn (MarkdownEditor $field): bool => $field->getFileAttachmentsDiskName() === self::DISK
            && $field->getFileAttachmentsDirectory() === 'support-articles'
            && $field->getFileAttachmentsAcceptedFileTypes() === ['image/png', 'image/jpeg', 'image/webp']
            && $field->getFileAttachmentsMaxSize() === 100;

        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->assertFormFieldExists('body.en', checkFieldUsing: $isConfigured);
    }

    public function test_a_png_upload_is_stored_and_answers_its_url(): void
    {
        $answer = $this->upload(UploadedFile::fake()->image('a.png')->size(10));

        $this->assertNotNull($answer);
        $this->assertCount(1, Storage::disk(self::DISK)->allFiles('support-articles'));
    }

    public function test_a_gif_upload_is_refused_and_nothing_is_stored(): void
    {
        $this->assertNull($this->upload(UploadedFile::fake()->image('a.gif')->size(10)));
        $this->assertSame([], Storage::disk(self::DISK)->allFiles());
    }

    public function test_an_upload_over_the_configured_size_is_refused(): void
    {
        $this->assertNull($this->upload(UploadedFile::fake()->image('big.png')->size(500)));
        $this->assertSame([], Storage::disk(self::DISK)->allFiles());
    }

    public function test_a_bound_markdown_validator_turns_its_errors_into_form_errors(): void
    {
        $validator = new class implements ValidatesSupportArticleMarkdown
        {
            public function validate(string $markdown, SupportArticleVisibility $visibility, string $locale): array
            {
                return str_contains($markdown, '{{') ? ['Unknown placeholder in the ' . $locale . ' text.'] : [];
            }
        };

        $this->app->instance(ValidatesSupportArticleMarkdown::class, $validator);

        Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->fillForm($this->payload([
                'slug' => 'placeholder',
                'body' => ['en' => 'Hello {{ name }}'],
            ]))
            ->call('create')
            ->assertHasFormErrors(['body.en']);

        $this->assertSame(0, SupportArticle::query()->count());
    }

    public function test_the_list_shows_the_default_locale_question_visibility_and_each_locale_state(): void
    {
        $article = $this->translatedDraft();

        Livewire::actingAs($this->staff())->test(ListSupportArticles::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$article])
            ->assertTableColumnStateSet('question.en', 'How do I reset my password?', $article)
            ->assertTableColumnStateSet('section', 'general', $article)
            ->assertTableColumnStateSet('visibility', SupportArticleVisibility::PUBLIC, $article)
            ->assertTableColumnStateSet('locale_state.en', 'approved', $article)
            ->assertTableColumnStateSet('locale_state.tr', 'draft', $article)
            ->assertTableColumnExists('published_at');
    }

    /**
     * Pushes a form state over the defaults a create needs.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'slug' => 'article',
            'section' => 'general',
            'sort' => 0,
            'visibility' => SupportArticleVisibility::PUBLIC->value,
            'published_at' => now()->subMinute()->toDateTimeString(),
            'question' => ['en' => 'How do I reset my password?'],
            'body' => ['en' => 'Use the link on the sign-in screen.'],
            ...$overrides,
        ];
    }

    /**
     * An English article approved, with a Turkish text a translator wrote and nobody approved yet.
     */
    private function translatedDraft(): SupportArticle
    {
        return SupportArticle::factory()->create([
            'question' => ['en' => 'How do I reset my password?', 'tr' => 'Sifremi nasil sifirlarim?'],
            'body' => ['en' => 'Use the link.', 'tr' => 'Baglantiyi kullanin.'],
            'locale_state' => ['en' => 'approved', 'tr' => 'draft'],
        ]);
    }

    /**
     * Binds a translator that records each draft it is asked for.
     *
     * @return object{queued: list<array{0: string|int, 1: string}>}&TranslatesSupportArticles
     */
    private function bindTranslator(): TranslatesSupportArticles
    {
        $translator = new class implements TranslatesSupportArticles
        {
            /** @var list<array{0: string|int, 1: string}> */
            public array $queued = [];

            public function queueDraft(SupportArticle $article, string $locale): void
            {
                $this->queued[] = [$article->getKey(), $locale];
            }
        };

        $this->app->instance(TranslatesSupportArticles::class, $translator);

        return $translator;
    }

    /**
     * Drives the editor's upload round trip the way the browser does: the file lands in the
     * temporary attachments, then the editor asks the field to save it and answer a url.
     */
    private function upload(UploadedFile $file): ?string
    {
        $page = Livewire::actingAs($this->staff())->test(CreateSupportArticle::class)
            ->set('componentFileAttachments.data.body.en', $file);

        $component = $page->instance()->getSchema('form')->getComponent(
            static fn (mixed $candidate): bool => $candidate instanceof MarkdownEditor
                && $candidate->getStatePath() === 'data.body.en',
        );

        $page->call('callSchemaComponentMethod', $component->getKey(), 'saveUploadedFileAttachmentAndGetUrl');

        $returned = $page->effects['returns'][0] ?? null;

        return is_string($returned) ? $returned : null;
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
