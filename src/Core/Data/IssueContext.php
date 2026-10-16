<?php

namespace LicenseKit\Core\Data;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\ApiConnection;

final readonly class IssueContext
{
    public function __construct(
        public string $source = 'app',
        public ?string $reference = null,
        public ?Model $actor = null,
        public ?ApiConnection $connection = null,
        public ?string $label = null,
        public ?int $customDays = null,
    ) {}
}
