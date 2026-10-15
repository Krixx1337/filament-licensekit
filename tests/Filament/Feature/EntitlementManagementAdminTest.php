<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Filament\Resources\Entitlements\Pages\ManageEntitlements;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Tests\Core\Fixtures\AdminPolicy;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('staff extend pause and resume licenses from table', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDay()]);

    Livewire::test(ManageEntitlements::class)
        ->assertTableActionHidden('resume', $entitlement)
        ->callTableAction('extend', $entitlement, data: ['duration' => 2, 'unit' => 'hours', 'reason' => 'Support'])
        ->assertHasNoActionErrors();

    expect($entitlement->fresh()->expires_at->toIso8601String())->toBe('2026-01-02T02:00:00+00:00');

    Livewire::test(ManageEntitlements::class)
        ->callTableAction('pause', $entitlement, data: ['reason' => 'Vacation'])->assertHasNoActionErrors();

    Livewire::test(ManageEntitlements::class)
        ->assertSee('Paused')->assertSee('1d 2h 0m 0s')
        ->assertTableActionHidden('pause', $entitlement)
        ->callTableAction('extend', $entitlement, data: ['duration' => 1, 'unit' => 'days', 'reason' => 'Support'])->assertHasNoActionErrors();
    expect($entitlement->fresh()->remaining_seconds)->toBe(180000);

    $this->travelTo(CarbonImmutable::parse('2026-02-01T00:00:00Z'));
    Livewire::test(ManageEntitlements::class)
        ->callTableAction('resume', $entitlement, data: ['reason' => 'Returned'])->assertHasNoActionErrors();

    expect($entitlement->fresh()->expires_at->toIso8601String())->toBe('2026-02-03T02:00:00+00:00');
    expect($entitlement->fresh()->paused_at)->toBeNull();
});

test('staff clock forms reject invalid duration units and reasons', function (array $data, string $field) {
    adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $expiry = $entitlement->expires_at;

    Livewire::test(ManageEntitlements::class)
        ->callTableAction('extend', $entitlement, data: [...['duration' => 1, 'unit' => 'days', 'reason' => 'Support'], ...$data])
        ->assertHasActionErrors([$field]);

    expect($entitlement->fresh()->expires_at->equalTo($expiry))->toBeTrue();
})->with([
    'zero' => [['duration' => 0], 'duration'],
    'fraction' => [['duration' => 1.5], 'duration'],
    'too many days' => [['duration' => 3651], 'duration'],
    'too many hours' => [['duration' => 87601, 'unit' => 'hours'], 'duration'],
    'unknown unit' => [['unit' => 'weeks'], 'unit'],
    'missing reason' => [['reason' => ''], 'reason'],
    'long reason' => [['reason' => str_repeat('x', 2001)], 'reason'],
]);

test('staff clock actions require individual abilities even with master gate', function () {
    $actor = adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $paused = Entitlement::factory()->paused()->create(['user_id' => User::factory()->create()->id]);
    Gate::policy(Entitlement::class, AdminPolicy::class);
    config(['testing.admin_abilities' => ['viewAny', 'view', 'pause']]);

    Livewire::test(ManageEntitlements::class)
        ->assertTableActionHidden('extend', $entitlement)
        ->assertTableActionHidden('resume', $paused)
        ->callTableAction('pause', $entitlement, data: ['reason' => 'Vacation'])->assertHasNoActionErrors();

    expect($entitlement->fresh()->paused_at)->not->toBeNull();
});

test('staff clock actions reauthorize after mounting', function () {
    adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $component = Livewire::test(ManageEntitlements::class)->mountTableAction('pause', $entitlement);
    Gate::define('licensekit.manage', fn (): bool => false);

    $component->callMountedTableAction(['reason' => 'Vacation'])->assertForbidden();

    expect($entitlement->fresh()->paused_at)->toBeNull();
});

test('staff sees actionable notification for unsettled product operation', function () {
    $actor = adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    app(ManageOutages::class)->start($entitlement->product, 'deployment', 'Deployment', $actor);

    Livewire::test(ManageEntitlements::class)
        ->callTableAction('pause', $entitlement, data: ['reason' => 'Vacation'])
        ->assertNotified('Finish the product outage or finish/cancel compensation before trying again.');

    expect($entitlement->fresh()->paused_at)->toBeNull();
});

test('unmanageable licenses hide clock actions and expired licenses can only extend', function () {
    adminUser();
    $lifetime = Entitlement::factory()->lifetime()->create(['user_id' => User::factory()->create()->id]);
    $revoked = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'is_revoked' => true]);
    $orphan = Entitlement::factory()->create();
    $expired = Entitlement::factory()->expired()->create(['user_id' => User::factory()->create()->id]);
    $page = Livewire::test(ManageEntitlements::class);

    foreach ([$lifetime, $revoked, $orphan] as $entitlement) {
        $page->assertTableActionHidden('extend', $entitlement)->assertTableActionHidden('pause', $entitlement)->assertTableActionHidden('resume', $entitlement);
    }
    $page->assertTableActionVisible('extend', $expired)->assertTableActionHidden('pause', $expired)->assertTableActionHidden('resume', $expired);
});
