<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Model;

class AdminPolicy
{
    private function allowed(string $ability, ?Model $record = null, ?Model $target = null): bool
    {
        return in_array($ability, config('testing.admin_abilities', []), true)
            && ($record === null || config('testing.admin_record_id') === null || (string) $record->getKey() === (string) config('testing.admin_record_id'))
            && ($target === null || config('testing.admin_target_id') === null || (string) $target->getKey() === (string) config('testing.admin_target_id'));
    }

    public function viewAny(Model $actor): bool
    {
        return $this->allowed(__FUNCTION__);
    }

    public function view(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function create(Model $actor): bool
    {
        return $this->allowed(__FUNCTION__);
    }

    public function update(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function delete(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function createKeys(Model $actor, Model $record): bool
    {
        return $this->allowed(__FUNCTION__, $record);
    }

    public function updateNote(Model $actor, Model $record): bool
    {
        return $this->allowed(__FUNCTION__, $record);
    }

    public function exportUnclaimed(Model $actor, Model $record): bool
    {
        return $this->allowed(__FUNCTION__, $record);
    }

    public function revokeUnclaimed(Model $actor, Model $record): bool
    {
        return $this->allowed(__FUNCTION__, $record);
    }

    public function claim(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function revoke(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function restoreAccess(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function block(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function unblock(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function resetHwid(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function inspectDevice(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function startOutage(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function addTime(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function lift(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function rotateToken(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function revokeTokens(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function stop(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function retry(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }

    public function cancel(Model $actor, Model $record, ?Model $target = null): bool
    {
        return $this->allowed(__FUNCTION__, $record, $target);
    }
}
