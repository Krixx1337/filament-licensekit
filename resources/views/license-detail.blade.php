<x-filament-panels::page>
    <x-filament::section heading="License">
        <p>{{ $license->user?->getAttribute(config('licensekit.user_label')) ?? 'Deleted customer' }} / {{ $license->product->name }}</p>
        <p>{{ \LicenseKit\Filament\Enums\LicenseState::for($license)->getLabel() }}</p>
        <p>{{ $license->is_lifetime ? 'Lifetime' : $license->expires_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}</p>
        <p>Device: {{ $license->device === null ? 'Not locked yet' : \Illuminate\Support\Str::limit($license->device, 24) }}</p>
        @if ($license->note)<p>{{ $license->note }}</p>@endif
    </x-filament::section>
    <x-filament::section heading="Keys redeemed">
        <ul>@forelse ($keys as $key)<li>{{ $key->key }} / Key batch #{{ $key->batch_id }} / {{ ucfirst($key->batch->source) }}</li>@empty<li>No keys redeemed.</li>@endforelse</ul>
    </x-filament::section>
    <x-filament::section heading="Active app sessions">
        <ul>@forelse ($sessions as $session)<li>Session #{{ $session->id }}, expires {{ $session->expires_at->diffForHumans() }}</li>@empty<li>No active sessions.</li>@endforelse</ul>
    </x-filament::section>
    <x-filament::section heading="Activity">
        <ol>@forelse ($entries as $entry)<li>{{ $entry->description() }} / {{ $entry->created_at->diffForHumans() }}</li>@empty<li>No activity yet.</li>@endforelse</ol>
    </x-filament::section>
</x-filament-panels::page>
