<?php

namespace LicenseKit\Modules\Outages\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Models\LicensingOperation;
use LicenseKit\Support\Modules;
use Throwable;

class ApplyCompensation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $operationId) {}

    public function handle(Audit $audit): void
    {
        try {
            DB::transaction(function () use ($audit): void {
                Modules::requireEnabled('outages');
                $operation = LicensingOperation::findOrFail($this->operationId);
                Product::whereKey($operation->product_id)->lockForUpdate()->firstOrFail();
                $operation = LicensingOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if (! in_array($operation->status, ['pending', 'failed'], true)) {
                    return;
                }
                $operation->update(['status' => 'processing', 'failure' => null]);
                $at = CarbonImmutable::now('UTC')->startOfSecond();
                $outage = $operation->outage;
                $base = $outage === null || $outage->access_blocked ? $at : $outage->stopped_at;
                $credited = 0;
                $skipped = 0;
                DB::table('licensekit_compensation_entries')->where('operation_id', $operation->id)->orderBy('id')->chunkById(250, function ($entries) use ($operation, $base, $at, &$credited, &$skipped): void {
                    $entitlements = DB::table('licensekit_entitlements')->whereIn('id', $entries->pluck('entitlement_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $updates = [];
                    $results = [];
                    foreach ($entries as $entry) {
                        $entitlement = $entitlements->get($entry->entitlement_id);
                        $eligible = $entitlement !== null && $entitlement->product_id === $operation->product_id && $entitlement->user_id !== null && ! $entitlement->is_lifetime && ! $entitlement->is_revoked && $entitlement->paused_at === null && $entitlement->expires_at !== null;
                        $seconds = 0;
                        if ($eligible) {
                            $old = CarbonImmutable::parse($entitlement->expires_at, 'UTC');
                            $expiry = ($operation->kind === 'manual' ? $old : $base)->addSeconds($entry->seconds);
                            $updates[$entry->entitlement_id] = $expiry->format('Y-m-d H:i:s');
                            $seconds = max(0, (int) $old->diffInSeconds($expiry, false));
                            $credited++;
                        } else {
                            $skipped++;
                        }
                        $results[] = ['id' => $entry->id, 'operation_id' => $operation->id, 'entitlement_id' => $entry->entitlement_id, 'seconds' => $entry->seconds, 'result' => $eligible ? 'applied' : 'skipped', 'credited_seconds' => $seconds, 'applied_at' => $at->format('Y-m-d H:i:s')];
                    }
                    if ($updates !== []) {
                        $cases = [];
                        $bindings = [];
                        foreach ($updates as $id => $expiry) {
                            $cases[] = 'WHEN ? THEN ?';
                            array_push($bindings, $id, $expiry);
                        }
                        $ids = array_keys($updates);
                        DB::update('UPDATE licensekit_entitlements SET expires_at = CASE id '.implode(' ', $cases).' END, updated_at = ? WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')', [...$bindings, $at->format('Y-m-d H:i:s'), ...$ids]);
                    }
                    DB::table('licensekit_compensation_entries')->upsert($results, ['id'], ['result', 'credited_seconds', 'applied_at']);
                });
                $operation->update(['status' => 'completed', 'credited_count' => $credited, 'skipped_count' => $skipped, 'applied_at' => $at]);
                $outage?->update(['ended_at' => $at]);
                $audit->record($operation->actor, 'compensation_completed', $operation, [], ['credited_count' => $credited, 'skipped_count' => $skipped]);
            }, 3);
        } catch (Throwable $exception) {
            $transient = $exception instanceof QueryException && (in_array(substr($exception->errorInfo[0] ?? '', 0, 2), ['08', '40'], true) || in_array($exception->errorInfo[1] ?? null, [5, 6, 1205, 1213, 2006, 2013], true));
            if (! $transient) {
                $this->failed($exception);
                if ($this->job !== null) {
                    $this->fail($exception);

                    return;
                }
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        LicensingOperation::whereKey($this->operationId)->whereIn('status', ['pending', 'processing'])->update(['status' => 'failed', 'failure' => 'processing_failed']);
    }
}
