<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class ClientHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['hwid' => ['required', 'string']];
    }
}
