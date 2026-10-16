<?php

namespace LicenseKit\Livewire;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;
use Livewire\Component;
use Livewire\WithPagination;

class MyLicenses extends Component
{
    use WithPagination;

    public string $key = '';

    public ?string $message = null;

    public function redeem(): void
    {
        $this->validate(['key' => ['required', 'string', 'max:64']]);
        try {
            app(ClaimLicense::class)->claim($this->user(), $this->key);
            $this->key = '';
            $this->message = 'Key redeemed. Your license is ready.';
            $this->resetPage();
        } catch (LicensingException $exception) {
            $this->addError('key', $exception->friendlyMessage());
        }
    }

    public function resetDevice(int $licenseId): void
    {
        $user = $this->user();
        $license = License::where('user_id', $user->getKey())->findOrFail($licenseId);
        try {
            app(LicensedDevices::class)->reset($user, $license->product);
            $this->message = 'Device reset. Your next app login will lock your new PC.';
        } catch (LicensingException $exception) {
            $this->addError('device', $exception->friendlyMessage());
        }
    }

    public function render(): View
    {
        $user = $this->user();

        return view()->make('licensekit::my-licenses', ['licenses' => app(CustomerLicenses::class)->paginate($user),
            'verified' => ! ($user instanceof MustVerifyEmail) || $user->hasVerifiedEmail()]);
    }

    private function user(): Model
    {
        $user = Auth::guard(ConfiguredUserModel::guard())->user();
        abort_unless($user instanceof Model, 401);
        abort_if(app(UserAccess::class)->suspended($user), 403, 'Your account is suspended. Contact support for help.');

        return $user;
    }
}
