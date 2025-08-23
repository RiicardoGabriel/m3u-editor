<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function user()
    {
        return $this->belongsTo(User::class, 'tokenable_id')
            ->where('tokenable_type', User::class);
    }
}
