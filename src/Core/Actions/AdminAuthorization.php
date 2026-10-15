<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Users\UserAccess;

class AdminAuthorization
{
    public function __construct(private UserAccess $users) {}

    /**
     * @param  class-string<Model>|Model  $subject
     * @param  list<mixed>  $context
     */
    public function allowsExplicit(Model $actor, string $ability, string|Model $subject, array $context = []): bool
    {
        return $this->users->available($actor)
            && Gate::forUser($actor)->allows($ability, [$subject, ...$context]);
    }

    /**
     * @param  class-string<Model>|Model  $subject
     * @param  list<mixed>  $context
     */
    public function allows(Model $actor, string $ability, string|Model $subject, array $context = []): bool
    {
        if (! $this->users->available($actor)) {
            return false;
        }

        $gate = Gate::forUser($actor);

        return $gate->getPolicyFor($subject) !== null
            ? $gate->allows($ability, [$subject, ...$context])
            : $gate->allows('licensekit.manage');
    }
}
