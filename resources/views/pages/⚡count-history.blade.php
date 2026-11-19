<?php

use App\Models\InventoryCount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Count History')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $source = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSource(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function counts()
    {
        return InventoryCount::with(['medicine', 'user'])
            ->whereHas('medicine', fn($q) => $q->where('generic_name', 'like', '%' . $this->search . '%'))
            ->when($this->source !== '', fn($q) => $q->where('source', $this->source))
            ->latest('id')
            ->paginate(15);
    }
}; ?>
<div>
    <x-page-heading title="Count History" />
    <x-inventory-card>
        <div class="mb-6 flex flex-wrap gap-4">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search medicine" aria-label="Search history"
                class="max-w-md" />
            <flux:select wire:model.live="source" aria-label="Count source" class="max-w-48">
                <option value="">All sources</option>
                <option value="physical_scale">Physical scale</option>
                <option value="manual">Manual</option>
                <option value="simulation">Isolated simulation</option>
            </flux:select>
        </div>
        <div class="space-y-4">
            @forelse($this->counts as $count)
                <details wire:key="history-{{ $count->id }}" class="rounded-xl border border-zinc-200 p-4">
                    <summary class="cursor-pointer">
                        <span class="font-medium">{{ $count->medicine->generic_name }}
                            {{ $count->medicine->strength }}</span>
                        <span class="mx-3">{{ $count->previous_quantity ?? 'Not counted' }} &rarr;
                            {{ $count->new_quantity }}</span>
                        <flux:badge size="sm">{{ str_replace('_', ' ', $count->source) }}</flux:badge>
                        <span class="ml-3 text-xs text-zinc-500">{{ $count->created_at->format('d M Y H:i') }}</span>
                    </summary>
                    <div class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                        <p>Staff: {{ $count->user->name }}
                            ({{ $count->count_method === 'manual' ? 'manually verified' : 'scale estimate' }})
                        </p>
                        <p>Adjustment:
                            {{ $count->difference === null ? 'First count' : sprintf('%+d', $count->difference) }}
                        </p>
                        <p>Reason: {{ $count->reason }}</p>
                        <p>Notes: {{ $count->notes ?: 'None' }}</p>
                        <p class="sm:col-span-2">{{ $count->medicine->identity() }}</p>
                        @if ($count->scale_id)
                            <details class="sm:col-span-2 text-xs text-zinc-500">
                                <summary>Device audit reference</summary>
                                <p class="mt-2 break-all">Scale {{ $count->scale_id }} / profile
                                    {{ $count->calibration_profile_id }} v{{ $count->profile_version }} / boot
                                    {{ $count->boot_id }} / counting session {{ $count->counting_session_id }} /
                                    calibration {{ $count->calibration_session_id }} / tare
                                    {{ $count->tare_revision }} / sequence {{ $count->reading_sequence }}</p>
                            </details>
                        @endif
                    </div>
            </details>@empty<flux:text class="py-8">No confirmed counts yet.</flux:text>
            @endforelse
        </div>
        <div class="mt-6">{{ $this->counts->links() }}</div>
    </x-inventory-card>
</div>
