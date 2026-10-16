<x-filament-panels::page>
    <p>Default loader API: {{ config('licensekit.client_enabled') ? 'On' : 'Off' }}.</p>
    @if ($diagnosis)<p role="status">{{ $diagnosis }}</p>@endif
    <label>Product <select wire:model.live="productId" class="rounded border p-2 dark:bg-gray-900">
        @foreach ($products as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach
    </select></label>
    @if ($product)
        <ul>@foreach (['login', 'heartbeat', 'redeem', 'move-device'] as $endpoint)<li>POST <code>{{ $base }}/{{ $endpoint }}</code></li>@endforeach</ul>
        <p>Signing public key. Embed this in your app:</p><pre>{{ $publicKey }}</pre>
        <p>Each request needs a random hex nonce. Verify the response signature, echoed nonce and timestamp before allowing access. A token alone does not mean the customer has a license.</p>
        @foreach ($snippets as $language => $snippet)<x-filament::section :heading="$language"><pre class="overflow-x-auto">{{ $snippet }}</pre></x-filament::section>@endforeach
    @else<p>Create a product first.</p>@endif
    <p>Invalid credentials: check login and password. Device mismatch: ask before moving to this PC. Suspended or banned: contact support. Expired or no license: redeem a key. Paused: saved time resumes when staff resume it.</p>
    <p>For a custom protocol, disable client_enabled and use the public PHP services described in docs/client-api.md. Website 2FA does not apply to loader password logins.</p>
</x-filament-panels::page>
