<section class="space-y-6 text-gray-900 dark:text-gray-100" aria-label="My licenses">
    <h2 class="text-xl font-semibold">My licenses</h2>
    @if ($message)
        <p role="status">{{ $message }}</p>
    @endif
    @if (! $verified)
        <p>Verify your email to redeem keys.</p>
        @if (\Illuminate\Support\Facades\Route::has('verification.notice'))
            <a href="{{ route('verification.notice') }}" class="underline">Verify your email or resend verification</a>
        @endif
    @else
        <form wire:submit="redeem" class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-2">Redeem a key
                <input wire:model="key" autocomplete="off" class="rounded border p-2 dark:bg-gray-900" required>
            </label>
            <button type="submit" wire:loading.attr="disabled" class="rounded bg-blue-700 px-4 py-2 text-white">Redeem key</button>
            @error('key') <p role="alert">{{ $message }}</p> @enderror
        </form>
    @endif
    @error('device') <p role="alert">{{ $message }}</p> @enderror
    <div class="grid gap-4 sm:grid-cols-2">
        @forelse ($licenses as $license)
            <article wire:key="license-{{ $license['id'] }}" class="space-y-3 rounded border p-4">
                <h3 class="font-semibold">{{ $license['product']['name'] }}</h3>
                <p>{{ ucfirst($license['status']) }}
                    @if ($license['status'] === 'paused')
                        ({{ intdiv((int) $license['remaining_seconds'], 86400) }} days saved)
                    @endif
                </p>
                <p>@if ($license['lifetime']) Lifetime
                    @elseif ($license['expires_at'])
                        Expires <time title="{{ \Carbon\CarbonImmutable::parse($license['expires_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}">{{ \Carbon\CarbonImmutable::parse($license['expires_at'])->diffForHumans() }}</time>
                    @endif
                </p>
                <p>{{ $license['device']['bound'] ? 'Device locked' : 'No device locked yet' }}</p>
                @if ($license['reset']['allowed'] && $verified)
                    <button wire:click="resetDevice({{ $license['id'] }})" wire:loading.attr="disabled"
                        wire:confirm="Your current PC will be signed out. The next PC you log in from becomes your device." class="underline">Reset device</button>
                @elseif ($license['reset']['available_at'])
                    <p>Next reset {{ \Carbon\CarbonImmutable::parse($license['reset']['available_at'])->diffForHumans() }}</p>
                @elseif ($license['device']['bound'])
                    <p>Contact support to reset your device.</p>
                @endif
            </article>
        @empty
            <p>No licenses yet. Redeem your first key above.</p>
        @endforelse
    </div>
    {{ $licenses->links() }}
</section>
