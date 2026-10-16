<?php

use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\ManageApiConnections;
use LicenseKit\Core\Actions\ResellerBatches;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Livewire\ResellerBatches as ResellerComponent;
use LicenseKit\Tests\Core\Fixtures\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->management = true;
    $this->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

test('bot stamps its owner and lists manual purchases without exposing other resellers', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $connection = ApiConnection::factory()->create(['user_id' => $owner->id]);
    $token = app(ManageApiConnections::class)->regenerate($connection, $owner);
    $plan = LicensePlan::factory()->create();
    $manual = app(Keys::class)->issue($plan, 3, new IssueContext(reseller: $owner));
    $foreign = app(Keys::class)->issue($plan, 1, new IssueContext(reseller: $other));

    $id = $this->withToken($token)->postJson(route('licensekit.management.batches.store'), [
        'plan_id' => $plan->id, 'quantity' => 2, 'reference' => 'bot-order',
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('licensekit_batches', ['id' => $id, 'reseller_id' => $owner->id]);
    $this->withToken($token)->getJson(route('licensekit.management.batches.index'))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $manual->id]);
    $this->withToken($token)->getJson(route('licensekit.management.batches.keys', $manual->id))->assertOk()->assertJsonCount(3, 'data');
    $this->withToken($token)->getJson(route('licensekit.management.batches.keys', $foreign->id))->assertNotFound();
});

test('unavailable connection owner gets 403 on next API request', function (array $attributes) {
    $owner = User::factory()->create();
    $connection = ApiConnection::factory()->create(['user_id' => $owner->id]);
    $token = app(ManageApiConnections::class)->regenerate($connection, $owner);
    $owner->update($attributes);

    $this->withToken($token)->getJson(route('licensekit.management.batches.index'))->assertForbidden()->assertJsonPath('error', 'user_unavailable');
    expect($connection->fresh()->last_used_at)->toBeNull();
})->with(['suspended' => [['is_suspended' => true]], 'unverified' => [['email_verified_at' => null]]]);

test('changing reseller on same reference conflicts with 409', function () {
    $plan = LicensePlan::factory()->create();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $first = app(Keys::class)->issue($plan, 1, new IssueContext(reference: 'order', reseller: $owner));

    try {
        app(Keys::class)->issue($plan, 1, new IssueContext(reference: 'order', reseller: $other));
        $this->fail('Expected batch conflict');
    } catch (LicensingException $exception) {
        expect($exception->error)->toBe('batch_conflict');
        expect($exception->status)->toBe(409);
    }
    expect($first->fresh()->reseller_id)->toBe($owner->id);
    $this->assertDatabaseCount('licensekit_batches', 1);
});

test('reseller inventory counts and exports only unused owned keys', function () {
    $owner = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $batch = app(Keys::class)->issue($plan, 4, new IssueContext(reseller: $owner));
    $batch->keys[0]->update(['redeemed_at' => now()]);
    $batch->keys[1]->update(['cancelled_at' => now()]);
    $foreign = app(Keys::class)->issue($plan, 1, new IssueContext(reseller: User::factory()->create()));

    $page = app(ResellerBatches::class)->paginate($owner);

    expect($page->total())->toBe(1);
    expect($page->items()[0]->only(['total_count', 'redeemed_count', 'unused_count', 'cancelled_count']))
        ->toBe(['total_count' => 4, 'redeemed_count' => 1, 'unused_count' => 2, 'cancelled_count' => 1]);
    expect(app(ResellerBatches::class)->unusedKeys($owner, $batch))->toBe([$batch->keys[2]->key, $batch->keys[3]->key]);
    expect(fn () => app(ResellerBatches::class)->unusedKeys($owner, $foreign))->toThrow(HttpException::class);
});

test('reseller component lists purchases and downloads unused keys', function () {
    $owner = User::factory()->create();
    $batch = app(Keys::class)->issue(LicensePlan::factory()->create(), 2, new IssueContext(reseller: $owner, label: '<script>sale</script>'));
    $batch->keys[0]->update(['redeemed_at' => now()]);
    $foreign = app(Keys::class)->issue($batch->plan, 1, new IssueContext(reseller: User::factory()->create(), label: 'Other purchase'));
    $this->actingAs($owner);

    Livewire::test('licensekit.reseller-batches')->assertSee(['2 bought', '1 redeemed', '1 left', $batch->plan->product->name])
        ->assertSee('&lt;script&gt;sale&lt;/script&gt;', false)->assertDontSee('<script>sale</script>', false)->assertDontSee('Other purchase')
        ->call('download', $batch->id)->assertFileDownloaded('license-keys-'.$batch->id.'.txt', $batch->keys[1]->key);
    Livewire::test(ResellerComponent::class)->call('download', $foreign->id)->assertNotFound();
});

test('reseller component shows empty state', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ResellerComponent::class)->assertSee('No batches yet');
});

test('reseller component refuses guests and suspended users', function () {
    Livewire::test(ResellerComponent::class)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['is_suspended' => true]));

    Livewire::test(ResellerComponent::class)->assertForbidden();
});

test('reseller suspension between render and download blocks export', function () {
    $owner = User::factory()->create();
    $batch = Batch::factory()->create(['reseller_id' => $owner->id]);
    $this->actingAs($owner);
    $page = Livewire::test(ResellerComponent::class);
    $owner->update(['is_suspended' => true]);

    $page->call('download', $batch->id)->assertForbidden();
});
