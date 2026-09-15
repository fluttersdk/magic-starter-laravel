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

    public function test_an_empty_ui_avatars_url_sends_null_instead_of_a_generated_image(): void
    {
        // What a JSON client usually wants: it draws its own initials already,
        // and a generated image otherwise costs a third-party round trip per
        // avatar, sends the person's name to that third party, fails offline,
        // and cannot be told apart from a real upload.
        config(['magic-starter.ui_avatars_url' => '']);

        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Bob';
        $user->profile_photo_path = null;

        $this->assertNull($user->profile_photo_url);
    }

    public function test_an_uploaded_photo_is_unaffected_by_the_switch(): void
    {
        // The switch governs the FALLBACK only. A real upload still answers,
        // which is the half that would break silently if the null were returned
        // one branch too early.
        config([
            'magic-starter.profile_photo_disk' => 'profile-photos',
            'magic-starter.ui_avatars_url' => '',
        ]);

        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Doe';
        $user->profile_photo_path = 'avatars/alice.png';

        $this->assertSame(
            'https://cdn.example.test/profile-photos/avatars/alice.png',
            $user->profile_photo_url,
        );
    }
}

final class HasProfilePhotoTestUser extends Model
{
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;

    protected $guarded = [];
}
