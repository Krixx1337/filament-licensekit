<?php

namespace LicenseKit\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return self::rulesFor($this->route()->getActionMethod());
    }

    /** @return array<string, list<string>> */
    public static function rulesFor(string $action): array
    {
        $fields = match ($action) {
            'login', 'moveDevice' => ['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:4096'],
                'device' => ['required', 'string', 'max:1024']],
            'heartbeat' => ['token' => ['required', 'string', 'size:64'], 'device' => ['required', 'string', 'max:1024']],
            'redeem' => ['token' => ['required', 'string', 'size:64'], 'key' => ['required', 'string', 'max:64']],
            default => [],
        };

        return [...$fields, ...self::nonceRules()];
    }

    /** @return array<string, list<string>> */
    public static function nonceRules(): array
    {
        return ['nonce' => ['required', 'string', 'regex:/^[a-f0-9]{32,128}$/']];
    }
}
