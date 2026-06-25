<?php

namespace App\Support;

use App\Models\User;

class AuthRedirect
{
    public static function home(?User $user): string
    {
        return $user?->isAdmin()
            ? route('dashboard', absolute: false)
            : route('home', absolute: false);
    }

    public static function verifiedHome(?User $user): string
    {
        return self::home($user).'?verified=1';
    }
}
