<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

final class HasProfilePhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.profile-photos' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/profile-photos'),
            'url' => 'https://cdn.example.test/profile-photos',
            'visibility' => 'public',
        ]]);
    }

    public function test_profile_photo_url_uses_configured_disk_when_path_exists(): void
    {
        config(['magic-starter.profile_photo_disk' => 'profile-photos']);

        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Doe';
        $user->profile_photo_path = 'avatars/alice.png';

        $this->assertSame(
            'https://cdn.example.test/profile-photos/avatars/alice.png',
            $user->profile_photo_url,
        );
    }

    public function test_profile_photo_url_falls_back_to_default_avatar_when_missing_path(): void
    {
        config(['magic-starter.profile_photo_disk' => 'profile-photos']);

        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Bob';
        $user->profile_photo_path = null;

        $this->assertSame(
            'https://ui-avatars.com/api/?name=A+B&color=FFFFFF&background=009E60',
            $user->profile_photo_url,
        );
    }
}

final class HasProfilePhotoTestUser extends Model
{
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;

    protected $guarded = [];
}
