<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use Illuminate\Contracts\Filesystem\Filesystem;
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

    public function test_profile_photo_url_uses_the_default_disk_when_none_is_configured(): void
    {
        config([
            'magic-starter.profile_photo_disk' => null,
            'filesystems.default' => 'profile-photos',
        ]);

        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Doe';
        $user->profile_photo_path = 'avatars/alice.png';

        $this->assertSame(
            'https://cdn.example.test/profile-photos/avatars/alice.png',
            $user->profile_photo_url,
        );
    }

    public function test_a_disk_that_cannot_build_urls_answers_the_stored_path(): void
    {
        // A custom driver registered through Storage::extend can hand back a
        // bare Filesystem with no url(); the stored path is the best answer
        // left rather than a fatal on every serialised user.
        app('filesystem')->set('bare', $this->createStub(Filesystem::class));
        config(['magic-starter.profile_photo_disk' => 'bare']);

        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Doe';
        $user->profile_photo_path = 'avatars/alice.png';

        $this->assertSame('avatars/alice.png', $user->profile_photo_url);
    }

    public function test_profile_photo_url_is_null_when_no_photo_is_stored(): void
    {
        // The client draws its own initials in its own theme. A generated
        // image cost a third-party round trip per avatar, sent the person's
        // name to that third party, failed offline, arrived in colours the
        // client did not choose, and could not be told apart from an upload.
        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Bob';
        $user->profile_photo_path = null;

        $this->assertNull($user->profile_photo_url);
    }

    public function test_an_empty_stored_path_is_no_photo(): void
    {
        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Bob';
        $user->profile_photo_path = '';

        $this->assertNull($user->profile_photo_url);
    }

    public function test_a_published_config_still_naming_ui_avatars_generates_nothing(): void
    {
        // Every install that published config/magic-starter.php before this
        // release carries the old `ui_avatars_url` key with the old URL in it,
        // and `mergeConfigFrom` never overwrites a published key. A default
        // flipped in the package's own config would have reached none of them.
        config(['magic-starter.ui_avatars_url' => 'https://ui-avatars.com/api/']);

        $user = new HasProfilePhotoTestUser;
        $user->name = 'Alice Bob';
        $user->profile_photo_path = null;

        $this->assertNull($user->profile_photo_url);
    }
}

final class HasProfilePhotoTestUser extends Model
{
    use HasProfilePhoto;

    protected $guarded = [];
}
