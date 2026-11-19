<?php
use App\Models\Medicine;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\{Computed, Locked, Title};
use Livewire\{Component, WithPagination};
new #[Title('Medicines')] class extends Component {
    use WithPagination;
    public string $search=''; public string $status='active'; public bool $showForm=false;
    #[Locked] public ?int $editing=null;
    public array $form=['code'=>'','generic_name'=>'','brand_name'=>'','strength'=>'','dosage_form'=>'Tablet','manufacturer'=>'','notes'=>'','low_stock_threshold'=>10,'active'=>true];
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function edit(?int $id=null): void {
        $m=$id?Medicine::findOrFail($id):new Medicine;
        Gate::authorize($id?'update':'create',$id?$m:Medicine::class);
        $this->editing=$id; $this->resetValidation();
        $this->form=$id?$m->only(array_keys($this->form)):['code'=>'','generic_name'=>'','brand_name'=>'','strength'=>'','dosage_form'=>'Tablet','manufacturer'=>'','notes'=>'','low_stock_threshold'=>10,'active'=>true];
        $this->showForm=true;
    }
    public function save(): void {
        $m=$this->editing?Medicine::findOrFail($this->editing):new Medicine;
        Gate::authorize($this->editing?'update':'create',$this->editing?$m:Medicine::class);
        $d=$this->validate(['form.code'=>['required','string','max:50',Rule::unique('medicines','code')->ignore($this->editing)],'form.generic_name'=>'required|string|max:255','form.brand_name'=>'nullable|string|max:255','form.strength'=>'required|string|max:100','form.dosage_form'=>'required|string|max:100','form.manufacturer'=>'required|string|max:255','form.notes'=>'nullable|string|max:2000','form.low_stock_threshold'=>'required|integer|min:0|max:1000000','form.active'=>'required|boolean']);
        $m->fill($d['form'])->save(); $this->showForm=false; Flux::toast('Medicine saved.',variant:'success');
    }
    public function archive(int $id): void { $m=Medicine::findOrFail($id); Gate::authorize('update',$m); $m->update(['active'=>false]); Flux::toast('Medicine archived. History retained.'); }
    #[Computed] public function medicines() {
        return Medicine::where('simulation',false)->when($this->status!=='all',fn($q)=>$q->where('active',$this->status==='active'))->where(fn($q)=>$q->where('generic_name','like','%'.$this->search.'%')->orWhere('code','like','%'.$this->search.'%')->orWhere('brand_name','like','%'.$this->search.'%'))->orderBy('generic_name')->orderBy('id')->paginate(10);
    }
}; 
