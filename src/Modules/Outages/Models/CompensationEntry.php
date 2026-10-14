<?php

namespace LicenseKit\Modules\Outages\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $id @property int $operation_id @property int $entitlement_id @property int $seconds */
#[Fillable(['operation_id', 'entitlement_id', 'seconds', 'result', 'credited_seconds', 'applied_at'])]
class CompensationEntry extends Model
{
    protected $table = 'licensekit_compensation_entries';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['operation_id' => 'integer', 'entitlement_id' => 'integer', 'seconds' => 'integer', 'credited_seconds' => 'integer', 'applied_at' => 'immutable_datetime'];
    }
}
