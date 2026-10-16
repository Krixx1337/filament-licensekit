<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Resources\Batches\BatchResource;
use LicenseKit\Filament\Resources\Batches\Pages\ManageBatches;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use Livewire\Livewire;

test('batch listing shows labels counts filters and escapes operator metadata', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(Keys::class)->issue($plan, 3, new IssueContext(source: 'admin', actor: $actor, reference: 'one', label: '<script>alert(1)</script>'));
    $other = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: $actor, reference: 'two'));
    $batch->keys[0]->update(['redeemed_at' => now()]);
    $batch->keys[1]->update(['cancelled_at' => now()]);

    Livewire::test(ManageBatches::class)->assertCanSeeTableRecords([$batch, $other])->assertSee('Batch #'.$batch->id)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $row = BatchResource::getEloquentQuery()->findOrFail($batch->id);
    expect($row->getAttribute('redeemed_count'))->toBe(1)->and($row->getAttribute('unused_count'))->toBe(1)->and($row->getAttribute('cancelled_count'))->toBe(1);
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
    $batch = app(Keys::class)->issue($plan, 3, new IssueContext(source: 'admin', actor: $actor, reference: 'one'));
    $other = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: $actor, reference: 'two'));
    $batch->keys[0]->update(['redeemed_at' => now()]);
    $batch->keys[1]->update(['cancelled_at' => now()]);

    Livewire::test(ManageBatches::class)->callTableAction('export_unused', $batch)->assertHasNoActionErrors()
        ->assertFileDownloaded('license-keys-'.$batch->id.'.txt', $batch->keys[2]->key);
    Livewire::test(ManageBatches::class)->callTableAction('cancel_unused', $batch, data: ['reason' => 'Chargeback'])->assertHasNoActionErrors();

    expect($batch->keys[2]->fresh()->cancelled_at)->not->toBeNull();
    expect($other->keys->first()->fresh()->cancelled_at)->toBeNull();
    Livewire::test(ManageBatches::class)->callTableAction('export_unused', $batch)->assertHasNoActionErrors()->assertNoFileDownloaded();
});

test('batch download response uses stable ordering and private no store headers', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(Keys::class)->issue($plan, 2, new IssueContext(source: 'admin', actor: $actor, reference: 'one'));

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
    $batch = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: $actor, reference: 'one'));
    $page = match ($operation) {
        'create_keys' => ManageLicenseKeys::class,
        'edit_note' => ManageLicenseKeys::class,
        default => ManageBatches::class,
    };
    $record = match ($operation) {
        'create_keys' => $plan,
        'edit_note' => $batch->keys->first(),
        default => $batch,
    };
    $component = Livewire::test($page);
    if ($operation === 'create_keys') {
        $component->mountAction('create_keys');
    } elseif ($operation !== 'export_unused') {
        $component->mountTableAction($operation, $record);
    }
    Gate::define('licensekit.admin', fn () => false);

    if ($operation === 'export_unused') {
        $component->call('$refresh')->assertForbidden();
    } elseif ($operation === 'create_keys') {
        $component->callMountedAction()->assertForbidden();
    } else {
        $component->callMountedTableAction()->assertForbidden();
    }

    $this->assertDatabaseCount('licensekit_keys', 1);
    expect($batch->keys->first()->fresh()->cancelled_at)->toBeNull();
    expect($batch->keys->first()->fresh()->note)->toBeNull();
})->with(['create_keys', 'edit_note', 'export_unused', 'cancel_unused']);
