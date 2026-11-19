<?php
use App\Services\{DeviceReadingService, SimulatorService};
use Livewire\Attributes\{Computed,Title};
use Livewire\Component;
new #[Title('Isolated Simulator')] class extends Component {
    public int $quantity=25;
    public function mount(SimulatorService $simulator): void { $simulator->ensureEnabled(); }
    public function send(string $action,SimulatorService $simulator): void {
        $this->validate(['quantity'=>'required|integer|min:0|max:1000000']);
        abort_unless(in_array($action,['reboot','disconnect','stale','out_of_order','new','tare','sample_one','sample_ten','calibrate','ack','stable','unstable'],true),422);
        try { $simulator->send($action,$this->quantity); \Flux\Flux::toast('Simulation event accepted.'); }
        catch (\Illuminate\Validation\ValidationException $e) { foreach ($e->errors() as $key=>$messages) { $this->addError($key,implode(' ',$messages)); } }
    }
    #[Computed] public function reading() { return app(DeviceReadingService::class)->latest(app(SimulatorService::class)->scale()); }
}; 
