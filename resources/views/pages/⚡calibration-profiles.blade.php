<?php

use App\Models\CalibrationProfile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Calibration Profiles')] class extends Component {
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function profiles()
    {
        return CalibrationProfile::with('medicine')->whereHas('medicine', fn($q) => $q->where('generic_name', 'like', '%' . $this->search . '%'))->latest('id')->paginate(12);
    }
}; ?>
<div>
    <x-page-heading title="Calibration Profiles" description="">
        <flux:button variant="primary" :href="route('count-stock')" wire:navigate>Count Stock</flux:button>
    </x-page-heading>
    <x-inventory-card>
        <flux:input wire:model.live.debounce.300ms="search" label="Search medicine" class="mb-6 max-w-md" />
        <div class="grid gap-4 md:grid-cols-2">
            @forelse($this->profiles as $p)
                <article wire:key="calibration-{{ $p->id }}" class="rounded-xl border p-5">
                    <div class="flex items-start justify-between gap-3">
                        <flux:heading>{{ $p->medicine->generic_name }} {{ $p->medicine->strength }}</flux:heading>
                        <flux:badge :color="$p->active?'teal':'zinc'">{{ $p->active ? 'Active' : 'Previous' }}</flux:badge>
                    </div>
                    <p class="mt-2 text-sm text-zinc-500">{{ $p->medicine->dosage_form }} &middot;
                        {{ $p->medicine->manufacturer }}</p>
                    <p class="mt-4 text-sm">Version {{ $p->version }} &middot;
                        {{ $p->calibrated_at->format('d M Y H:i') }} @if ($p->medicine->simulation)
                            <flux:badge size="sm" color="amber">Simulation</flux:badge>
                        @endif
                    </p>
                    <p class="mt-2 text-sm">{{ $p->notes }}</p>
                    <details class="mt-4 text-xs text-zinc-500">
                        <summary>Calibration audit</summary>
                        <p class="mt-3">Scale {{ $p->scale_id }} &middot; 10 pieces total &middot; signed raw
                            units/piece {{ $p->raw_units_per_piece }}</p>
                        <p>Original tare (audit only): {{ $p->original_tare_raw }}. A fresh session tare is always
                            required when loading.</p>
                    </details>
            </article>@empty<flux:text class="py-8">No saved calibrations. Start a session in Count Stock and
                    explicitly save a captured calibration.</flux:text>
            @endforelse
        </div>
        <div class="mt-6">{{ $this->profiles->links() }}</div>
    </x-inventory-card>
</div>
