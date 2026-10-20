<?php

namespace FlutterSdk\MagicStarter\Tests\Mail;

use FlutterSdk\MagicStarter\Console\PruneMailSendsCommand;
use FlutterSdk\MagicStarter\Mail\OneSignalTransport;
use FlutterSdk\MagicStarter\Models\MailSend;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;

/**
 * Locks the delivery record: every mail OneSignal accepts leaves one row that
 * holds the OneSignal id, the mail class, counts and a hash of the first address,
 * never the address itself, and a bookkeeping failure never fails that mail.
 */
final class MailSendRecordTest extends TestCase
{
    private const ADDRESS = 'Secret.Person@Example.org';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.use_uuids' => false,
            'magic-starter.onesignal.app_id' => 'app-xyz',
            'magic-starter.onesignal.rest_api_key' => 'rest-key',
        ]);
    }

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function keyModes(): iterable
    {
        yield 'uuid keys' => [true];
        yield 'integer keys' => [false];
    }

    #[DataProvider('keyModes')]
    public function test_an_accepted_send_leaves_one_row_with_a_hash_and_no_address(bool $useUuids): void
    {
        // Arrange
        config(['magic-starter.use_uuids' => $useUuids]);
        $this->migrateSends();
        $this->clientAccepting('notif-1', ['invalid_email_tokens' => ['other@example.org']]);

        // Act
        $this->transport()->send($this->email([self::ADDRESS, 'other@example.org']));

        // Assert
        $this->assertSame(1, MailSend::query()->count());

        $row = MailSend::query()->firstOrFail();
        $this->assertSame($useUuids, is_string($row->getKey()));
        $this->assertSame('notif-1', $row->onesignal_id);
        $this->assertNull($row->mail_class);
        $this->assertSame(hash('sha256', 'secret.person@example.org'), $row->recipient_hash);
        $this->assertSame(2, $row->recipients);
        $this->assertSame(1, $row->invalid_recipients);
        $this->assertNotNull($row->created_at);

        foreach ($row->getAttributes() as $value) {
            $this->assertStringNotContainsString('@', (string) $value);
            $this->assertStringNotContainsStringIgnoringCase('secret', (string) $value);
        }
    }

    public function test_the_table_has_no_column_for_an_address_a_subject_or_a_body(): void
    {
        // Arrange + Act
        $this->migrateSends();

        // Assert
        $this->assertTrue(Schema::hasColumns('magic_starter_mail_sends', [
            'id',
            'onesignal_id',
            'mail_class',
            'recipient_hash',
            'recipients',
            'invalid_recipients',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('magic_starter_mail_sends', 'updated_at'));
        $this->assertTrue(Schema::hasIndex('magic_starter_mail_sends', ['onesignal_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('magic_starter_mail_sends', ['created_at']));
    }

    public function test_a_send_with_no_invalid_tokens_records_zero(): void
    {
        // Arrange
        $this->migrateSends();
        $this->clientAccepting('notif-1');

        // Act
        $this->transport()->send($this->email([self::ADDRESS]));

        // Assert
        $row = MailSend::query()->firstOrFail();
        $this->assertSame(1, $row->recipients);
        $this->assertSame(0, $row->invalid_recipients);
    }

    public function test_a_database_failure_is_reported_and_the_accepted_send_still_succeeds(): void
    {
        // Arrange: the table was never published, so the insert throws.
        $this->assertFalse(Schema::hasTable('magic_starter_mail_sends'));
        $this->clientAccepting('notif-1');

        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->once())
            ->method('report')
            ->with($this->isInstanceOf(QueryException::class));
        $this->app->instance(ExceptionHandler::class, $handler);

        // Act
        $sent = $this->transport()->send($this->email([self::ADDRESS]));

        // Assert: the mail was accepted, so the transport answered instead of throwing.
        $this->assertNotNull($sent);
    }

    public function test_the_mail_class_is_set_when_the_send_goes_through_the_router(): void
    {
        // Arrange
        $this->migrateSends();
        $this->clientAccepting('notif-routed');
        config([
            'mail.mailers.routed' => ['transport' => 'magic-starter-router', 'default' => 'default-sink'],
            'mail.mailers.default-sink' => ['transport' => 'array'],
            'mail.mailers.onesignal' => ['transport' => 'onesignal'],
            'mail.mailers.onesignal-failover' => ['transport' => 'failover', 'mailers' => ['onesignal', 'array']],
            'magic-starter.mail.onesignal.enabled' => true,
            'magic-starter.mail.onesignal.classes' => ['SendRecordTestMailable'],
            'magic-starter.mail.onesignal.mailer' => 'onesignal-failover',
        ]);

        // Act
        Mail::mailer('routed')->to('a@example.test')->send(new SendRecordTestMailable);

        // Assert
        $row = MailSend::query()->firstOrFail();
        $this->assertSame('notif-routed', $row->onesignal_id);
        $this->assertSame(SendRecordTestMailable::class, $row->mail_class);
    }

    public function test_the_retention_ships_at_thirty_days(): void
    {
        $this->refreshApplication();

        $this->assertSame(30, config('magic-starter.mail.onesignal.retention_days'));
    }

    public function test_prune_deletes_rows_older_than_the_retention_and_keeps_the_rest(): void
    {
        // Arrange
        $this->migrateSends();
        config(['magic-starter.mail.onesignal.retention_days' => 30]);
        $this->seedSend('old', 31);
        $this->seedSend('recent', 29);

        // Act
        $this->artisan(PruneMailSendsCommand::NAME)
            ->expectsOutputToContain('Pruned 1 mail send row')
            ->assertSuccessful();

        // Assert
        $this->assertSame(['recent'], MailSend::query()->pluck('onesignal_id')->all());
    }

    public function test_prune_never_wipes_everything_when_the_retention_is_zero(): void
    {
        // Arrange
        $this->migrateSends();
        config(['magic-starter.mail.onesignal.retention_days' => 0]);
        $this->seedSend('two-days', 2);
        $this->seedSend('today', 0);

        // Act
        $this->artisan(PruneMailSendsCommand::NAME)
            ->expectsOutputToContain('Pruned 1 mail send row older than 1 days')
            ->assertSuccessful();

        // Assert: a retention below one day counts as one day.
        $this->assertSame(['today'], MailSend::query()->pluck('onesignal_id')->all());
    }

    public function test_prune_with_nothing_old_deletes_nothing_and_says_so(): void
    {
        // Arrange
        $this->migrateSends();
        $this->seedSend('recent', 1);

        // Act
        $this->artisan(PruneMailSendsCommand::NAME)
            ->expectsOutputToContain('Pruned 0 mail send rows')
            ->assertSuccessful();

        // Assert
        $this->assertSame(1, MailSend::query()->count());
    }

    public function test_prune_skips_when_the_table_was_never_published(): void
    {
        $this->artisan(PruneMailSendsCommand::NAME)
            ->expectsOutputToContain('Skipped pruning')
            ->assertSuccessful();
    }

    public function test_prune_is_registered_and_scheduled_daily(): void
    {
        $this->assertArrayHasKey(PruneMailSendsCommand::NAME, Artisan::all());

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, PruneMailSendsCommand::NAME));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function migrateSends(): void
    {
        (require __DIR__ . '/../../database/migrations/create_magic_starter_mail_sends_table.php')->up();
    }

    private function seedSend(string $onesignalId, int $daysOld): void
    {
        $send = new MailSend;
        $send->forceFill([
            'onesignal_id' => $onesignalId,
            'recipient_hash' => hash('sha256', $onesignalId),
            'recipients' => 1,
            'invalid_recipients' => 0,
            'created_at' => now()->subDays($daysOld),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function clientAccepting(string $id, array $errors = []): void
    {
        $client = $this->createMock(DefaultApi::class);
        $client->method('createNotification')
            ->willReturn(new CreateNotificationSuccessResponse(['id' => $id, 'errors' => $errors]));
        $this->app->instance(DefaultApi::class, $client);
    }

    private function transport(): OneSignalTransport
    {
        return new OneSignalTransport($this->app);
    }

    /**
     * @param  list<string>  $to
     */
    private function email(array $to): Email
    {
        return (new Email)
            ->from('hello@example.test')
            ->to(...$to)
            ->subject('Welcome')
            ->html('<p>Hello</p>')
            ->text('Hello');
    }
}

final class SendRecordTestMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Send record');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hello</p>');
    }
}
