<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property bool $personal_team
 * @property string|null $profile_photo_path
 * @property-read  string  $profile_photo_url
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read  \Illuminate\Database\Eloquent\Model  $owner
 * @property-read  \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>  $users
 * @property-read  \Illuminate\Database\Eloquent\Collection<int, \FlutterSdk\MagicStarter\Models\TeamInvitation>  $invitations
 */
class Team extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'name',
        'personal_team',
        'profile_photo_path',
    ];

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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(MagicStarter::userModel(), 'user_id');
    }

    /**
     * Get all of the users that belong to the team.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(MagicStarter::userModel())
            ->using(TeamUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get all of the pending invitations for the team.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\FlutterSdk\MagicStarter\Models\TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the team's profile photo URL.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute<string, never>
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

        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&color=7F9CF5&background=EBF4FF';
    }
}
