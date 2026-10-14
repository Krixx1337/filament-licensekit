<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Resources\Batches\BatchResource;
use LicenseKit\Filament\Resources\Batches\Pages\ManageBatches;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\LicensePlans\LicensePlanResource;
use LicenseKit\Filament\Resources\LicensePlans\Pages\ManageLicensePlans;
use Livewire\Livewire;

test('plan action creates labeled batch with optional download and no import action', function (bool $download) {
    adminUser();
    $plan = LicensePlan::factory()->create();
    $component = Livewire::test(ManageLicensePlans::class)->assertTableActionDoesNotExist('import_stock', record: $plan)
        ->callTableAction('create_keys', $plan, data: ['quantity' => 2, 'reference' => 'generated', 'label' => 'Generated', 'download' => $download])
        ->assertHasNoActionErrors();
    $batch = Batch::firstOrFail();
    expect($batch->label)->toBe('Generated');
    expect($batch->keys)->toHaveCount(2);
    if ($download) {
        $component->assertFileDownloaded('license-keys-'.$batch->id.'.txt', $batch->keys->pluck('key')->implode("\r\n"));
    } else {
        $component->assertNoFileDownloaded();
    }
    $this->assertDatabaseCount('licensekit_license_keys', 2);
})->with([true, false]);

test('batch listing shows labels counts filters and escapes operator metadata', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 3, 'one', '<script>alert(1)</script>');
    $other = app(CreateBatch::class)->createFor($actor, $plan, 1, 'two');
    $batch->keys[0]->update(['claimed_at' => now()]);
    $batch->keys[1]->update(['revoked_at' => now()]);

    Livewire::test(ManageBatches::class)->assertCanSeeTableRecords([$batch, $other])->assertSee('Batch #'.$batch->id)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $row = BatchResource::getEloquentQuery()->findOrFail($batch->id);
    expect($row->getAttribute('claimed_count'))->toBe(1)->and($row->getAttribute('unclaimed_count'))->toBe(1)->and($row->getAttribute('revoked_count'))->toBe(1);
    Livewire::test(ManageLicenseKeys::class)->filterTable('batch_id', $batch->id)
        ->assertCanSeeTableRecords($batch->keys)->assertCanNotSeeTableRecords($other->keys);
    Livewire::withQueryParams(['filters' => ['batch_id' => ['value' => $batch->id]]])->test(ManageLicenseKeys::class)
        ->assertCanSeeTableRecords($batch->keys)->assertCanNotSeeTableRecords($other->keys);
});

test('key note action edits and clears escaped text without general key editing', function () {
    adminUser();
    $key = LicenseKey::factory()->create();

    Livewire::test(ManageLicenseKeys::class)->callTableAction('edit_note', $key, data: ['note' => '<b>support</b>'])->assertHasNoActionErrors()
        ->assertSee('&lt;b&gt;support&lt;/b&gt;', false)->assertDontSee('<b>support</b>', false);

    expect($key->fresh()->note)->toBe('<b>support</b>');
    Livewire::test(ManageLicenseKeys::class)->callTableAction('edit_note', $key, data: ['note' => ''])->assertHasNoActionErrors();
    expect($key->fresh()->note)->toBeNull();
});

test('existing batch downloads only eligible keys and revocation leaves other batches alone', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 3, 'one');
    $other = app(CreateBatch::class)->createFor($actor, $plan, 1, 'two');
    $batch->keys[0]->update(['claimed_at' => now()]);
    $batch->keys[1]->update(['revoked_at' => now()]);

    Livewire::test(ManageBatches::class)->callTableAction('export_unclaimed', $batch)->assertHasNoActionErrors()
        ->assertFileDownloaded('license-keys-'.$batch->id.'.txt', $batch->keys[2]->key);
    Livewire::test(ManageBatches::class)->callTableAction('revoke_unclaimed', $batch, data: ['reason' => 'Chargeback'])->assertHasNoActionErrors();

    expect($batch->keys[2]->fresh()->revoked_at)->not->toBeNull();
    expect($other->keys->first()->fresh()->revoked_at)->toBeNull();
    Livewire::test(ManageBatches::class)->callTableAction('export_unclaimed', $batch)->assertHasNoActionErrors()->assertNoFileDownloaded();
});

test('batch download response uses stable ordering and private no store headers', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 2, 'one');

    $response = (LicensingActions::exportBatch()->getActionFunction())($batch);

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    ob_start();
    $response->sendContent();
    $text = ob_get_clean();
    expect($text)->toBe($batch->keys[0]->key."\r\n".$batch->keys[1]->key);
});

test('new action execution denies permissions removed after mounting', function (string $operation) {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'one');
    $page = match ($operation) {
        'create_keys' => ManageLicensePlans::class,
        'edit_note' => ManageLicenseKeys::class,
        default => ManageBatches::class,
    };
    $record = match ($operation) {
        'create_keys' => $plan,
        'edit_note' => $batch->keys->first(),
        default => $batch,
    };
    $component = Livewire::test($page);
    if ($operation !== 'export_unclaimed') {
        $component->mountTableAction($operation, $record);
    }
    Gate::define('licensekit.manage', fn () => false);

    if ($operation === 'export_unclaimed') {
        $component->call('$refresh')->assertForbidden();
    } else {
        $component->callMountedTableAction()->assertForbidden();
    }

    $this->assertDatabaseCount('licensekit_license_keys', 1);
    expect($batch->keys->first()->fresh()->revoked_at)->toBeNull();
    expect($batch->keys->first()->fresh()->note)->toBeNull();
})->with(['create_keys', 'edit_note', 'export_unclaimed', 'revoke_unclaimed']);

test('plan unclaimed count includes earlier terms and excludes claimed or revoked keys', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $earlier = app(CreateBatch::class)->createFor($actor, $plan, 3, 'earlier');
    $earlier->keys[0]->update(['claimed_at' => now()]);
    $earlier->keys[1]->update(['revoked_at' => now()]);
    $plan->update(['duration_days' => 1]);
    app(CreateBatch::class)->createFor($actor, $plan, 2, 'current');
    expect(LicensePlanResource::getEloquentQuery()->findOrFail($plan->id)->getAttribute('unclaimed_count'))->toBe(3);
});
