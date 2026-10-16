<div x-data class="space-y-3">
    @if ($token)<p>Copy this token now. It will not be shown again.</p>@endif
    <textarea x-ref="result" readonly rows="8" class="w-full rounded border p-3 dark:bg-gray-900" aria-label="{{ $token ? 'Connection token' : 'Generated keys' }}">{{ $text }}</textarea>
    <x-filament::button x-on:click="navigator.clipboard.writeText($refs.result.value)">{{ $token ? 'Copy token' : 'Copy all' }}</x-filament::button>
</div>
