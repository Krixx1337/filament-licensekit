<section class="space-y-6 text-gray-900 dark:text-gray-100" aria-label="My batches">
    <h2 class="text-xl font-semibold">My batches</h2>
    <div class="space-y-4">
        @forelse ($batches as $batch)
            <article wire:key="batch-{{ $batch->id }}" class="space-y-3 rounded border p-4">
                <h3 class="font-semibold">{{ $batch->label ?? 'Batch #'.$batch->id }}</h3>
                <p>{{ $batch->plan->product->name }} · {{ $batch->plan->name }}</p>
                <p>{{ $batch->total_count }} bought · {{ $batch->redeemed_count }} redeemed · {{ $batch->unused_count }} left</p>
                <button wire:click="download({{ $batch->id }})" wire:loading.attr="disabled" class="underline">Download unused keys</button>
            </article>
        @empty
            <p>No batches yet</p>
        @endforelse
    </div>
    {{ $batches->links() }}
</section>
