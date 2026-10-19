<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Database\Factories\SupportArticleFactory;
use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Observers\SupportArticleObserver;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One knowledge-base article, in as many locales as staff wrote it.
 *
 * Global to the app, not to a team. Readers narrow it with {@see scopeVisibleTo()},
 * {@see scopeLive()} and {@see scopeApprovedIn()}; none of them is implied by the
 * others. A save or a delete drops the cached corpus through
 * {@see SupportArticleObserver}.
 *
 * @property string|int $id
 * @property string $slug
 * @property string $section
 * @property int $sort
 * @property SupportArticleVisibility $visibility
 * @property Carbon|null $published_at
 * @property array<string, string> $question Plain text, keyed by locale.
 * @property array<string, string> $body Markdown, keyed by locale. Never HTML.
 * @property array<string, string> $locale_state `approved` or `draft`, keyed by locale.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ObservedBy(SupportArticleObserver::class)]
class SupportArticle extends Model
{
    use ConditionallyUsesUuids;

    /** @use HasFactory<SupportArticleFactory> */
    use HasFactory;

    public const LOCALE_APPROVED = 'approved';

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'section',
        'sort',
        'visibility',
        'published_at',
        'question',
        'body',
        'locale_state',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'visibility' => SupportArticleVisibility::class,
            'published_at' => 'datetime',
            'question' => 'array',
            'body' => 'array',
            'locale_state' => 'array',
        ];
    }

    /**
     * Rows the audience may read, by visibility alone.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, SupportAudience $audience): Builder
    {
        return $query->whereIn(
            'visibility',
            array_map(
                static fn (SupportArticleVisibility $visibility): string => $visibility->value,
                $audience->visibilities(),
            ),
        );
    }

    /**
     * Rows whose publish date has passed.
     *
     * Compared with the clock on every call and never stored as a flag, so an
     * article dated tomorrow goes live on time without anyone writing to it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Rows whose text in the locale has been approved, not left as a draft.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApprovedIn(Builder $query, string $locale): Builder
    {
        return $query->where('locale_state->' . $locale, self::LOCALE_APPROVED);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): SupportArticleFactory
    {
        return SupportArticleFactory::new();
    }
}
