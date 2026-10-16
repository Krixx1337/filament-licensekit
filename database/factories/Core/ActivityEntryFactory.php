<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\ActivityEntry;

/** @extends Factory<ActivityEntry> */
class ActivityEntryFactory extends Factory
{
    protected $model = ActivityEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['actor' => 'console', 'event' => 'fixture', 'subject_type' => 'fixture', 'subject_id' => 1, 'before' => [], 'after' => []];
    }
}
