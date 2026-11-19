<?php
use App\Models\InventoryCount;
use Livewire\Attributes\{Computed,Title};
use Livewire\{Component,WithPagination};
new #[Title('Count History')] class extends Component {
    use WithPagination;
    public string $search=''; public string $source='';
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedSource(): void { $this->resetPage(); }
    #[Computed] public function counts() { return InventoryCount::with(['medicine','user'])->whereHas('medicine',fn($q)=>$q->where('generic_name','like','%'.$this->search.'%'))->when($this->source!=='',fn($q)=>$q->where('source',$this->source))->latest('id')->paginate(15); }
}; 
