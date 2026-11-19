<?php

use App\Services\DeviceReadingService;
use App\Services\SimulatorService;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Isolated Simulator')] class extends Component
{
    public int $quantity = 25;

    public bool $broadcasting = false;

    public function tick(SimulatorService $simulator): void
    {
        $simulator->ensureEnabled();
        if ($this->broadcasting) {
            $simulator->send('heartbeat', $this->quantity);
        }
    }

    public function mount(SimulatorService $simulator): void
    {
        $simulator->ensureEnabled();
    }

    public function send(string $action, SimulatorService $simulator): void
    {
        if (in_array($action, ['disconnect', 'stale', 'out_of_order'], true)) {
            $this->broadcasting = false;
        }
        $this->validate(['quantity' => 'required|integer|min:0|max:1000000']);
        abort_unless(in_array($action, ['reboot', 'disconnect', 'stale', 'out_of_order', 'new', 'tare', 'sample_one', 'sample_ten', 'calibrate', 'ack', 'stable', 'unstable'], true), 422);
        try {
            $simulator->send($action, $this->quantity);
            Flux::toast('Simulation event accepted.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, implode(' ', $messages));
            }
        }
    }

    #[Computed]
    public function reading()
    {
        return app(DeviceReadingService::class)->latest(app(SimulatorService::class)->scale());
    }
}; ?>
<div wire:poll.2s.keep-alive="tick">
<x-page-heading title="Isolated Simulator" description="Development only. Events exercise the device protocol services; demo stock is isolated from physical inventory.">
<flux:badge color="amber">Simulation &middot; not hardware verification</flux:badge>
</x-page-heading>
<x-form-errors />
<div class="grid gap-6 lg:grid-cols-2">
<x-inventory-card>
<flux:heading size="lg">Start a demo count</flux:heading>
<ol class="mt-5 list-decimal space-y-3 pl-5 text-sm leading-relaxed">
<li>Open demo Count Stock in another tab, select a demo medicine, and acquire the session.</li>
<li>Send Reboot / connect, then Tare, One piece, Ten total, and Calibrate.</li>
<li>In Count Stock, save captured calibration, select it and load the profile.</li>
<li>Send Acknowledge profile, then Tare (empty tray).</li>
<li>Choose a quantity and send Stable reading. Review and confirm Save Count.</li>
</ol>
<flux:button class="mt-6" variant="primary" :href="route('count-stock',['simulation'=>1])" target="_blank">Open demo Count Stock</flux:button>
</x-inventory-card>
<x-inventory-card>
<flux:heading size="lg">Device events</flux:heading>
<flux:checkbox class="mt-4" wire:model.live="broadcasting" label="Publish current reading every 2 seconds" />
<div class="my-5 grid grid-cols-2 gap-3">@foreach(['reboot'=>'Reboot / connect','new'=>'NEW','tare'=>'Tare (empty tray)','sample_one'=>'SAMPLE: one piece','sample_ten'=>'SAMPLE: ten total','calibrate'=>'CALIBRATE','ack'=>'Acknowledge profile','disconnect'=>'Disconnect','stale'=>'Stale reading','out_of_order'=>'Out-of-order reading'] as $action=>$label)<flux:button wire:key="sim-{{ $action }}" wire:click="send('{{ $action }}')">{{ $label }}</flux:button>@endforeach</div>
<flux:input type="number" min="0" wire:model="quantity" label="Simulated quantity" />
<div class="mt-4 flex flex-wrap gap-3">
<flux:button variant="primary" wire:click="send('stable')">Stable reading</flux:button>
<flux:button wire:click="send('unstable')">Unstable reading</flux:button>
</div>
<details class="mt-6 text-xs">
<summary>Protocol telemetry</summary>
<pre class="mt-3 overflow-auto rounded-lg bg-zinc-50 p-3">{{ json_encode($this->reading,JSON_PRETTY_PRINT) }}</pre>
</details>
</x-inventory-card>
</div>
<flux:text class="mt-6">The physical scale occasionally misses a single-piece change. Simulation cannot validate or resolve that hardware limitation.</flux:text>
</div>
