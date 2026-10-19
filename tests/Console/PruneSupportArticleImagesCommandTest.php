<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\PruneSupportArticleImagesCommand;
use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * A knowledge-base image is stored the moment it is uploaded, on a web-served disk, so an article
 * that is deleted, leaves `public` or never saved the upload would leave a public file behind.
 * Both the listener and the daily prune delete under `support-articles/` on the articles disk and
 * nowhere else.
 */
final class PruneSupportArticleImagesCommandTest extends TestCase
{
    private const DISK = 'public';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'magic-starter.features' => [Features::support()],
            'magic-starter.use_uuids' => false,
            'magic-starter.support.articles.disk' => self::DISK,
            'magic-starter.support.articles.enabled' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_support_articles_table.php',
            '--realpath' => true,
        ]);
    }

    public function test_it_deletes_an_unreferenced_old_file_and_keeps_a_recent_or_referenced_one(): void
    {
        $this->putImage('support-articles/orphan.webp', ageHours: 25);
        $this->putImage('support-articles/fresh.webp', ageHours: 1);
        $this->putImage('support-articles/used.webp', ageHours: 25);
        $this->putImage('support-articles/used-in-fr.webp', ageHours: 25);
        $this->makeArticle([
            'en' => 'See ![diagram](support-articles/used.webp).',
            'fr' => 'Voir ![schema](support-articles/used-in-fr.webp "titre").',
        ]);

        $this->artisan(PruneSupportArticleImagesCommand::NAME)
            ->expectsOutputToContain('Deleted 1 orphaned')
            ->assertSuccessful();

        Storage::disk(self::DISK)->assertMissing('support-articles/orphan.webp');
        Storage::disk(self::DISK)->assertExists('support-articles/fresh.webp');
        Storage::disk(self::DISK)->assertExists('support-articles/used.webp');
        Storage::disk(self::DISK)->assertExists('support-articles/used-in-fr.webp');
    }

    public function test_it_keeps_a_file_a_reference_style_image_points_at(): void
    {
        $this->putImage('support-articles/by-reference.webp', ageHours: 25);
        $this->makeArticle([
            'en' => "See ![diagram][fig].\n\n[fig]: support-articles/by-reference.webp \"Figure\"",
        ]);

        $this->artisan(PruneSupportArticleImagesCommand::NAME)->assertSuccessful();

        Storage::disk(self::DISK)->assertExists('support-articles/by-reference.webp');
    }

    public function test_it_keeps_a_file_an_article_references_by_the_disks_absolute_url(): void
    {
        $this->putImage('support-articles/absolute.webp', ageHours: 25);
        $url = Storage::disk(self::DISK)->url('support-articles/absolute.webp');
        $this->makeArticle(['en' => "![shot]({$url})"]);

        $this->artisan(PruneSupportArticleImagesCommand::NAME)->assertSuccessful();

        Storage::disk(self::DISK)->assertExists('support-articles/absolute.webp');
    }

    public function test_it_never_touches_a_file_outside_the_support_articles_directory(): void
    {
        $this->putImage('.env', ageHours: 48);
        $this->putImage('avatars/me.webp', ageHours: 48);

        $this->artisan(PruneSupportArticleImagesCommand::NAME)->assertSuccessful();

        Storage::disk(self::DISK)->assertExists('.env');
        Storage::disk(self::DISK)->assertExists('avatars/me.webp');
    }

    public function test_deleting_an_article_removes_the_files_its_bodies_reference(): void
    {
        $this->putImage('support-articles/en.webp', ageHours: 0);
        $this->putImage('support-articles/fr.webp', ageHours: 0);
        $this->putImage('support-articles/other.webp', ageHours: 0);
        $article = $this->makeArticle([
            'en' => '![a](support-articles/en.webp)',
            'fr' => '![b](support-articles/fr.webp)',
        ]);

        $article->delete();

        Storage::disk(self::DISK)->assertMissing('support-articles/en.webp');
        Storage::disk(self::DISK)->assertMissing('support-articles/fr.webp');
        Storage::disk(self::DISK)->assertExists('support-articles/other.webp');
    }

    public function test_deleting_an_article_keeps_a_file_another_article_still_references(): void
    {
        $this->putImage('support-articles/shared.webp', ageHours: 0);
        $this->putImage('support-articles/own.webp', ageHours: 0);
        $first = $this->makeArticle([
            'en' => '![a](support-articles/shared.webp) ![b](support-articles/own.webp)',
        ]);
        $second = $this->makeArticle([
            'en' => 'Nothing here.',
            'fr' => '![c](' . Storage::disk(self::DISK)->url('support-articles/shared.webp') . ')',
        ]);

        $first->delete();

        Storage::disk(self::DISK)->assertExists('support-articles/shared.webp');
        Storage::disk(self::DISK)->assertMissing('support-articles/own.webp');

        $second->delete();

        Storage::disk(self::DISK)->assertMissing('support-articles/shared.webp');
    }

    public function test_leaving_public_keeps_a_file_another_article_still_references(): void
    {
        $this->putImage('support-articles/shared.webp', ageHours: 0);
        $leaving = $this->makeArticle(['en' => '![a](support-articles/shared.webp)']);
        $this->makeArticle(['en' => '![b](support-articles/shared.webp)']);

        $leaving->update([
            'visibility' => SupportArticleVisibility::AI_ONLY,
            'body' => ['en' => 'Plain text.'],
        ]);

        Storage::disk(self::DISK)->assertExists('support-articles/shared.webp');
    }

    public function test_deleting_an_article_never_removes_a_path_outside_the_support_articles_directory(): void
    {
        $this->putImage('.env', ageHours: 0);
        $this->putImage('avatars/me.webp', ageHours: 0);
        $this->putImage('support-articles/own.webp', ageHours: 0);
        $article = $this->makeArticle([
            'en' => implode("\n", [
                '![a](support-articles/../.env)',
                '![b](support-articles/%2e%2e/.env)',
                '![c](/support-articles/own.webp)',
                '![d](avatars/me.webp)',
                '![e](https://example.com/support-articles/own.webp)',
                '![f](support-articles/own.webp)',
            ]),
        ]);

        $article->delete();

        Storage::disk(self::DISK)->assertExists('.env');
        Storage::disk(self::DISK)->assertExists('avatars/me.webp');
        Storage::disk(self::DISK)->assertMissing('support-articles/own.webp');
    }

    public function test_leaving_public_removes_the_files_the_article_referenced_before_the_save(): void
    {
        $this->putImage('support-articles/gone.webp', ageHours: 0);
        $article = $this->makeArticle(['en' => '![a](support-articles/gone.webp)']);

        $article->update([
            'visibility' => SupportArticleVisibility::AI_ONLY,
            'body' => ['en' => 'Plain text, as a non-public article must be.'],
        ]);

        Storage::disk(self::DISK)->assertMissing('support-articles/gone.webp');
    }

    public function test_an_edit_that_keeps_the_article_public_keeps_its_files(): void
    {
        $this->putImage('support-articles/stays.webp', ageHours: 0);
        $article = $this->makeArticle(['en' => '![a](support-articles/stays.webp)']);

        $article->update(['section' => 'billing']);

        Storage::disk(self::DISK)->assertExists('support-articles/stays.webp');
    }

    public function test_it_is_registered_and_scheduled_daily(): void
    {
        $this->assertArrayHasKey(PruneSupportArticleImagesCommand::NAME, Artisan::all());

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                PruneSupportArticleImagesCommand::NAME,
            ));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function putImage(string $path, int $ageHours): void
    {
        $disk = Storage::disk(self::DISK);
        $disk->put($path, 'image');

        touch($disk->path($path), now()->subHours($ageHours)->getTimestamp());
    }

    /**
     * @param  array<string, string>  $body
     */
    private function makeArticle(array $body): SupportArticle
    {
        return SupportArticle::factory()->create([
            'body' => $body,
            'visibility' => SupportArticleVisibility::PUBLIC,
        ]);
    }
}
