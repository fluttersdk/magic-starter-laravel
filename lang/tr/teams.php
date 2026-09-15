<?php

return [

    /*
     * Names the package WRITES into a row, rather than sentences it answers
     * with. They are resolved once, at creation, in the locale the account
     * carries at that moment, and the stored value never re-resolves.
     */
    'personal_team_name' => ':name Takımı',
    'guest_name' => 'Misafir',

    /*
     * Outcomes and refusals on the team itself. `not_a_member` answers two
     * endpoints with one sentence, because the reader is being told the same
     * thing about the same relationship and the status code already
     * distinguishes the two cases.
     */
    'deleted' => 'Takım silindi.',
    'switched' => 'Takım değiştirildi',
    'not_found' => 'Seçilen takım bulunamadı.',
    'not_a_member' => 'Bu takımın üyesi değilsiniz.',
    'personal_team_undeletable' => 'Kişisel takımınızı silemezsiniz.',

    /*
     * Membership. The three owner refusals stay separate because they refuse
     * three different actions, and only the last can name a way forward.
     */
    'members' => [
        'updated' => 'Takım üyesi güncellendi.',
        'removed' => 'Takım üyesi çıkarıldı.',
        'left' => 'Takımdan ayrıldınız.',
        'owner_role_locked' => 'Takım sahibinin rolü değiştirilemez.',
        'owner_not_removable' => 'Takım sahibi takımdan çıkarılamaz.',
        'owner_cannot_leave' => 'Takım sahibi takımdan ayrılamaz. Önce sahipliği devredin ya da takımı silin.',
        'user_not_found' => 'Seçilen kullanıcı bulunamadı.',
        'already_a_member' => 'Bu kullanıcı zaten takımın üyesi.',
    ],

    /*
     * Invitations. The two "already a member" lines address opposite people:
     * `members.already_a_member` tells an inviter about somebody else,
     * `invitations.already_joined` tells the invited person about themselves.
     */
    'invitations' => [
        'already_sent' => 'Bu e-posta adresine zaten bir davet gönderilmiş.',
        'canceled' => 'Davet iptal edildi.',
        'wrong_email' => 'Bu davet farklı bir e-posta adresine gönderilmiş.',
        'expired' => 'Bu davetin süresi dolmuş.',
        'already_joined' => 'Bu takımın zaten üyesisiniz.',
        'accepted' => 'Davet kabul edildi. Takıma katıldınız.',
    ],

];
