<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 用户模型（含平台超管 + 租户用户）。
 *
 * tenant_id 为 null 表示平台超管；非 null 表示租户级用户。
 * role 区分 platform_admin / tenant_admin / trader / viewer。
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Filament 5.x：用户能否访问指定 Panel。
     *
     * - platform Panel：仅 platform_admin 角色 + tenant_id=null 的用户
     * - admin Panel：租户级用户（tenant_id 非 null）
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'platform' => $this->role === 'platform_admin' && $this->tenant_id === null,
            'admin' => $this->tenant_id !== null,
            default => false,
        };
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'email_verified_at',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
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

    /**
     * 归属租户（tenant_id 为 null 时为平台超管，关联为 null）。
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 是否为平台超管。
     */
    public function isPlatformAdmin(): bool
    {
        return $this->role === 'platform_admin';
    }
}
