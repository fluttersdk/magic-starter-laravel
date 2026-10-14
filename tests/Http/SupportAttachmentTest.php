<?php

namespace FlutterSdk\MagicStarter\Tests\Http;

use FlutterSdk\MagicStarter\Actions\StoreSupportAttachments;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Support\SupportThreadToken;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GdImage;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use RuntimeException;

/**
 * Customer attachments, driven through the routes the package registers.
 *
 * Every file is a real upload on a real temporary path, so the type check
 * sniffs the bytes the way production does; a testing fake would report the
 * type its name implies. Every refusal asserts the disk is still empty and
 * every isolation case asserts the opener's own success beside the intruder's
 * 404, so a check that is simply missing cannot pass.
 */
class SupportAttachmentTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.use_uuids', true);
        $app['config']->set('magic-starter.features', [
            Features::support(),
            Features::supportAttachments(),
        ]);
        $app['config']->set('auth.providers.users.model', SupportAttachmentTestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(SupportAttachmentTestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('current_team_id')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'add_feedback_columns_to_support_conversations_table.php',
            'create_support_message_attachments_table.php',
        ] as $file) {
            (require __DIR__ . '/../../database/migrations/' . $file)->up();
        }

        Storage::fake('local');
        Event::fake([
            SupportConversationNeedsStaff::class,
        ]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_png_jpeg_webp_and_pdf_are_accepted_and_stored_under_generated_names(): void
    {
        $opener = $this->user('opener@example.com');
        $this->assertSame([], $this->storedFiles());

        $this->actingAs($opener, 'sanctum');
        $response = $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Here are the files.',
            'attachments' => [
                $this->upload('chart.png', $this->png()),
                $this->upload('photo.jpg', $this->jpeg()),
                $this->upload('shot.webp', $this->webp()),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonCount(3, 'data.messages.0.attachments');
        $conversation = SupportConversation::query()->sole();
        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => 'And the invoice.',
            'attachments' => [
                $this->upload('invoice.pdf', $this->pdf()),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.attachments.0.kind', 'pdf')
            ->assertJsonPath('data.attachments.0.mime', 'application/pdf')
            ->assertJsonPath('data.attachments.0.name', 'invoice.pdf');

        $attachments = SupportMessageAttachment::query()->get()->keyBy('original_name');
        $this->assertSame([
            'chart.png' => 'image/png',
            'invoice.pdf' => 'application/pdf',
            'photo.jpg' => 'image/jpeg',
            'shot.webp' => 'image/webp',
        ], $attachments->map->mime->sortKeys()->all());
        $this->assertCount(4, $this->storedFiles());

        foreach ($attachments as $attachment) {
            $this->assertSame(SupportAttachmentOrigin::UPLOAD, $attachment->origin);
            $this->assertSame('local', $attachment->disk);
            $this->assertStringNotContainsString($attachment->original_name, $attachment->path);
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertSame(strlen((string) Storage::disk('local')->get($attachment->path)), $attachment->size);
        }

        // The PDF is kept byte for byte; the png is re-encoded but keeps its size and format.
        $this->assertSame($this->pdf(), Storage::disk('local')->get($attachments['invoice.pdf']->path));
        $this->assertSame(40, $attachments['chart.png']->width);
        $this->assertSame(20, $attachments['chart.png']->height);
        $this->assertNull($attachments['invoice.pdf']->width);

        $first = $response->json('data.messages.0.attachments.0');
        $this->assertSame([
            'id',
            'name',
            'mime',
            'size',
            'width',
            'height',
            'kind',
            'url',
        ], array_keys($first));
        $this->assertSame(
            "support/conversations/{$conversation->getKey()}/attachments/{$first['id']}",
            $first['url'],
        );
    }

    public function test_an_svg_or_html_file_named_png_is_refused(): void
    {
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Logo attached.',
            'attachments' => [
                $this->upload('logo.png', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
                    . '<script>alert(1)</script></svg>'),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Page attached.',
            'attachments' => [
                $this->upload('page.png', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        $this->assertSame(0, SupportConversation::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_an_image_whose_header_claims_too_many_pixels_is_refused_on_its_dimensions(): void
    {
        $opener = $this->user('opener@example.com');
        $bomb = $this->pngClaiming(30000, 30000);
        $this->assertLessThan(5120 * 1024, strlen($bomb));
        $this->assertSame([30000, 30000], array_slice((array) getimagesizefromstring($bomb), 0, 2));

        $this->actingAs($opener, 'sanctum');
        $response = $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Huge image.',
            'attachments' => [
                $this->upload('huge.png', $bomb),
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'attachments.0' => 'invalid image dimensions',
            ]);
        $this->assertStringNotContainsString('kilobytes', implode(' ', $response->json('errors')['attachments.0']));
        $this->assertSame(0, SupportConversation::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_an_image_within_the_edge_but_past_the_pixel_budget_is_refused(): void
    {
        config()->set('magic-starter.support.attachments.max_pixels', 100);
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Small image.',
            'attachments' => [
                $this->upload('chart.png', $this->png(20, 10)),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'attachments.0' => 'invalid image dimensions',
        ]);

        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_jpeg_is_stored_upright_with_its_exif_dropped_and_its_long_edge_capped(): void
    {
        config()->set('magic-starter.support.attachments.stored_max_edge', 30);
        $original = $this->jpegWithExif();
        $exif = exif_read_data('data://image/jpeg;base64,' . base64_encode($original), null, true);
        $this->assertSame(6, $exif['IFD0']['Orientation'] ?? null, 'The fixture carries orientation 6.');
        $this->assertArrayHasKey('GPS', $exif, 'The fixture carries a GPS block.');
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Photo.',
            'attachments' => [
                $this->upload('photo.jpg', $original),
            ],
        ])->assertCreated();

        $attachment = SupportMessageAttachment::query()->sole();
        $stored = (string) Storage::disk('local')->get($attachment->path);
        $storedExif = exif_read_data('data://image/jpeg;base64,' . base64_encode($stored), null, true);
        $this->assertArrayNotHasKey('GPS', (array) $storedExif);
        $this->assertArrayNotHasKey('Orientation', (array) ($storedExif['IFD0'] ?? []));

        // 80x40 landscape, rotated a quarter turn clockwise, then capped at 30 on the long edge: 15x30.
        $image = imagecreatefromstring($stored);
        $this->assertInstanceOf(GdImage::class, $image);
        $this->assertSame(15, imagesx($image));
        $this->assertSame(30, imagesy($image));
        $this->assertSame(15, $attachment->width);
        $this->assertSame(30, $attachment->height);
        $this->assertSame('image/jpeg', $attachment->mime);

        // The red left half of the original is now the top, the blue right half the bottom.
        [$topRed, , $topBlue] = $this->rgb($image, 7, 4);
        [$bottomRed, , $bottomBlue] = $this->rgb($image, 7, 26);
        $this->assertGreaterThan($topBlue + 100, $topRed);
        $this->assertGreaterThan($bottomRed + 100, $bottomBlue);
    }

    public function test_a_large_rotated_jpeg_is_capped_and_turned_upright(): void
    {
        config()->set('magic-starter.support.attachments.stored_max_edge', 50);
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Photo.',
            'attachments' => [
                $this->upload('photo.jpg', $this->jpegWithExif(1200, 600)),
            ],
        ])->assertCreated();

        $attachment = SupportMessageAttachment::query()->sole();
        $image = imagecreatefromstring((string) Storage::disk('local')->get($attachment->path));

        // 1200x600 landscape with orientation 6: portrait once upright, capped at 50 on the long edge.
        $this->assertInstanceOf(GdImage::class, $image);
        $this->assertSame([25, 50], [imagesx($image), imagesy($image)]);
        $this->assertSame([25, 50], [$attachment->width, $attachment->height]);
        [$topRed, , $topBlue] = $this->rgb($image, 12, 6);
        [$bottomRed, , $bottomBlue] = $this->rgb($image, 12, 44);
        $this->assertGreaterThan($topBlue + 100, $topRed);
        $this->assertGreaterThan($bottomRed + 100, $bottomBlue);
    }

    public function test_four_files_are_refused(): void
    {
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Four files.',
            'attachments' => [
                $this->upload('a.png', $this->png()),
                $this->upload('b.png', $this->png()),
                $this->upload('c.png', $this->png()),
                $this->upload('d.png', $this->png()),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments');

        $this->assertSame(0, SupportConversation::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_daily_file_cap_refuses_the_next_upload(): void
    {
        config()->set('magic-starter.support.attachments.daily_files', 2);
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, [
            $this->upload('a.png', $this->png()),
            $this->upload('b.png', $this->png()),
        ]);
        $this->assertCount(2, $this->storedFiles());

        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => 'One more.',
            'attachments' => [
                $this->upload('c.png', $this->png()),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments');

        $this->assertCount(2, $this->storedFiles());
        $this->assertSame(1, SupportMessage::query()->count(), 'A refused upload posts no message either.');

        // The cap is per day: tomorrow the same upload goes through.
        $this->travel(1)->days();
        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => 'Next day.',
            'attachments' => [
                $this->upload('c.png', $this->png()),
            ],
        ])->assertCreated();
        $this->assertCount(3, $this->storedFiles());
    }

    public function test_staff_files_are_exempt_from_the_daily_allowance(): void
    {
        config()->set('magic-starter.support.attachments.daily_files', 1);
        $staff = $this->user('staff@example.com');
        $action = app(StoreSupportAttachments::class);

        $prepared = $action->prepare(
            $staff,
            [
                $this->upload('a.png', $this->png()),
                $this->upload('b.png', $this->png()),
            ],
            null,
            SupportAttachmentOrigin::STAFF,
        );

        $this->assertCount(2, $prepared);
    }

    public function test_the_daily_byte_cap_refuses_the_next_upload(): void
    {
        config()->set('magic-starter.support.attachments.daily_kb', 1);
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Invoice.',
            'attachments' => [
                $this->upload('invoice.pdf', $this->pdf(2048)),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments');

        $this->assertSame(0, SupportConversation::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_message_of_only_an_image_is_accepted_and_a_message_of_nothing_is_not(): void
    {
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, []);

        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'attachments' => [
                $this->upload('a.png', $this->png()),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.body', '')
            ->assertJsonCount(1, 'data.attachments');

        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertCount(1, $this->storedFiles());
    }

    public function test_the_owner_fetches_the_bytes_with_a_fixed_type_and_no_sniffing(): void
    {
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, [
            $this->upload('chart.png', $this->png()),
        ]);
        $attachment = SupportMessageAttachment::query()->sole();

        $response = $this->get("/support/conversations/{$conversation->getKey()}/attachments/{$attachment->getKey()}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('local')->get($attachment->path), $response->streamedContent());
    }

    public function test_a_pdf_is_served_as_a_download(): void
    {
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, [
            $this->upload('invoice.pdf', $this->pdf()),
        ]);
        $attachment = SupportMessageAttachment::query()->sole();

        $response = $this->get("/support/conversations/{$conversation->getKey()}/attachments/{$attachment->getKey()}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('invoice.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_teammate_and_a_stranger_get_404_on_the_file_and_its_link(): void
    {
        $opener = $this->user('opener@example.com', 'team-a');
        $teammate = $this->user('mate@example.com', 'team-a');
        $stranger = $this->user('stranger@example.com', 'team-b');
        $conversation = $this->openWithFiles($opener, [
            $this->upload('invoice.pdf', $this->pdf()),
        ]);
        $attachment = SupportMessageAttachment::query()->sole();
        $file = "/support/conversations/{$conversation->getKey()}/attachments/{$attachment->getKey()}";

        $this->get($file)->assertOk();
        $this->postJson("{$file}/link")->assertOk();

        foreach ([$teammate, $stranger] as $intruder) {
            $this->actingAs($intruder, 'sanctum');
            $this->get($file)->assertNotFound();
            $this->postJson("{$file}/link")->assertNotFound();
        }
    }

    public function test_an_attachment_of_another_conversation_is_404_under_this_one(): void
    {
        $opener = $this->user('opener@example.com');
        $first = $this->openWithFiles($opener, [
            $this->upload('chart.png', $this->png()),
        ]);
        $second = $this->openWithFiles($opener, []);
        $attachment = SupportMessageAttachment::query()->sole();

        $this->get("/support/conversations/{$first->getKey()}/attachments/{$attachment->getKey()}")->assertOk();
        $this->get("/support/conversations/{$second->getKey()}/attachments/{$attachment->getKey()}")
            ->assertNotFound();
        $this->get("/support/conversations/{$first->getKey()}/attachments/not-a-key")->assertNotFound();
    }

    public function test_the_pdf_link_is_a_signed_url_that_downloads_without_a_bearer(): void
    {
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, [
            $this->upload('invoice.pdf', $this->pdf()),
            $this->upload('chart.png', $this->png()),
        ]);
        $pdf = SupportMessageAttachment::query()->where('mime', 'application/pdf')->sole();
        $image = SupportMessageAttachment::query()->where('mime', 'image/png')->sole();
        $base = "/support/conversations/{$conversation->getKey()}/attachments";

        $this->freezeSecond();
        $url = $this->postJson("{$base}/{$pdf->getKey()}/link")
            ->assertOk()
            ->json('url');

        $this->assertIsString($url);
        $this->assertStringContainsString("/support/attachments/{$pdf->getKey()}/download?", $url);
        $this->assertStringContainsString('expires=' . now()->addSeconds(60)->getTimestamp(), $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString($pdf->path, $url);
        $this->postJson("{$base}/{$image->getKey()}/link")->assertNotFound();

        // The signature is the whole credential: nobody is signed in when a system viewer follows it.
        $this->app['auth']->forgetGuards();
        $response = $this->get($url);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('invoice.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->get($pdf->path), $response->streamedContent());
    }

    public function test_a_tampered_or_expired_download_signature_is_refused(): void
    {
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, [
            $this->upload('invoice.pdf', $this->pdf()),
            $this->upload('other.pdf', $this->pdf(16)),
        ]);
        [$pdf, $other] = SupportMessageAttachment::query()->orderBy('original_name')->get()->all();
        $this->freezeSecond();
        $url = (string) $this->postJson(
            "/support/conversations/{$conversation->getKey()}/attachments/{$pdf->getKey()}/link",
        )->json('url');
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertOk();
        $this->get(str_replace((string) $pdf->getKey(), (string) $other->getKey(), $url))->assertForbidden();
        $this->get(preg_replace('/expires=\d+/', 'expires=' . now()->addDay()->getTimestamp(), $url))
            ->assertForbidden();
        $this->get(strtok($url, '?'))->assertForbidden();

        $this->travel(61)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_a_signed_download_of_an_image_or_a_malformed_key_is_not_found(): void
    {
        $opener = $this->user('opener@example.com');
        $this->openWithFiles($opener, [
            $this->upload('chart.png', $this->png()),
        ]);
        $image = SupportMessageAttachment::query()->sole();
        $signed = static fn (string $attachment): string => URL::temporarySignedRoute(
            'magic-starter.support.attachment-download',
            now()->addMinute(),
            [
                'attachment' => $attachment,
            ],
        );

        $this->get($signed((string) $image->getKey()))->assertNotFound();
        $this->get($signed('not-a-key'))->assertNotFound();
    }

    public function test_a_pdf_named_png_and_a_png_named_pdf_are_refused(): void
    {
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Chart attached.',
            'attachments' => [
                $this->upload('chart.png', $this->pdf()),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Invoice attached.',
            'attachments' => [
                $this->upload('invoice.pdf', $this->png()),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        $this->assertSame(0, SupportConversation::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_auto_screenshot_does_not_count_against_the_daily_allowance(): void
    {
        config()->set('magic-starter.features', [
            Features::support(),
            Features::supportAttachments(),
            Features::supportDiagnostics(),
        ]);
        config()->set('magic-starter.support.attachments.daily_files', 1);
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $response = $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Broken page.',
            'screenshot' => $this->upload('screenshot.png', $this->png()),
            'attachments' => [
                $this->upload('chart.png', $this->png()),
            ],
        ]);

        $response->assertCreated();
        $this->assertCount(2, $this->storedFiles());

        // The one upload used the allowance; the screenshot did not.
        $this->postJson("/support/conversations/{$response->json('data.id')}/messages", [
            'body' => 'One more.',
            'attachments' => [
                $this->upload('more.png', $this->png()),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments');
    }

    public function test_a_message_whose_files_fail_to_store_alerts_nobody(): void
    {
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, []);
        // Staff answered, so the next customer line starts a new wait and would alert.
        $conversation->forceFill([
            'waiting_since' => null,
        ])->save();
        Event::fake([
            SupportConversationNeedsStaff::class,
        ]);
        $this->app->instance(StoreSupportAttachments::class, new class extends StoreSupportAttachments
        {
            public function store(SupportMessage $message, array $prepared, Authenticatable $sender): void
            {
                throw new RuntimeException('The disk is full.');
            }
        });

        $this->postJson("/support/conversations/{$conversation->getKey()}/messages", [
            'body' => 'Here is the chart.',
            'attachments' => [
                $this->upload('chart.png', $this->png()),
            ],
        ])->assertServerError();

        $this->assertSame(1, SupportMessage::query()->count(), 'The message rolled back with its files.');
        Event::assertNotDispatched(SupportConversationNeedsStaff::class);
    }

    public function test_client_context_is_allowlisted_and_its_route_loses_query_and_fragment(): void
    {
        config()->set('magic-starter.features', [
            Features::support(),
            Features::supportAttachments(),
            Features::supportDiagnostics(),
        ]);
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Broken page.',
            'client_context' => [
                'route' => '/monitors/42?token=secret&tab=checks#latest',
                'app_version' => '1.4.0',
                'platform' => 'ios',
                'password' => 'hunter2',
            ],
        ])->assertCreated();

        $this->assertSame([
            'route' => '/monitors/42',
            'app_version' => '1.4.0',
            'platform' => 'ios',
        ], SupportConversation::query()->sole()->client_context);

        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Long value.',
            'client_context' => [
                'locale' => str_repeat('a', 121),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('client_context.locale');
    }

    public function test_client_context_and_the_screenshot_are_ignored_while_diagnostics_is_off(): void
    {
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Broken page.',
            'screenshot' => $this->upload('screenshot.png', $this->png()),
            'client_context' => [
                'route' => '/monitors/42',
            ],
        ])->assertCreated();

        $this->assertNull(SupportConversation::query()->sole()->client_context);
        $this->assertSame(0, SupportMessageAttachment::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_screenshot_is_stored_as_an_auto_screenshot_while_diagnostics_is_on(): void
    {
        config()->set('magic-starter.features', [
            Features::support(),
            Features::supportAttachments(),
            Features::supportDiagnostics(),
        ]);
        $opener = $this->user('opener@example.com');

        $this->actingAs($opener, 'sanctum');
        $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'Broken page.',
            'screenshot' => $this->upload('screenshot.png', $this->png()),
            'attachments' => [
                $this->upload('chart.png', $this->png()),
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'data.messages.0.attachments');

        $origins = SupportMessageAttachment::query()->pluck('origin', 'original_name')->map->value->sortKeys()->all();
        $this->assertSame([
            'chart.png' => 'upload',
            'screenshot.png' => 'auto_screenshot',
        ], $origins);
        $this->assertCount(2, $this->storedFiles());
    }

    public function test_the_public_thread_payload_carries_no_attachments(): void
    {
        $opener = $this->user('opener@example.com');
        $conversation = $this->openWithFiles($opener, [
            $this->upload('chart.png', $this->png()),
        ]);

        $this->getJson("/support/conversations/{$conversation->getKey()}")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages.0.attachments');

        $token = SupportThreadToken::mint($conversation);
        $public = $this->getJson("/support/public/thread/{$token}");

        $public->assertOk()->assertJsonCount(1, 'data.messages');
        $this->assertArrayNotHasKey('attachments', $public->json('data.messages.0'));
    }

    public function test_deleting_the_account_deletes_its_files_after_commit(): void
    {
        // The rows go by the database cascade, as on PostgreSQL; SQLite enforces it only when asked.
        Schema::enableForeignKeyConstraints();
        $opener = $this->user('opener@example.com');
        $other = $this->user('other@example.com');
        $this->openWithFiles($opener, [
            $this->upload('chart.png', $this->png()),
            $this->upload('invoice.pdf', $this->pdf()),
        ]);
        $this->openWithFiles($other, [
            $this->upload('mine.png', $this->png()),
        ]);
        $kept = SupportMessageAttachment::query()->where('original_name', 'mine.png')->sole()->path;
        $this->assertCount(3, $this->storedFiles());

        app(DeletesUsers::class)->delete($opener->fresh());

        $this->assertSame([$kept], $this->storedFiles());
        $this->assertSame(1, SupportMessageAttachment::query()->count());
    }

    /**
     * Open a conversation as `$user` with the given files and stay signed in as them.
     *
     * @param  list<UploadedFile>  $files
     */
    private function openWithFiles(SupportAttachmentTestUser $user, array $files): SupportConversation
    {
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/support/conversations', [
            'topic' => 'technical',
            'body' => 'My checks fail.',
            'attachments' => $files,
        ]);

        $this->assertCreatedOrFail($response);

        return SupportConversation::query()->findOrFail($response->json('data.id'));
    }

    private function assertCreatedOrFail(TestResponse $response): void
    {
        if ($response->status() !== 201) {
            throw new RuntimeException('Opening failed: ' . $response->getContent());
        }
    }

    private function user(string $email, ?string $team = null): SupportAttachmentTestUser
    {
        return SupportAttachmentTestUser::create([
            'name' => 'Customer',
            'email' => $email,
            'current_team_id' => $team,
        ]);
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        $files = Storage::disk('local')->allFiles();
        sort($files);

        return $files;
    }

    /**
     * A real upload on a real temporary path, so its type is sniffed from its bytes.
     */
    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'msl-attachment-');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));

        return $image;
    }

    private function png(int $width = 40, int $height = 20): string
    {
        ob_start();
        imagepng($this->canvas($width, $height));

        return (string) ob_get_clean();
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg($this->canvas(40, 20), null, 90);

        return (string) ob_get_clean();
    }

    private function webp(): string
    {
        ob_start();
        imagewebp($this->canvas(40, 20), null, 90);

        return (string) ob_get_clean();
    }

    private function pdf(int $padding = 0): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            . '%' . str_repeat('x', $padding) . "\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * A PNG of a signature, an IHDR claiming the given size and an IEND, and no pixel data at all.
     */
    private function pngClaiming(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IEND', '');
    }

    /**
     * A JPEG, red on the left half and blue on the right, whose EXIF says
     * orientation 6 (display rotated a quarter turn clockwise) and carries a GPS block.
     */
    private function jpegWithExif(int $width = 80, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);
        $half = intdiv($width, 2);
        imagefilledrectangle($image, 0, 0, $half - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, $half, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();

        // Little-endian TIFF: IFD0 at 8 holds Orientation and the GPS pointer (2 + 2 * 12 + 4 = 30 bytes), so
        // the GPS IFD starts at 38 and holds the version and a latitude reference.
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 2)
            . pack('vvVvv', 0x0112, 3, 1, 6, 0)
            . pack('vvVV', 0x8825, 4, 1, 38)
            . pack('V', 0)
            . pack('v', 2)
            . pack('vvV', 0x0000, 1, 4) . "\x02\x02\x00\x00"
            . pack('vvV', 0x0001, 2, 2) . "N\x00\x00\x00"
            . pack('V', 0);
        $payload = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(GdImage $image, int $x, int $y): array
    {
        $color = imagecolorat($image, $x, $y);

        return [
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF,
        ];
    }
}

/**
 * A customer without teams that can hold Sanctum tokens, so account deletion runs end to end.
 */
class SupportAttachmentTestUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;
    use ConditionallyUsesUuids;
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];
}
