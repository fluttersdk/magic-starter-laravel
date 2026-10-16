<?php

namespace FlutterSdk\MagicStarter\Tests\Notifications;

use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/**
 * The mails a package user receives read in the recipient's language.
 *
 * The password reset mail is Laravel's own: its strings are English JSON keys that
 * the framework ships no translation for, so a Turkish recipient got an English mail
 * until the package registered `resources/lang/tr.json`. Every Turkish assertion compares
 * against the literal text, never only against "not English", because a mail that is
 * wrongly Turkish passes the second kind of check.
 */
final class MailTranslationTest extends TestCase
{
    /**
     * An app's `lang` directory, written per test: `lang/tr.json` overrides the reset
     * subject and `lang/vendor/magic-starter/tr/mail.php` overrides ONE invitation key.
     */
    private string $appLang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appLang = sys_get_temp_dir() . '/magic-starter-mail-' . bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->appLang . '/vendor/magic-starter/tr');
        File::put(
            $this->appLang . '/tr.json',
            json_encode(['Reset Password Notification' => 'Uygulamanin Sifre Konusu'], JSON_THROW_ON_ERROR),
        );
        File::put(
            $this->appLang . '/vendor/magic-starter/tr/mail.php',
            "<?php\n\nreturn ['invitation' => ['subject' => 'Uygulamanin Davet Konusu']];\n",
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->appLang);

        parent::tearDown();
    }

    public function test_the_reset_mail_is_turkish_under_tr(): void
    {
        config(['auth.defaults.passwords' => 'users', 'auth.passwords.users.expire' => 60]);
        app()->setLocale('tr');

        $mail = (new ResetPassword('tok-123'))->toMail(new MailTranslationTestUser(['email' => 'ada@example.test']));

        $this->assertSame('Şifre Sıfırlama Bildirimi', $mail->subject);
        $this->assertSame('Şifreyi Sıfırla', $mail->actionText);
        $this->assertSame([
            'Hesabınız için bir şifre sıfırlama isteği aldığımız için bu e-postayı alıyorsunuz.',
        ], $mail->introLines);
        $this->assertSame([
            'Bu şifre sıfırlama bağlantısının geçerlilik süresi 60 dakika içinde dolacak.',
            'Şifre sıfırlama isteğinde bulunmadıysanız herhangi bir işlem yapmanıza gerek yoktur.',
        ], $mail->outroLines);
    }

    public function test_the_reset_mail_stays_english_under_en(): void
    {
        app()->setLocale('en');

        $mail = (new ResetPassword('tok-123'))->toMail(new MailTranslationTestUser(['email' => 'ada@example.test']));

        $this->assertSame('Reset Password Notification', $mail->subject);
        $this->assertSame('Reset Password', $mail->actionText);
    }

    /**
     * Laravel 13 renamed the subject, and a package that carried only the 12 key
     * would turn Turkish again on the day an adopter upgrades.
     */
    public function test_the_laravel_13_reset_subject_is_translated_too(): void
    {
        app()->setLocale('tr');

        $this->assertSame('Şifrenizi sıfırlayın', __('Reset your password'));
    }

    public function test_the_mail_template_chrome_is_turkish_under_tr(): void
    {
        app()->setLocale('tr');

        $this->assertSame('Merhaba!', __('Hello!'));
        $this->assertSame('Hay aksi!', __('Whoops!'));
        $this->assertSame('Saygılarımızla,', __('Regards,'));
        $this->assertSame('Tüm hakları saklıdır.', __('All rights reserved.'));
    }

    /**
     * The key is copied from the framework template and holds a real newline, so a
     * hand-typed line that differs by one character silently stops matching.
     */
    public function test_the_subcopy_line_matches_the_framework_key_byte_for_byte(): void
    {
        app()->setLocale('tr');

        $key = "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n"
            . 'into your web browser:';

        $this->assertTrue(Lang::has($key, 'tr', false));
        $this->assertSame(
            "\"Şifreyi Sıfırla\" düğmesine tıklamakta sorun yaşıyorsanız, aşağıdaki bağlantıyı\n"
            . 'tarayıcınıza kopyalayıp yapıştırın:',
            __($key, ['actionText' => 'Şifreyi Sıfırla']),
        );
    }

    /**
     * The `magic-starter-lang` tag publishes the whole `lang/` directory to `lang/vendor/magic-starter/`, where Laravel
     * reads no JSON file, so a `tr.json` in it would be edited by an adopter and never loaded.
     */
    public function test_the_json_catalogue_sits_outside_the_published_lang_directory(): void
    {
        $this->assertFileDoesNotExist(__DIR__ . '/../../lang/tr.json');
        $this->assertFileExists(__DIR__ . '/../../resources/lang/tr.json');
    }

    public function test_an_app_json_file_overrides_the_package_value(): void
    {
        Lang::addJsonPath($this->appLang);
        app()->setLocale('tr');

        $mail = (new ResetPassword('tok-123'))->toMail(new MailTranslationTestUser(['email' => 'ada@example.test']));

        $this->assertSame('Uygulamanin Sifre Konusu', $mail->subject);
        $this->assertSame('Şifreyi Sıfırla', $mail->actionText, 'A key the app left alone keeps the package value.');
    }

    public function test_a_namespace_override_changes_only_the_key_it_names(): void
    {
        Lang::getLoader()->addPath($this->appLang);
        app()->setLocale('tr');

        $mail = (new TeamInvitationNotification(new MailTranslationTestInvitation))->toMail(null);

        $this->assertSame('Uygulamanin Davet Konusu', $mail->subject);
        $this->assertSame('Daveti kabul edin', $mail->actionText);
    }
}

final class MailTranslationTestUser extends Model
{
    protected $guarded = [];

    public function getEmailForPasswordReset(): string
    {
        return $this->getAttribute('email');
    }
}

final class MailTranslationTestInvitation extends Model
{
    protected $guarded = [];

    public function __construct()
    {
        parent::__construct(['token' => 'tok-123']);

        $this->setRelation('team', new MailTranslationTestTeam(['name' => 'Acme Ops']));
    }
}

final class MailTranslationTestTeam extends Model
{
    protected $guarded = [];
}
