<?php

namespace App\Support;

use App\Models\User;

class AuthRedirect
{
    public static function home(?User $user): string
    {
        if ($user?->canAccessDatacrypt()) {
            return route('datacrypt.hub', absolute: false);
        }

        return route('home', absolute: false);
    }

    public static function verifiedHome(?User $user): string
    {
        return self::home($user).'?verified=1';
    }
}
