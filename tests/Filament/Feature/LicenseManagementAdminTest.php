<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('staff extend pause and resume licenses from table', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDay()]);

    Livewire::test(ManageLicenses::class)
        ->assertTableActionHidden('resume', $license)
        ->callTableAction('extend', $license, data: ['duration' => 2, 'unit' => 'hours', 'reason' => 'Support'])
        ->assertHasNoActionErrors();

    expect($license->fresh()->expires_at->toIso8601String())->toBe('2026-01-02T02:00:00+00:00');

    Livewire::test(ManageLicenses::class)
        ->callTableAction('pause', $license, data: ['reason' => 'Vacation'])->assertHasNoActionErrors();

    Livewire::test(ManageLicenses::class)
        ->assertSee('Paused')->assertSee('1d 2h 0m 0s')
        ->assertTableActionHidden('pause', $license)
        ->callTableAction('extend', $license, data: ['duration' => 1, 'unit' => 'days', 'reason' => 'Support'])->assertHasNoActionErrors();
    expect($license->fresh()->remaining_seconds)->toBe(180000);

    $this->travelTo(CarbonImmutable::parse('2026-02-01T00:00:00Z'));
    Livewire::test(ManageLicenses::class)
        ->callTableAction('resume', $license, data: ['reason' => 'Returned'])->assertHasNoActionErrors();

    expect($license->fresh()->expires_at->toIso8601String())->toBe('2026-02-03T02:00:00+00:00');
    expect($license->fresh()->paused_at)->toBeNull();
});

test('staff clock forms reject invalid duration units and reasons', function (array $data, string $field) {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $expiry = $license->expires_at;

    Livewire::test(ManageLicenses::class)
        ->callTableAction('extend', $license, data: [...['duration' => 1, 'unit' => 'days', 'reason' => 'Support'], ...$data])
        ->assertHasActionErrors([$field]);

    expect($license->fresh()->expires_at->equalTo($expiry))->toBeTrue();
})->with([
    'zero' => [['duration' => 0], 'duration'],
    'fraction' => [['duration' => 1.5], 'duration'],
    'too many days' => [['duration' => 3651], 'duration'],
    'too many hours' => [['duration' => 87601, 'unit' => 'hours'], 'duration'],
    'unknown unit' => [['unit' => 'weeks'], 'unit'],
    'long reason' => [['reason' => str_repeat('x', 2001)], 'reason'],
]);

test('staff clock actions reauthorize after mounting', function () {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $component = Livewire::test(ManageLicenses::class)->mountTableAction('pause', $license);
    Gate::define('licensekit.admin', fn (): bool => false);

    $component->callMountedTableAction(['reason' => 'Vacation'])->assertForbidden();

    expect($license->fresh()->paused_at)->toBeNull();
});

test('unmanageable licenses hide clock actions and expired licenses can only extend', function () {
    adminUser();
    $lifetime = License::factory()->lifetime()->create(['user_id' => User::factory()->create()->id]);
    $revoked = License::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'revoked']);
    $orphan = License::factory()->create();
    $expired = License::factory()->expired()->create(['user_id' => User::factory()->create()->id]);
    $page = Livewire::test(ManageLicenses::class);

    foreach ([$lifetime, $revoked, $orphan] as $license) {
        $page->assertTableActionHidden('extend', $license)->assertTableActionHidden('pause', $license)->assertTableActionHidden('resume', $license);
    }
    $page->assertTableActionVisible('extend', $expired)->assertTableActionHidden('pause', $expired)->assertTableActionHidden('resume', $expired);
});
