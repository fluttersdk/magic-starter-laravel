<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CreateAppRelease;
use FlutterSdk\MagicStarter\Contracts\CreatesAppReleases;
use FlutterSdk\MagicStarter\Contracts\DeletesAppReleases;
use FlutterSdk\MagicStarter\Contracts\UpdatesAppReleases;
use FlutterSdk\MagicStarter\Enums\ReleaseItemType;
use FlutterSdk\MagicStarter\Enums\ReleaseStatus;
use FlutterSdk\MagicStarter\Models\AppRelease;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/**
 * Locks the write contracts staff curate app releases through: what they
 * validate, how items are normalised on save, and which action each contract
 * resolves to.
 */
class AppReleaseActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/create_app_releases_table.php',
            '--realpath' => true,
        ]);
    }

    public function test_create_persists_list_shaped_items_from_a_string_type(): void
    {
        $release = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input([
            'items' => [
                [
                    'type' => 'new',
                    'text' => ['en' => 'Dark mode'],
                ],
            ],
        ]));

        $this->assertStringContainsString('"type":"new"', $this->rawItems($release));
        $this->assertSame('Dark mode', $release->fresh()->items[0]['text']['en']);
    }

    public function test_create_persists_the_backed_value_of_an_enum_type(): void
    {
        $release = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input([
            'status' => ReleaseStatus::DRAFT,
            'items' => [
                [
                    'type' => ReleaseItemType::NEW,
                    'text' => ['en' => 'Dark mode'],
                ],
            ],
        ]));

        $this->assertStringContainsString('"type":"new"', $this->rawItems($release));
        $this->assertSame(ReleaseStatus::DRAFT, $release->fresh()->status);
    }

    public function test_create_removes_a_blank_non_default_locale(): void
    {
        $release = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input([
            'items' => [
                [
                    'type' => 'fixed',
                    'text' => ['en' => 'Crash fixed', 'tr' => ''],
                ],
                [
                    'type' => 'improved',
                    'text' => ['en' => 'Faster', 'tr' => null],
                ],
            ],
        ]));

        $items = $release->fresh()->items;

        $this->assertSame(['en' => 'Crash fixed'], $items[0]['text']);
        $this->assertSame(['en' => 'Faster'], $items[1]['text']);
    }

    public function test_create_keeps_a_filled_non_default_locale(): void
    {
        $release = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input([
            'items' => [
                [
                    'type' => 'new',
                    'text' => ['en' => 'Dark mode', 'tr' => 'Karanlik mod'],
                ],
            ],
        ]));

        $this->assertSame('Karanlik mod', $release->fresh()->items[0]['text']['tr']);
    }

    public function test_create_publishes_now_when_no_date_is_given(): void
    {
        $this->travelTo(now()->startOfSecond());

        $release = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input([
            'status' => 'published',
            'published_at' => null,
        ]));

        $this->assertSame(ReleaseStatus::PUBLISHED, $release->fresh()->status);
        $this->assertTrue($release->fresh()->published_at->equalTo(now()));
    }

    public function test_create_refuses_a_future_published_at(): void
    {
        $this->assertRefused('published_at', fn () => app(CreatesAppReleases::class)->create(
            new ConcreteUser,
            $this->input(['status' => 'published', 'published_at' => now()->addDay()->toDateTimeString()]),
        ));
    }

    public function test_create_refuses_a_duplicate_version(): void
    {
        app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input());

        $this->assertRefused('version', fn () => app(CreatesAppReleases::class)->create(
            new ConcreteUser,
            $this->input(),
        ));
    }

    public function test_create_refuses_a_bad_version_format(): void
    {
        $this->assertRefused('version', fn () => app(CreatesAppReleases::class)->create(
            new ConcreteUser,
            $this->input(['version' => '1.4']),
        ));
    }

    public function test_create_refuses_an_unknown_item_type_with_a_validation_error(): void
    {
        $this->assertRefused('items.0.type', fn () => app(CreatesAppReleases::class)->create(
            new ConcreteUser,
            $this->input([
                'items' => [
                    [
                        'type' => 'bogus',
                        'text' => ['en' => 'Dark mode'],
                    ],
                ],
            ]),
        ));
    }

    public function test_create_refuses_items_without_the_default_locale_text(): void
    {
        $this->assertRefused('items.0.text.en', fn () => app(CreatesAppReleases::class)->create(
            new ConcreteUser,
            $this->input([
                'items' => [
                    [
                        'type' => 'new',
                        'text' => ['tr' => 'Karanlik mod'],
                    ],
                ],
            ]),
        ));
    }

    public function test_update_ignores_its_own_version_for_uniqueness(): void
    {
        $release = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input());

        app(UpdatesAppReleases::class)->update(new ConcreteUser, $release, $this->input([
            'ios_build' => 7,
            'items' => [
                [
                    'type' => ReleaseItemType::IMPROVED,
                    'text' => ['en' => 'Faster'],
                ],
            ],
        ]));

        $fresh = $release->fresh();

        $this->assertSame('1.4.0', $fresh->version);
        $this->assertSame(7, $fresh->ios_build);
        $this->assertStringContainsString('"type":"improved"', $this->rawItems($fresh));
    }

    public function test_update_refuses_the_version_of_another_release(): void
    {
        app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input());
        $other = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input(['version' => '1.5.0']));

        $this->assertRefused('version', fn () => app(UpdatesAppReleases::class)->update(
            new ConcreteUser,
            $other,
            $this->input(),
        ));
    }

    public function test_delete_removes_the_row(): void
    {
        $release = app(CreatesAppReleases::class)->create(new ConcreteUser, $this->input());

        app(DeletesAppReleases::class)->delete(new ConcreteUser, $release);

        $this->assertSame(0, AppRelease::query()->count());
    }

    public function test_contract_resolves_to_the_default_action(): void
    {
        $this->assertInstanceOf(CreateAppRelease::class, app(CreatesAppReleases::class));
    }

    /**
     * A valid input, with the given keys replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'version' => '1.4.0',
            'status' => 'draft',
            'published_at' => null,
            'ios_build' => null,
            'android_build' => null,
            'items' => [
                [
                    'type' => 'new',
                    'text' => ['en' => 'Dark mode'],
                ],
            ],
        ], $overrides);
    }

    private function rawItems(AppRelease $release): string
    {
        return (string) AppRelease::query()->whereKey($release->getKey())->toBase()->value('items');
    }

    private function assertRefused(string $key, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());

            return;
        }

        $this->fail('Expected the write to be refused with a ValidationException.');
    }
}
