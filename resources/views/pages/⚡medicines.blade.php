<?php

use App\Models\Medicine;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Medicines')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $status = 'active';

    public bool $showForm = false;

    #[Locked]
    public ?int $editing = null;

    public array $form = ['code' => '', 'generic_name' => '', 'brand_name' => '', 'strength' => '', 'dosage_form' => 'Tablet', 'manufacturer' => '', 'notes' => '', 'low_stock_threshold' => 10, 'active' => true];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function edit(?int $id = null): void
    {
        $m = $id ? Medicine::findOrFail($id) : new Medicine();
        Gate::authorize($id ? 'update' : 'create', $id ? $m : Medicine::class);
        $this->editing = $id;
        $this->resetValidation();
        $this->form = $id ? $m->only(array_keys($this->form)) : ['code' => '', 'generic_name' => '', 'brand_name' => '', 'strength' => '', 'dosage_form' => 'Tablet', 'manufacturer' => '', 'notes' => '', 'low_stock_threshold' => 10, 'active' => true];
        $this->showForm = true;
    }

    public function save(): void
    {
        $m = $this->editing ? Medicine::findOrFail($this->editing) : new Medicine();
        Gate::authorize($this->editing ? 'update' : 'create', $this->editing ? $m : Medicine::class);
        $d = $this->validate(['form.code' => ['required', 'string', 'max:50', Rule::unique('medicines', 'code')->ignore($this->editing)], 'form.generic_name' => 'required|string|max:255', 'form.brand_name' => 'nullable|string|max:255', 'form.strength' => 'required|string|max:100', 'form.dosage_form' => 'required|string|max:100', 'form.manufacturer' => 'required|string|max:255', 'form.notes' => 'nullable|string|max:2000', 'form.low_stock_threshold' => 'required|integer|min:0|max:1000000', 'form.active' => 'required|boolean']);
        $m->fill($d['form'])->save();
        $this->showForm = false;
        Flux::toast('Medicine saved.', variant: 'success');
    }

    public function archive(int $id): void
    {
        $m = Medicine::findOrFail($id);
        Gate::authorize('update', $m);
        $m->update(['active' => false]);
        Flux::toast('Medicine archived. History retained.');
    }

    #[Computed]
    public function medicines()
    {
        return Medicine::where('simulation', false)
            ->when($this->status !== 'all', fn($q) => $q->where('active', $this->status === 'active'))
            ->where(
                fn($q) => $q
                    ->where('generic_name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhere('brand_name', 'like', '%' . $this->search . '%'),
            )
            ->orderBy('generic_name')
            ->orderBy('id')
            ->paginate(10);
    }
}; ?>
<div>
    <x-page-heading title="Medicines">
        @can('create', Medicine::class)
            <flux:button variant="primary" icon="plus" wire:click="edit">Add medicine</flux:button>
        @endcan
    </x-page-heading>
    <x-inventory-card>
        <div class="mb-6 flex flex-wrap gap-4">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search name, brand or code"
                aria-label="Search medicines" icon="magnifying-glass" class="max-w-md" />
            <flux:select wire:model.live="status" aria-label="Medicine status" class="max-w-40">
                <option value="active">Active</option>
                <option value="archived">Archived</option>
                <option value="all">All medicines</option>
            </flux:select>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b text-zinc-500">
                    <tr>
                        <th class="py-3 font-medium">Medicine</th>
                        <th class="p-3 font-medium">Code</th>
                        <th class="p-3 font-medium">Recorded stock</th>
                        <th class="p-3 font-medium">Status</th>
                        <th class="p-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($this->medicines as $medicine)
                        <tr wire:key="medicine-{{ $medicine->id }}">
                            <td class="py-5">
                                <p class="font-semibold">{{ $medicine->generic_name }} {{ $medicine->strength }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $medicine->dosage_form }} &middot;
                                    {{ $medicine->manufacturer }} @if ($medicine->brand_name)
                                        &middot; {{ $medicine->brand_name }}
                                    @endif
                                </p>
                            </td>
                            <td class="p-3 text-zinc-500">{{ $medicine->code }}</td>
                            <td class="p-3">
                                {{ $medicine->quantity === null ? 'Not counted' : number_format($medicine->quantity) . ' pieces' }}
                                @if ($medicine->quantity !== null && $medicine->quantity <= $medicine->low_stock_threshold)
                                    <flux:badge size="sm" color="amber">Low stock</flux:badge>
                                @endif
                            </td>
                            <td class="p-3">
                                <flux:badge size="sm" :color="$medicine->active?'teal':'zinc'">
                                    {{ $medicine->active ? 'Active' : 'Archived' }}</flux:badge>
                            </td>
                            <td class="p-3">
                                @can('update', $medicine)
                                    <div class="flex gap-2">
                                        <flux:button size="sm" wire:click="edit({{ $medicine->id }})">Edit
                                        </flux:button>
                                        @if ($medicine->active)
                                            <flux:button size="sm" variant="ghost"
                                                wire:click="archive({{ $medicine->id }})"
                                                wire:confirm="Archive this medicine? Its history will be retained.">Archive
                                            </flux:button>
                                        @endif
                                    </div>
                                @endcan
                            </td>
                    </tr>@empty<tr>
                            <td colspan="5" class="py-12 text-center text-zinc-500">No medicines match your search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $this->medicines->links() }}</div>
    </x-inventory-card>
    <flux:modal wire:model="showForm" class="md:w-2xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editing ? 'Edit medicine' : 'Add medicine' }}</flux:heading>
            <x-form-errors />
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach (['code' => 'Medicine code', 'generic_name' => 'Generic name', 'brand_name' => 'Brand name (optional)', 'strength' => 'Strength', 'dosage_form' => 'Dosage form', 'manufacturer' => 'Manufacturer'] as $field => $label)
                    <flux:input wire:model="form.{{ $field }}" :label="$label"
                        wire:key="field-{{ $field }}" />
                @endforeach
                <flux:input type="number" min="0" wire:model="form.low_stock_threshold"
                    label="Low-stock threshold" />
                <flux:checkbox wire:model="form.active" label="Active medicine" />
            </div>
            <flux:textarea wire:model="form.notes" label="Notes (optional)" />
            <flux:button variant="primary" type="submit">Save medicine</flux:button>
        </form>
    </flux:modal>
</div>
