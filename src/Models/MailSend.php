<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One mail OneSignal accepted: its OneSignal id, the mail class that produced it,
 * how many addresses it went to and how many of those OneSignal skipped.
 *
 * Never an address, subject or body: `recipient_hash` is the sha256 of the first
 * lowercased To address. Rows are written once and never updated, which is why the
 * table carries `created_at` only.
 *
 * @property int|string $id
 * @property string $onesignal_id
 * @property string|null $mail_class
 * @property string $recipient_hash
 * @property int $recipients
 * @property int $invalid_recipients
 * @property Carbon|null $created_at
 */
class MailSend extends Model
{
    use ConditionallyUsesUuids;

    public const UPDATED_AT = null;

    protected $table = 'magic_starter_mail_sends';

    /** @var list<string> */
    protected $fillable = [
        'onesignal_id',
        'mail_class',
        'recipient_hash',
        'recipients',
        'invalid_recipients',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'integer',
            'invalid_recipients' => 'integer',
        ];
    }
}
