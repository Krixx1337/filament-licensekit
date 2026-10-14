<?php

namespace LicenseKit\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManagementWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['plan_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:'.config('licensekit.maximum_batch_size')],
            'reference' => ['required', 'string', 'max:128'], 'label' => ['nullable', 'string', 'max:255'], 'method' => ['missing']];
    }
}
