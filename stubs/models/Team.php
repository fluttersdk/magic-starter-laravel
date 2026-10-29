<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Models\Team as MagicStarterTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends MagicStarterTeam
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'personal_team',
        'profile_photo_path',
    ];
}
