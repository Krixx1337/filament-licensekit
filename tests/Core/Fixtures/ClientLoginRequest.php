<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class ClientLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:1024'],
            'hwid' => ['required', 'array']];
    }
}
