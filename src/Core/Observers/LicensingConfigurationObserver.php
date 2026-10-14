<?php

namespace LicenseKit\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Models\ProductGroup;

class LicensingConfigurationObserver
{
    public function created(Model $model): void
    {
        app(Audit::class)->record($this->actor($model), 'configuration_created', $model, [], $model->attributesToArray());
    }

    public function updated(Model $model): void
    {
        $fields = array_diff(array_keys($model->getChanges()), ['updated_at']);
        if ($fields !== []) {
            $before = [];
            foreach ($fields as $field) {
                $before[$field] = $model->getOriginal($field);
            }
            app(Audit::class)->record($this->actor($model), 'configuration_updated', $model, $before, $model->only($fields));
        }
    }

    private function actor(Model $model): string
    {
        $actor = $model instanceof Product || $model instanceof LicensePlan || $model instanceof ProductGroup ? $model->auditActor : null;
        if (is_string($actor)) {
            return $actor;
        }

        return auth()->id() === null ? 'console' : 'user:'.auth()->id();
    }
}
