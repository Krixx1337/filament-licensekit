<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Support\ModuleReadiness;
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
