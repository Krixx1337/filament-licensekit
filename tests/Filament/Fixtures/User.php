<?php

namespace LicenseKit\Tests\Filament\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail
{
    use HasFactory;

    public function getFilamentName(): string
    {
        return $this->email;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    protected $table = 'users';

    protected $guarded = [];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'immutable_datetime', 'is_suspended' => 'boolean'];
    }
}
