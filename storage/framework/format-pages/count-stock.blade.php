<?php
use App\Models\{CalibrationProfile, CountingSession, Medicine, Scale};
use App\Services\{CalibrationService, CountingSessionService, DeviceReadingService, InventoryCountService, SimulatorService};
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\{Computed,Locked,Title,Url};
use Livewire\Component;
new #[Title('Count Stock')] class extends Component {
    #[Url] public bool $simulation=false;
    public string $search=''; public string $medicineId=''; public string $profileId='';
    public string $method='scale'; public ?int $manualQuantity=null; public string $reason='Stock check'; public string $notes='';
    public bool $identityConfirmed=false; public bool $showReview=false;
    #[Locked] public ?string $sessionId=null;
    #[Locked] public array $pending=[];
    public function mount(): void { $this->checkMode(); }
    private function checkMode(): void { if ($this->simulation) { app(SimulatorService::class)->ensureEnabled(); } }
    public function updatedMedicineId(): void {
        if ($this->sessionId) { $s=CountingSession::find($this->sessionId); if ($s && $s->user_id===auth()->id()) { $s->delete(); } }
        $this->sessionId=null; $this->profileId=''; $this->identityConfirmed=false; $this->pending=[]; $this->showReview=false;
        unset($this->medicine,$this->profiles);
    }
    #[Computed] public function medicines() { $this->checkMode(); return Medicine::where('active',true)->where('simulation',$this->simulation)->where(fn($q)=>$q->where('generic_name','like','%'.$this->search.'%')->orWhere('code','like','%'.$this->search.'%'))->orderBy('generic_name')->orderBy('id')->limit(100)->get(); }
    #[Computed] public function medicine() { return Medicine::whereKey($this->medicineId)->where('simulation',$this->simulation)->first(); }
    #[Computed] public function scale() { return $this->simulation ? app(SimulatorService::class)->scale() : Scale::where('simulation',false)->first(); }
    #[Computed] public function reading() { return $this->scale?app(DeviceReadingService::class)->latest($this->scale):null; }
    #[Computed] public function status() { return app(DeviceReadingService::class)->status($this->scale); }
    #[Computed] public function profiles() { return CalibrationProfile::where('medicine_id',$this->medicineId)->where('scale_id',$this->scale?->id)->where('active',true)->get(); }
    public function acquire(CountingSessionService $sessions): void {
        $this->checkMode(); $this->validate(['medicineId'=>'required|integer']);
        abort_unless($this->medicine && $this->scale,422,'Choose a medicine and provision the scale in Settings.');
        $this->sessionId=$sessions->acquire(auth()->user(),$this->medicine,$this->scale)->id;
        Flux::toast('Scale session acquired.');
    }
    public function heartbeat(CountingSessionService $sessions): void {
        $this->checkMode();
        if ($this->sessionId) {
            $s=CountingSession::find($this->sessionId);
            if (!$s || $s->user_id!==auth()->id() || $s->expires_at->isPast()) { $this->sessionId=null; $this->pending=[]; $this->showReview=false; return; }
            $sessions->owned(auth()->user(),$s->id)->update(['expires_at'=>now()->addSeconds(config('inventory.session_seconds'))]);
        }
    }
    public function finish(CountingSessionService $sessions): void { if ($this->sessionId) { $sessions->release(auth()->user(),$this->sessionId); } $this->sessionId=null; $this->pending=[]; $this->showReview=false; Flux::toast('Scale released.'); }
    public function saveCalibration(CalibrationService $service): void {
        $this->validate(['notes'=>'nullable|string|max:2000']);
        $p=$service->save(auth()->user(),$this->sessionId??'',$this->notes);
        $this->profileId=(string)$p->id; unset($this->profiles); Flux::toast('Calibration saved. Load the profile, then tare the empty tray.');
    }
    public function loadProfile(CalibrationService $service): void { $this->validate(['profileId'=>'required|integer']); $service->apply(auth()->user(),$this->sessionId??'',(int)$this->profileId); Flux::toast('Profile queued. Wait for acknowledgement, then tare the empty tray.'); }
    #[Computed] public function readyToReview(): bool {
        if ($this->method === 'manual') { return (bool)$this->medicine; }
        $s = $this->sessionId ? CountingSession::find($this->sessionId) : null;
        $r = $this->reading;
        return $s && $s->user_id === auth()->id() && $s->expires_at->isFuture() && $s->acknowledged
            && $s->medicine_id === (int)$this->medicineId && $s->calibration_profile_id === ($r['applied_profile_id'] ?? null)
            && $s->calibration_session_id === ($r['calibration_session_id'] ?? null)
            && $s->tare_revision !== null && $s->tare_revision === ($r['tare_revision'] ?? null)
            && str_ends_with($this->status,'Stable reading');
    }
    public function review(): void {
        $this->checkMode(); $this->validate(['medicineId'=>'required|integer','method'=>'required|in:manual,scale','reason'=>'required|string|max:255','notes'=>'nullable|string|max:2000','identityConfirmed'=>'accepted','manualQuantity'=>[$this->method==='manual'?'required':'nullable','integer','min:0','max:1000000']]);
        $m=$this->medicine; abort_unless($m,422); $this->authorize('count',$m);
        $r=$this->reading;
        if ($this->method==='scale') {
            app(CountingSessionService::class)->owned(auth()->user(),$this->sessionId??'');
            if (!$this->readyToReview || !$r) { $this->addError('count','A fresh stable reading is required.'); return; }
        }
        $this->pending=['medicine_id'=>$m->id,'stock_revision'=>$m->stock_revision,'source'=>$this->method,'quantity'=>$this->method==='manual'?$this->manualQuantity:null,'reason'=>$this->reason,'notes'=>$this->notes,'identity_confirmed'=>true,'idempotency_key'=>(string)Str::uuid(),'session_id'=>$this->method==='scale'?$this->sessionId:null,'boot_id'=>$this->method==='scale'?$r['boot_id']:null,'sequence'=>$this->method==='scale'?$r['sequence']:null];
        $this->pending['display_quantity']=$this->method==='manual'?$this->manualQuantity:$r['estimated_count'];
        $this->pending['previous_quantity']=$m->quantity; $this->showReview=true;
    }
    public function confirm(InventoryCountService $service): void {
        abort_unless($this->pending,422);
        $data=$this->pending; unset($data['display_quantity'],$data['previous_quantity']);
        $service->save(auth()->user(),$data);
        $this->showReview=false; unset($this->medicine); Flux::toast('Count confirmed. Inventory and history updated.',variant:'success');
    }
}; 
