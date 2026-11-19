<?php

use App\Models\User;
use App\Services\UserManagementService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    public bool $showForm = false;

    #[Locked]
    public ?int $editing = null;

    public array $form = ['name' => '', 'email' => '', 'password' => '', 'role' => 'staff', 'active' => true];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function edit(?int $id = null): void
    {
        $this->authorize('viewAny', User::class);
        $this->editing = $id;
        $this->resetValidation();
        $this->form = $id ? array_merge(User::findOrFail($id)->only(['name', 'email', 'role', 'active']), ['password' => '']) : ['name' => '', 'email' => '', 'password' => '', 'role' => 'staff', 'active' => true];
        $this->showForm = true;
    }

    public function save(UserManagementService $service): void
    {
        $service->save(auth()->user(), $this->editing, $this->form);
        $this->showForm = false;
        Flux::toast('User saved.', variant: 'success');
    }

    #[Computed]
    public function users()
    {
        $this->authorize('viewAny', User::class);

        return User::orderBy('name')->orderBy('id')->paginate(10);
    }
}; ?>
<div>
    <x-page-heading title="Users">
        <flux:button variant="primary" wire:click="edit" icon="plus">Add user</flux:button>
    </x-page-heading>
    <x-inventory-card>
        <div class="divide-y">
            @foreach ($this->users as $user)
                <div wire:key="user-{{ $user->id }}" class="flex flex-wrap items-center justify-between gap-4 py-5">
                    <div>
                        <p class="font-semibold">{{ $user->name }}</p>
                        <p class="text-sm text-zinc-500">{{ $user->email }}</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <flux:badge>{{ ucfirst($user->role) }}</flux:badge>
                        <span class="text-sm">{{ $user->active ? 'Active' : 'Disabled' }}</span>
                        <flux:button size="sm" wire:click="edit({{ $user->id }})">Edit</flux:button>
                    </div>
                </div>
            @endforeach
        </div>{{ $this->users->links() }}
    </x-inventory-card>
    <flux:modal wire:model="showForm" class="md:w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editing ? 'Edit user' : 'Add user' }}</flux:heading>
            <x-form-errors />
            <flux:input wire:model="form.name" label="Name" />
            <flux:input wire:model="form.email" label="Email" type="email" />
            <flux:input wire:model="form.password" label="Password (12+ characters; leave blank to retain)"
                type="password" />
            <flux:select wire:model="form.role" label="Role">
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
            </flux:select>
            <flux:checkbox wire:model="form.active" label="Active account" />
            <flux:button type="submit" variant="primary">Save user</flux:button>
        </form>
    </flux:modal>
</div>
