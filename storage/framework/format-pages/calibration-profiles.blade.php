<?php
use App\Models\CalibrationProfile;
use Livewire\Attributes\{Computed,Title};
use Livewire\{Component,WithPagination};
new #[Title('Calibration Profiles')] class extends Component {
    use WithPagination;
    public string $search='';
    public function updatedSearch(): void { $this->resetPage(); }
    #[Computed] public function profiles() { return CalibrationProfile::with('medicine')->whereHas('medicine',fn($q)=>$q->where('generic_name','like','%'.$this->search.'%'))->latest('id')->paginate(12); }
}; 
