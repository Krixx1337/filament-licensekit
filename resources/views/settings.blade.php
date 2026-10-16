<x-filament-panels::page>
    <p>Manage store connections, key batches, device bans and activity from the Settings menu.</p>
    <p><a href="{{ \LicenseKit\Filament\Pages\ConnectYourApp::getUrl() }}">Connect your app</a></p>
    <p><a href="{{ \LicenseKit\Filament\Pages\TeamAccess::getUrl() }}">Team access</a></p>
    <p>Store API endpoint: <code>{{ url(config('licensekit.store_api_prefix')) }}</code></p>
    <pre>curl -X POST {{ url(config('licensekit.store_api_prefix').'/batches') }} \
  -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' \
  -d '{"plan_id":1,"quantity":10,"reference":"order-123"}'</pre>
</x-filament-panels::page>
