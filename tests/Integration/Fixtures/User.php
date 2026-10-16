<?php

namespace LicenseKit\Tests\Integration\Fixtures;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;

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
