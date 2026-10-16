<?php

namespace LicenseKit\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LicenseKit\Core\Actions\ResellerBatches as Inventory;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResellerBatches extends Component
{
    use WithPagination;

    public function download(int $batchId): StreamedResponse
    {
        $user = $this->user();
        $batch = Batch::where('reseller_id', $user->getKey())->whereKey($batchId)->first() ?? abort(404);
        $text = implode("\r\n", app(Inventory::class)->unusedKeys($user, $batch));

        return response()->streamDownload(function () use ($text): void {
            echo $text;
        }, 'license-keys-'.$batch->id.'.txt', ['Content-Type' => 'text/plain', 'Cache-Control' => 'private, no-store']);
    }

    public function render(): View
    {
        return view()->make('licensekit::reseller-batches', ['batches' => app(Inventory::class)->paginate($this->user())]);
    }

    private function user(): Model
    {
        $user = Auth::guard(ConfiguredUserModel::guard())->user();
        abort_unless($user instanceof Model, 401);
        $user = $user->fresh();
        abort_if($user === null || app(UserAccess::class)->suspended($user), 403, 'Your account is suspended. Contact support for help.');

        return $user;
    }
}
