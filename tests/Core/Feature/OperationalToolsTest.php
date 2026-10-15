<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Support\ModuleReadiness;
use LicenseKit\Tests\Core\Fixtures\AdminPolicy;
use LicenseKit\Tests\Core\Fixtures\User;

test('batch export and revocation isolate unclaimed nonrevoked keys and preserve claimed access', function () {
    $this->freezeTime();
    $actor = testAdminActor();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 4, 'batch');
    $other = app(CreateBatch::class)->createFor($actor, $plan, 1, 'other');
    $user = User::factory()->create();
    $entitlement = app(ClaimLicense::class)->claim($user, $batch->keys[0]->key);
    app(ManageEntitlements::class)->revokeKey($batch->keys[1], $actor, 'Prior revoke');
    $before = $entitlement->fresh()->getAttributes();
    $tools = app(ManageEntitlements::class);

    $values = $tools->exportUnclaimedBatch($batch, $actor);

    expect($values)->toBe([$batch->keys[2]->key, $batch->keys[3]->key]);
    expect($tools->exportUnclaimedBatch($batch, $actor))->toBe($values);
    $this->assertDatabaseCount('licensekit_license_keys', 5);
    expect($tools->revokeUnclaimedBatch($batch, $actor, ' Chargeback '))->toBe(2);
    expect($tools->revokeUnclaimedBatch($batch, $actor, 'Repeated'))->toBe(0);
    expect($tools->exportUnclaimedBatch($batch, $actor))->toBe([]);
    expect($entitlement->fresh()->getAttributes())->toBe($before);
    expect($other->keys->first()->fresh()->revoked_at)->toBeNull();
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'batch_unclaimed_revoked', 'reason' => 'Chargeback']);
});

test('key notes are trimmed cleared private and limited', function () {
    $actor = testAdminActor();
    $key = LicenseKey::factory()->create(['claimed_at' => now()]);
    $tools = app(ManageEntitlements::class);

    $tools->updateNote($key, $actor, '  Support follow-up  ');

    expect($key->fresh()->note)->toBe('Support follow-up');
    expect($key->fresh()->toArray())->not->toHaveKey('note');
    expect(fn () => $tools->updateNote($key, $actor, str_repeat('x', 256)))->toThrow(LicensingException::class);
    expect($key->fresh()->note)->toBe('Support follow-up');
    $tools->updateNote($key, $actor, ' ');
    expect($key->fresh()->note)->toBeNull();
});

test('new admin tools deny stale permissions and suspended actors', function (string $operation, bool $suspended) {
    $actor = testAdminActor();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'batch');
    if ($suspended) {
        $actor->update(['is_suspended' => true]);
    } else {
        Gate::define('licensekit.manage', fn () => false);
    }
    $tools = app(ManageEntitlements::class);

    expect(fn () => match ($operation) {
        'export' => $tools->exportUnclaimedBatch($batch, $actor),
        'revoke' => $tools->revokeUnclaimedBatch($batch, $actor, 'Reason'),
        'note' => $tools->updateNote($batch->keys->first(), $actor, 'Note'),
    })->toThrow(LicensingException::class, 'admin forbidden');

    expect($batch->keys->first()->fresh()->revoked_at)->toBeNull();
    expect($batch->keys->first()->fresh()->note)->toBeNull();
    $this->assertDatabaseCount('licensekit_license_keys', 1);
})->with(['export', 'revoke', 'note'])->with([false, true]);

test('new writes roll back on audit failure', function (string $operation) {
    $actor = testAdminActor();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'batch');
    Event::listen('eloquent.creating: '.AuditEvent::class, fn () => throw new RuntimeException('Audit failed'));
    $tools = app(ManageEntitlements::class);

    expect(fn () => match ($operation) {
        'revoke' => $tools->revokeUnclaimedBatch($batch, $actor, 'Reason'),
        'note' => $tools->updateNote($batch->keys->first(), $actor, 'Note'),
    })->toThrow(RuntimeException::class, 'Audit failed');

    expect($batch->keys->first()->fresh()->revoked_at)->toBeNull();
    expect($batch->keys->first()->fresh()->note)->toBeNull();
    $this->assertDatabaseCount('licensekit_license_keys', 1);
})->with(['revoke', 'note']);

test('customer reasons remain customer resets in either binding scope', function (string $scope) {
    $this->freezeTime();
    config(['licensekit.device_binding.scope' => $scope, 'licensekit.device_binding.customer_reset.require_reason' => true]);
    $user = testAdminActor();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => app(DeviceBindings::class)->scopeKey($product)]);

    app(LicensedDevices::class)->reset($entitlement, $user, '  PC replaced  ');

    $history = DeviceResetHistory::firstOrFail();
    expect($history->performed_by)->toBe('customer')->and($history->reason)->toBe('PC replaced');
    $binding->update(['hwid' => ['value' => 'new']]);
    expect(fn () => app(LicensedDevices::class)->reset($entitlement, $user, 'Another reason'))->toThrow(LicensingException::class, 'reset cooldown');
    app(LicensedDevices::class)->adminReset($entitlement, $user, 'Staff override');
    expect(DeviceResetHistory::latest('id')->first()->performed_by)->toBe('admin');
})->with(['global', 'per_product']);

test('invalid customer reasons preserve binding and history', function (?string $reason, string $error) {
    config(['licensekit.device_binding.customer_reset.require_reason' => true]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);

    expect(fn () => app(DeviceBindings::class)->reset($user, $product, $user, $reason))->toThrow(LicensingException::class, $error);

    expect($binding->fresh()->hwid)->not->toBeNull();
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
})->with([[null, 'reason required'], [' ', 'reason required'], [str_repeat('x', 2001), 'invalid reset reason']]);

test('optional blank reset reason stores null and reasons do not allow resetting another owner', function () {
    $user = User::factory()->create();
    $other = testAdminActor();
    $product = Product::factory()->create();
    DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);

    expect(fn () => app(DeviceBindings::class)->reset($user, $product, $other, 'Support'))->toThrow(LicensingException::class, 'reset forbidden');
    app(DeviceBindings::class)->reset($user, $product, $user, ' ');

    expect(DeviceResetHistory::firstOrFail()->reason)->toBeNull();
});

test('registered policies control new tools independently of broad fallback grants', function (string $operation, string $ability, string $model) {
    $actor = testAdminActor();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'one');
    $tools = app(ManageEntitlements::class);
    Gate::policy($model, AdminPolicy::class);
    config(['testing.admin_abilities' => []]);
    $run = fn () => match ($operation) {
        'export' => $tools->exportUnclaimedBatch($batch, $actor),
        'revoke' => $tools->revokeUnclaimedBatch($batch, $actor, 'Reason'),
        'note' => $tools->updateNote($batch->keys->first(), $actor, 'Note'),
    };

    expect($run)->toThrow(LicensingException::class, 'admin forbidden');
    config(['testing.admin_abilities' => [$ability]]);
    $run();

    expect(match ($operation) {
        'export' => $run() === [$batch->keys->first()->key],
        'revoke' => $batch->keys->first()->fresh()->revoked_at !== null,
        'note' => $batch->keys->first()->fresh()->note === 'Note',
    })->toBeTrue();
})->with([
    ['export', 'exportUnclaimed', Batch::class],
    ['revoke', 'revokeUnclaimed', Batch::class],
    ['note', 'updateNote', LicenseKey::class],
]);

test('invalid batch reasons do not revoke keys or create audit', function (string $reason) {
    $actor = testAdminActor();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'batch');

    expect(fn () => app(ManageEntitlements::class)->revokeUnclaimedBatch($batch, $actor, $reason))->toThrow(LicensingException::class, 'invalid revocation reason');

    expect($batch->keys->first()->fresh()->revoked_at)->toBeNull();
    expect(AuditEvent::where('event', 'batch_unclaimed_revoked')->count())->toBe(0);
})->with([' ', str_repeat('x', 2001)]);

test('generated lifetime batch remains lifetime after plan edits', function () {
    $actor = testAdminActor();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->lifetime()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'lifetime');
    $plan->update(['duration_days' => 30]);
    $entitlement = app(ClaimLicense::class)->claim($user, $batch->keys[0]->key);
    expect($batch->duration_days)->toBeNull();
    expect($batch->keys[0]->fresh()->duration_days)->toBeNull();
    expect($entitlement->is_lifetime)->toBeTrue()->and($entitlement->expires_at)->toBeNull();
});

test('schema diagnostics detect old databases missing private key notes', function () {
    Schema::table('licensekit_license_keys', fn (Blueprint $table) => $table->dropColumn('note'));

    expect(app(ModuleReadiness::class)->issues())->toContain('Missing licensekit_license_keys column: note. Run php artisan migrate with the installed LicenseKit version.');
});
