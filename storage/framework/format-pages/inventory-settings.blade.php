<?php
use App\Models\{ApplicationSetting,Scale};
use Flux\Flux;
use Illuminate\Support\Facades\{Hash,Gate};
use Illuminate\Support\Str;
use Livewire\Attributes\{Locked,Title};
use Livewire\Component;
new #[Title('Settings')] class extends Component {
    public string $name=''; public int $staleSeconds=10; public int $offlineSeconds=30;
    public string $deviceIdentifier='esp32-01'; public string $deviceName='Dispensary scale'; public bool $deviceActive=true;
    #[Locked] public ?string $plaintext=null;
    public function mount(): void {
        abort_unless(auth()->user()->isAdmin(),403); $s=ApplicationSetting::findOrFail(1);
        $this->name=$s->name; $this->staleSeconds=$s->stale_seconds; $this->offlineSeconds=$s->offline_seconds;
        $device=Scale::where('simulation',false)->first(); if ($device) { $this->deviceIdentifier=$device->device_identifier; $this->deviceName=$device->name; $this->deviceActive=$device->active; }
    }
    public function save(): void {
        abort_unless(auth()->user()->isAdmin(),403);
        $this->validate(['name'=>'required|string|max:100','staleSeconds'=>'required|integer|min:3|max:120','offlineSeconds'=>'required|integer|gt:staleSeconds|max:600','deviceIdentifier'=>'required|alpha_dash|max:100','deviceName'=>'required|string|max:100','deviceActive'=>'boolean']);
        $scale=Scale::firstOrCreate(['simulation'=>false],['device_identifier'=>$this->deviceIdentifier,'name'=>$this->deviceName]); Gate::authorize('update',$scale);
        \Illuminate\Support\Facades\DB::transaction(function () use ($scale) {
            ApplicationSetting::findOrFail(1)->update(['name'=>$this->name,'stale_seconds'=>$this->staleSeconds,'offline_seconds'=>$this->offlineSeconds]);
            $scale->update(['device_identifier'=>$this->deviceIdentifier,'name'=>$this->deviceName,'active'=>$this->deviceActive]);
        }); Flux::toast('Settings saved.',variant:'success');
    }
    public function dismissCredential(): void { $this->plaintext=null; }
    public function rotate(): void {
        abort_unless(auth()->user()->isAdmin(),403);
        $scale=Scale::where('simulation',false)->firstOrFail(); Gate::authorize('update',$scale);
        $this->plaintext=Str::random(64); $scale->update(['credential_hash'=>Hash::make($this->plaintext)]);
        Flux::toast('Credential generated. Copy it now; it will not be shown again.');
    }
}; 
