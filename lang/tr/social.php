<?php

return [

    /*
     * Social sign-in outcomes and error messages for OAuth flows. See the
     * English file for why these are shipped rather than inlined.
     */

    /*
     * Email address conflict: an account already holds this email and the
     * social provider returned it. Phrased as the client would tell a user
     * who tried to sign up with a provider but the email was taken.
     */
    'social_email_taken' => 'Bu e-posta adresiyle zaten bir hesap var. Giriş yapın ve sağlayıcıyı profilinizden bağlayın.',

    /*
     * Social account already linked: the same provider returned a different
     * identity than the account's existing link for that provider.
     */
    'social_account_taken' => 'Bu sağlayıcı hesabı başka bir hesaba zaten bağlı.',

    /*
     * Last login method: the account is password-only and the user tried to
     * sign in with an unlinked provider, or tried to unlink it after the
     * password was deleted. Verb order: Turkish ends the clause with the verb.
     */
    'last_login_method' => 'Bu sizin tek giriş yönteminiz. Bunu kaldırmadan önce bir şifre ekleyin veya başka bir sağlayıcı bağlayın.',

    /*
     * Provider not in the allowlist: the client sent a provider name that the
     * server has not configured.
     */
    'provider_not_supported' => 'Bu sağlayıcı desteklenmiyor.',

    /*
     * Provider is enabled but not configured: the server allows it but lacks
     * the credentials or platform configuration to complete the flow.
     */
    'platform_not_configured' => 'Bu sağlayıcı bu platformda yapılandırılmamış. Daha sonra tekrar deneyiniz.',

    /*
     * OAuth state or code token expired: the flow took longer than allowed.
     */
    'flow_expired' => 'Giriş yapma işleminiz çok uzun sürdü. Lütfen tekrar deneyiniz.',

    /*
     * Identity data mismatch: the provider returned a different subject than
     * expected, or no subject at all.
     */
    'invalid_identity' => 'Kimliğiniz doğrulanamadı. Tekrar deneyiniz.',

    /*
     * Provider did not return an email: the account was created with a
     * provider that yields no email address.
     */
    'provider_email_missing' => 'Sağlayıcınız e-posta adresi sağlamadı. Başka bir sağlayıcı kullanınız veya e-posta ile kaydolunuz.',

    /*
     * Password already set: the account has a password and the user tried to
     * set one again.
     */
    'password_already_set' => 'Hesabınızda zaten bir şifre ayarlanmış.',

    /*
     * User owns shared teams: the account tried to delete itself but is the
     * sole owner of one or more teams.
     */
    'owns_shared_teams' => 'Sizin sahip olduğunuz takımlar var, bunları silemezsiniz. Hesabınızı silmeden önce sahipliği devreyiniz veya takımları siliniz.',

    /*
     * Team has active subscription: a team owned by this account has an active
     * subscription and cannot be deleted.
     */
    'team_has_active_subscription' => 'Takımlarınızdan birinin aktif bir aboneliği var. Lütfen önce iptal ediniz.',

    /*
     * Account deletion scheduled: the account deletion was initiated, and the
     * account is now in a grace period.
     */
    'deletion_scheduled' => 'Hesabınız :days gün sonra silinecek. Bunu istediğiniz zaman iptal edebilirsiniz.',

    /*
     * Deletion cancellation successful: the user cancelled a pending account
     * deletion during the grace period.
     */
    'deletion_cancelled' => 'Hesap silme işlemi iptal edildi. Hesabınız yeniden aktif.',

    /*
     * Step-up required: a sensitive action requires re-authentication or a
     * second factor.
     */
    'step_up_required' => 'Devam etmek için lütfen kimliğinizi onaylayınız.',

];
