<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property bool $personal_team
 * @property string|null $profile_photo_path
 * @property-read  string  $profile_photo_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read  Model $owner
 * @property-read  Collection<int, Model>  $users
 * @property-read  Collection<int, TeamInvitation>  $invitations
 */
abstract class Team extends Model
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }

    /**
     * Get the owner of the team.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'user_id');
    }

    /**
     * Get all of the users that belong to the team.
     *
     * @return BelongsToMany<Model, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(MagicStarter::userModel())
            ->using(MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get all of the pending invitations for the team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(MagicStarter::teamInvitationModel());
    }

    /**
     * Get the team's profile photo URL.
     *
     * @return Attribute<string, never>
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::get(function (): string {
            return $this->profile_photo_path
                ? Storage::disk(config('magic-starter.profile_photo_disk', config('filesystems.default')))->url($this->profile_photo_path)
                : $this->defaultProfilePhotoUrl();
        });
    }

    /**
     * Get the default profile photo URL for the team.
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $initials = [];

        foreach (preg_split('/\s+/', trim((string) $this->name)) ?: [] as $segment) {
            if ($segment !== '') {
                $initials[] = mb_substr($segment, 0, 1);
            }
        }

        $name = implode(' ', $initials);

        $baseUrl = rtrim((string) config('magic-starter.ui_avatars_url', 'https://ui-avatars.com/api/'), '/');

        return $baseUrl . '/?name=' . urlencode($name) . '&color=7F9CF5&background=EBF4FF';
    }
}
