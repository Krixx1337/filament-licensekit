<?php

namespace LicenseKit\Core\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class ProductAccessInvalidated
{
    public function __construct(public Model $user, public ?int $productId = null) {}
}
