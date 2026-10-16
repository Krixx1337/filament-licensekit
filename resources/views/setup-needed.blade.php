<x-filament-panels::page>
    <p>Finish these steps, then reopen LicenseKit.</p>
    <ul>@forelse ($this->problems() as $problem)<li>{{ $problem }}</li>@empty<li>Setup complete. Open Dashboard to continue.</li>@endforelse</ul>
    <p>Your developer can run <code>php artisan licensekit:check</code> for the same checks.</p>
</x-filament-panels::page>
