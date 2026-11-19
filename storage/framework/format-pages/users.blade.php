<?php
use App\Models\User;
use App\Services\UserManagementService;
use Flux\Flux;
use Livewire\Attributes\{Computed,Locked,Title};
use Livewire\{Component,WithPagination};
new #[Title('Users')] class extends Component {
    use WithPagination;
    public bool $showForm=false; #[Locked] public ?int $editing=null;
    public array $form=['name'=>'','email'=>'','password'=>'','role'=>'staff','active'=>true];
    public function mount(): void { $this->authorize('viewAny',User::class); }
    public function edit(?int $id=null): void { $this->authorize('viewAny',User::class); $this->editing=$id; $this->resetValidation(); $this->form=$id?array_merge(User::findOrFail($id)->only(['name','email','role','active']),['password'=>'']):['name'=>'','email'=>'','password'=>'','role'=>'staff','active'=>true]; $this->showForm=true; }
    public function save(UserManagementService $service): void { $service->save(auth()->user(),$this->editing,$this->form); $this->showForm=false; Flux::toast('User saved.',variant:'success'); }
    #[Computed] public function users() { $this->authorize('viewAny',User::class); return User::orderBy('name')->orderBy('id')->paginate(10); }
}; 
