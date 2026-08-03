<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_BITCRAFT = 'bitcraft';

    public const ROLE_CONNECTED_REALMS = 'connected-realms';

    public const ROLE_WORLD_ENGINE = 'world-engine';

    public const FOOTMOUTHKICK_NAME = 'footmouthkick';

    public const FOOTMOUTHKICK_EMAIL = 'footmouthkick@pm.me';

    public const ACCESS_ROLE_LABELS = [
        self::ROLE_BITCRAFT => 'Bitcraft',
        self::ROLE_CONNECTED_REALMS => 'Evergather',
        self::ROLE_WORLD_ENGINE => 'World Engine',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'site_theme',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->isFootmouthkickUser() && $this->hasRole(self::ROLE_ADMIN);
    }

    public function isFootmouthkickUser(): bool
    {
        return strcasecmp($this->name, self::FOOTMOUTHKICK_NAME) === 0
            || strcasecmp($this->email, self::FOOTMOUTHKICK_EMAIL) === 0;
    }

    public function canAccessBitcraft(): bool
    {
        return $this->isAdmin() || $this->hasRole(self::ROLE_BITCRAFT);
    }

    public function canAccessConnectedRealms(): bool
    {
        return $this->isAdmin() || $this->hasRole(self::ROLE_CONNECTED_REALMS);
    }

    public function canAccessWorldEngine(): bool
    {
        return $this->isAdmin() || $this->hasRole(self::ROLE_WORLD_ENGINE);
    }

    public function canAccessAdmin(): bool
    {
        return $this->isAdmin();
    }

    public function canAccessDatacrypt(): bool
    {
        return $this->canAccessAdmin()
            || $this->canAccessBitcraft()
            || $this->canAccessConnectedRealms()
            || $this->canAccessWorldEngine();
    }

    public function canAccessArea(string $area): bool
    {
        return match ($area) {
            self::ROLE_BITCRAFT => $this->canAccessBitcraft(),
            self::ROLE_CONNECTED_REALMS => $this->canAccessConnectedRealms(),
            self::ROLE_WORLD_ENGINE => $this->canAccessWorldEngine(),
            self::ROLE_ADMIN => $this->canAccessAdmin(),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function accessRoleNames(): array
    {
        return $this->getRoleNames()
            ->filter(fn (string $role): bool => array_key_exists($role, self::ACCESS_ROLE_LABELS))
            ->values()
            ->all();
    }
}
