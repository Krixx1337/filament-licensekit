<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->stats() as $label => $count)
            <x-filament::section><p>{{ $label }}</p><p class="text-2xl font-semibold">{{ number_format($count) }}</p></x-filament::section>
        @endforeach
    </div>
    <x-filament::section heading="Get started">
        <ul class="space-y-3">@foreach ($this->steps() as $step)<li>{{ $step['done'] ? 'Done: ' : '' }}<a href="{{ $step['url'] }}" class="underline">{{ $step['label'] }}</a></li>@endforeach</ul>
    </x-filament::section>
</x-filament-panels::page>
