<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Events\LicenseAccessInvalidated;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Models\License;
use LicenseKit\Tests\Core\Fixtures\HostDesktopController;
use LicenseKit\Tests\Core\Fixtures\HostUserSecurityChanged;
use LicenseKit\Tests\Core\Fixtures\User;
use LicenseKit\Tests\Core\TestCase;

pest()->extend(TestCase::class)->in('Core/Feature');

/** @return array{login: string, password: string, hwid: array{value: string}} */
function lockedDeviceCount(): int
{
    return License::whereNotNull('device')->count();
}

function desktopRequest(User $user, string $device = 'desktop'): array
{
    return ['login' => $user->email, 'password' => 'password', 'hwid' => $device];
}

function installHostDesktopProtocol(): void
{
    Schema::create('host_desktop_sessions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('product_id');
        $table->string('token_hash')->unique();
        $table->string('fingerprint');
        $table->json('hwid');
        $table->dateTime('expires_at');
    });
    Route::middleware(['api', 'licensekit.json'])->post('host/products/{product:slug}/login', [HostDesktopController::class, 'login']);
    Route::middleware(['api', 'licensekit.json'])->post('host/products/{product:slug}/heartbeat', [HostDesktopController::class, 'heartbeat']);
    Event::listen(HostUserSecurityChanged::class, fn (HostUserSecurityChanged $event) => DB::table('host_desktop_sessions')->where('user_id', $event->user->getKey())->delete());
    Event::listen(ProductAccessInvalidated::class, fn (ProductAccessInvalidated $event) => DB::table('host_desktop_sessions')->where('user_id', $event->user->getKey())->when($event->productId !== null, fn ($query) => $query->where('product_id', $event->productId))->delete());
    Event::listen(LicenseAccessInvalidated::class, fn (LicenseAccessInvalidated $event) => DB::table('host_desktop_sessions')->where('user_id', $event->license->user_id)->where('product_id', $event->license->product_id)->delete());
}

function testAdminActor(): User
{
    $actor = User::factory()->create();
    Gate::define('licensekit.admin', fn (): bool => true);

    return $actor;
}
