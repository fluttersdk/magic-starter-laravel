<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Newsletter subscriber model for opt-in email subscriptions.
 *
 * @property string $id
 * @property string $email
 * @property bool $is_active
 * @property string|null $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NewsletterSubscriber extends Model
{
    use ConditionallyUsesUuids;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'is_active',
        'source',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
