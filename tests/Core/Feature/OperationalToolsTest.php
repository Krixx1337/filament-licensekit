<?php

use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\ManageKeys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\User;

test('batch export and revocation isolate unclaimed nonrevoked keys and preserve claimed access', function () {
    $this->freezeTime();
    $actor = testAdminActor();
    $plan = LicensePlan::factory()->create();
    $batch = app(Keys::class)->issue($plan, 4, new IssueContext(source: 'admin', actor: $actor, reference: 'batch'));
    $other = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: $actor, reference: 'other'));
    $user = User::factory()->create();
    $license = app(ClaimLicense::class)->claim($user, $batch->keys[0]->key);
    app(ManageKeys::class)->revokeKey($batch->keys[1], $actor, 'Prior revoke');
    $before = $license->fresh()->getAttributes();
    $tools = app(ManageKeys::class);

    $values = $tools->exportUnusedBatch($batch, $actor);

    expect($values)->toBe([$batch->keys[2]->key, $batch->keys[3]->key]);
    expect($tools->exportUnusedBatch($batch, $actor))->toBe($values);
    $this->assertDatabaseCount('licensekit_keys', 5);
    expect($tools->cancelUnusedBatch($batch, $actor, ' Chargeback '))->toBe(2);
    expect($tools->cancelUnusedBatch($batch, $actor, 'Repeated'))->toBe(0);
    expect($tools->exportUnusedBatch($batch, $actor))->toBe([]);
    expect($license->fresh()->getAttributes())->toBe($before);
    expect($other->keys->first()->fresh()->cancelled_at)->toBeNull();
    $this->assertDatabaseHas('licensekit_activity_log', ['event' => 'batch_unused_cancelled', 'meta->note' => 'Chargeback']);
});

test('key notes are trimmed cleared private and limited', function () {
    $actor = testAdminActor();
    $key = LicenseKey::factory()->create(['redeemed_at' => now()]);
    $tools = app(ManageKeys::class);

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
    $batch = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: $actor, reference: 'batch'));
    Event::listen('eloquent.creating: '.ActivityEntry::class, fn () => throw new RuntimeException('Audit failed'));
    $tools = app(ManageKeys::class);

    expect(fn () => match ($operation) {
        'revoke' => $tools->cancelUnusedBatch($batch, $actor, 'Reason'),
        'note' => $tools->updateNote($batch->keys->first(), $actor, 'Note'),
    })->toThrow(RuntimeException::class, 'Audit failed');

    expect($batch->keys->first()->fresh()->cancelled_at)->toBeNull();
    expect($batch->keys->first()->fresh()->note)->toBeNull();
    $this->assertDatabaseCount('licensekit_keys', 1);
})->with(['revoke', 'note']);

test('invalid batch reasons do not revoke keys or create audit', function (string $reason) {
    $actor = testAdminActor();
    $plan = LicensePlan::factory()->create();
    $batch = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: $actor, reference: 'batch'));

    expect(fn () => app(ManageKeys::class)->cancelUnusedBatch($batch, $actor, $reason))->toThrow(LicensingException::class, 'invalid revocation reason');

    expect($batch->keys->first()->fresh()->cancelled_at)->toBeNull();
    expect(ActivityEntry::where('event', 'batch_unused_cancelled')->count())->toBe(0);
})->with([str_repeat('x', 2001)]);

test('generated lifetime batch remains lifetime after plan edits', function () {
    $actor = testAdminActor();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->lifetime()->create();
    $batch = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: $actor, reference: 'lifetime'));
    $plan->update(['duration_days' => 30]);
    $license = app(ClaimLicense::class)->claim($user, $batch->keys[0]->key);
    expect($batch->duration_days)->toBeNull();
    expect($batch->keys[0]->fresh()->duration_days)->toBeNull();
    expect($license->is_lifetime)->toBeTrue()->and($license->expires_at)->toBeNull();
});
