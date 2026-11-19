<?php

use App\Models\CalibrationProfile;
use App\Models\CountingSession;
use App\Models\Medicine;
use App\Models\Scale;
use App\Services\CalibrationService;
use App\Services\CountingSessionService;
use App\Services\DeviceReadingService;
use App\Services\InventoryCountService;
use App\Services\SimulatorService;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Count Stock')] class extends Component {
    #[Url]
    public bool $simulation = false;

    public string $search = '';

    public string $medicineId = '';

    public string $profileId = '';

    public string $method = 'scale';

    public ?int $manualQuantity = null;

    public string $reason = 'Stock check';

    public string $notes = '';

    public bool $identityConfirmed = false;

    public bool $showReview = false;

    public bool $showSetup = false;

    public int $setupStep = 1;

    public bool $countingStarted = false;

    #[Locked]
    public ?string $sessionId = null;

    #[Locked]
    public array $pending = [];

    public function mount(): void
    {
        $this->checkMode();
    }

    private function checkMode(): void
    {
        if ($this->simulation) {
            app(SimulatorService::class)->ensureEnabled();
        }
    }

    public function updatedMedicineId(): void
    {
        if ($this->sessionId) {
            $s = CountingSession::find($this->sessionId);
            if ($s && $s->user_id === auth()->id()) {
                $s->delete();
            }
        }
        $this->sessionId = null;
        $this->profileId = '';
        $this->identityConfirmed = false;
        $this->pending = [];
        $this->showReview = false;
        unset($this->medicine, $this->profiles);
    }

    #[Computed]
    public function medicines()
    {
        $this->checkMode();

        return Medicine::where('active', true)->where('simulation', $this->simulation)->where(fn($q) => $q->where('generic_name', 'like', '%' . $this->search . '%')->orWhere('code', 'like', '%' . $this->search . '%'))->orderBy('generic_name')->orderBy('id')->limit(100)->get();
    }

    #[Computed]
    public function medicine()
    {
        return Medicine::whereKey($this->medicineId)->where('simulation', $this->simulation)->first();
    }

    #[Computed]
    public function scale()
    {
        return $this->simulation ? app(SimulatorService::class)->scale() : Scale::where('simulation', false)->first();
    }

    #[Computed]
    public function reading()
    {
        return $this->scale ? app(DeviceReadingService::class)->latest($this->scale) : null;
    }

    #[Computed]
    public function status()
    {
        return app(DeviceReadingService::class)->status($this->scale);
    }

    #[Computed]
    public function profiles()
    {
        return CalibrationProfile::where('medicine_id', $this->medicineId)->where('scale_id', $this->scale?->id)->where('active', true)->get();
    }

    #[Computed]
    public function session(): ?CountingSession
    {
        return $this->sessionId ? CountingSession::find($this->sessionId) : null;
    }

    #[Computed]
    public function captureReady(): bool
    {
        $scale = $this->scale;
        $session = $this->session;
        $reading = $this->reading;
        $capture = $scale ? Cache::get('capture-' . $scale->id) : null;

        return $session && $capture && $reading && $session->user_id === auth()->id() && $capture['counting_session_id'] === $session->id && $capture['boot_id'] === $reading['boot_id'] && $capture['calibration_session_id'] === $reading['calibration_session_id'] && $reading['calibrated'];
    }

    #[Computed]
    public function profileApplied(): bool
    {
        $session = $this->session;
        $reading = $this->reading;

        return $session && $reading && $session->acknowledged && $session->calibration_profile_id === (int) $this->profileId && $reading['applied_profile_id'] === (int) $this->profileId;
    }

    public function acquire(CountingSessionService $sessions): void
    {
        $this->checkMode();
        $this->validate(['medicineId' => 'required|integer']);
        abort_unless($this->medicine && $this->scale, 422, 'Choose a medicine and provision the scale in Settings.');
        $this->sessionId = $sessions->acquire(auth()->user(), $this->medicine, $this->scale)->id;
        Flux::toast('Scale session acquired.');
    }

    public function startCount(): void
    {
        $this->resetValidation();
        $this->setupStep = $this->sessionId ? 2 : 1;
        $this->showSetup = true;
    }

    public function continueManually(CountingSessionService $sessions): void
    {
        $this->validate([
            'medicineId' => 'required|integer',
            'manualQuantity' => 'required|integer|min:0|max:1000000',
        ]);
        abort_unless($this->medicine, 422, 'Choose an active medicine.');
        $this->authorize('count', $this->medicine);

        if ($this->sessionId) {
            $sessions->release(auth()->user(), $this->sessionId);
            $this->sessionId = null;
        }

        $this->method = 'manual';
        $this->countingStarted = true;
        $this->showSetup = false;
        $this->identityConfirmed = false;
        $this->pending = [];
    }

    public function continueWithScale(CountingSessionService $sessions): void
    {
        $this->method = 'scale';
        if (!$this->sessionId) {
            $this->acquire($sessions);
        }
        $this->setupStep = 2;
        $this->resetValidation();
    }

    public function beginLiveCount(): void
    {
        $reading = $this->reading;
        if (!$this->profileApplied || !filled($reading['tare_revision'] ?? null)) {
            $this->addError('setup', 'Apply a calibration and complete a fresh empty-tray tare before continuing.');

            return;
        }

        $this->countingStarted = true;
        $this->showSetup = false;
        $this->identityConfirmed = false;
        $this->pending = [];
    }

    public function backToMedicine(CountingSessionService $sessions): void
    {
        if ($this->sessionId) {
            $sessions->release(auth()->user(), $this->sessionId);
            $this->sessionId = null;
        }
        $this->setupStep = 1;
        $this->profileId = '';
        $this->resetValidation();
    }

    public function cancelSetup(CountingSessionService $sessions): void
    {
        if ($this->sessionId) {
            $sessions->release(auth()->user(), $this->sessionId);
        }
        $this->sessionId = null;
        $this->profileId = '';
        $this->setupStep = 1;
        $this->showSetup = false;
        $this->resetValidation();
    }

    public function heartbeat(CountingSessionService $sessions): void
    {
        $this->checkMode();
        if ($this->sessionId) {
            $s = CountingSession::find($this->sessionId);
            if (!$s || $s->user_id !== auth()->id() || $s->expires_at->isPast()) {
                $this->sessionId = null;
                $this->pending = [];
                $this->showReview = false;

                return;
            }
            $sessions->owned(auth()->user(), $s->id)->update(['expires_at' => now()->addSeconds(config('inventory.session_seconds'))]);
        }
    }

    public function finish(CountingSessionService $sessions): void
    {
        if ($this->sessionId) {
            $sessions->release(auth()->user(), $this->sessionId);
        }
        $this->sessionId = null;
        $this->pending = [];
        $this->showReview = false;
        Flux::toast('Scale released.');
    }

    public function saveCalibration(CalibrationService $service): void
    {
        $this->validate(['notes' => 'nullable|string|max:2000']);
        $p = $service->save(auth()->user(), $this->sessionId ?? '', $this->notes);
        $this->profileId = (string) $p->id;
        unset($this->profiles);
        Flux::toast('Calibration saved. Load the profile, then tare the empty tray.');
    }

    public function updatedProfileId(): void
    {
        $this->pending = [];
        $this->showReview = false;
        $this->identityConfirmed = false;
    }

    public function updatedMethod(): void
    {
        $this->pending = [];
        $this->showReview = false;
        $this->identityConfirmed = false;
    }

    public function loadProfile(CalibrationService $service): void
    {
        $this->validate(['profileId' => 'required|integer']);
        $service->apply(auth()->user(), $this->sessionId ?? '', (int) $this->profileId);
        Flux::toast('Profile queued. Wait for acknowledgement, then tare the empty tray.');
    }

    #[Computed]
    public function readyToReview(): bool
    {
        if ($this->method === 'manual') {
            return (bool) $this->medicine;
        }
        $s = $this->sessionId ? CountingSession::find($this->sessionId) : null;
        $r = $this->reading;

        return $s && $s->user_id === auth()->id() && $s->expires_at->isFuture() && $s->acknowledged && $s->medicine_id === (int) $this->medicineId && $s->calibration_profile_id === (int) $this->profileId && $s->calibration_profile_id === ($r['applied_profile_id'] ?? null) && $s->calibration_session_id === ($r['calibration_session_id'] ?? null) && $s->tare_revision !== null && $s->tare_revision === ($r['tare_revision'] ?? null) && str_ends_with($this->status, 'Stable reading');
    }

    public function review(): void
    {
        $this->checkMode();
        $this->validate(['medicineId' => 'required|integer', 'method' => 'required|in:manual,scale', 'reason' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000', 'identityConfirmed' => 'accepted', 'manualQuantity' => [$this->method === 'manual' ? 'required' : 'nullable', 'integer', 'min:0', 'max:1000000']]);
        $m = $this->medicine;
        abort_unless($m, 422);
        $this->authorize('count', $m);
        $r = $this->reading;
        if ($this->method === 'scale') {
            app(CountingSessionService::class)->owned(auth()->user(), $this->sessionId ?? '');
            if (!$this->readyToReview || !$r) {
                $this->addError('count', 'A fresh stable reading is required.');

                return;
            }
        }
        $this->pending = ['medicine_id' => $m->id, 'stock_revision' => $m->stock_revision, 'source' => $this->method, 'quantity' => $this->method === 'manual' ? $this->manualQuantity : null, 'reason' => $this->reason, 'notes' => $this->notes, 'identity_confirmed' => true, 'idempotency_key' => (string) Str::uuid(), 'session_id' => $this->method === 'scale' ? $this->sessionId : null, 'boot_id' => $this->method === 'scale' ? $r['boot_id'] : null, 'sequence' => $this->method === 'scale' ? $r['sequence'] : null];
        $this->pending['display_quantity'] = $this->method === 'manual' ? $this->manualQuantity : $r['estimated_count'];
        $this->pending['previous_quantity'] = $m->quantity;
        $this->showReview = true;
    }

    public function confirm(InventoryCountService $service, CountingSessionService $sessions): void
    {
        abort_unless($this->pending, 422);
        $data = $this->pending;
        unset($data['display_quantity'], $data['previous_quantity']);
        $service->save(auth()->user(), $data);
        if ($this->sessionId) {
            $sessions->release(auth()->user(), $this->sessionId);
        }
        $this->showReview = false;
        $this->showSetup = false;
        $this->countingStarted = false;
        $this->sessionId = null;
        $this->profileId = '';
        $this->medicineId = '';
        $this->manualQuantity = null;
        $this->identityConfirmed = false;
        $this->pending = [];
        unset($this->medicine);
        Flux::toast('Inventory count saved and scale released.', variant: 'success');
    }
}; ?>
@php
    $displayStatus = $method === 'manual' ? 'Manual count' : $this->status;
    $isDisconnected = $method === 'scale' && in_array($this->status, ['Disconnected', 'Reading stale'], true);
    $hasFreshTare = $this->profileApplied && filled($this->reading['tare_revision'] ?? null);
    $estimate = $this->reading['estimated_count'] ?? null;
    $displayEstimate = $isDisconnected ? null : $estimate;
@endphp

<div wire:poll.5s="heartbeat" class="space-y-6">
    <x-page-heading title="Count Stock">
        @if ($simulation)
            <flux:badge color="amber">Isolated simulation</flux:badge>
        @endif
    </x-page-heading>

    <x-form-errors />

    @if ($simulation)
        <flux:callout variant="warning">Demo inventory only. Open the <a class="underline" href="{{ route('simulator') }}"
                target="_blank">simulator controls</a> in another tab.</flux:callout>
    @endif

    @if (!$countingStarted)
        <section class="mx-auto grid min-h-[55vh] max-w-3xl place-items-center py-10 text-center">
            <div>
                <h2 class="mt-6 text-3xl font-semibold tracking-tight text-zinc-950">Start a new stock count</h2>
                <p class="mx-auto mt-3 max-w-xl text-base leading-7 text-zinc-600">Choose one medicine, decide whether
                    to count manually or use the scale, then confirm the new inventory balance.</p>
                <flux:button variant="primary" wire:click="startCount" class="mt-7" wire:loading.attr="disabled"
                    wire:target="startCount">
                    {{ $sessionId ? 'Continue scale setup' : 'Start now' }}
                </flux:button>
                @if ($sessionId)
                    <div class="mt-4">
                        <flux:button size="sm" wire:click="finish">Release reserved scale</flux:button>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <flux:modal wire:model="showSetup" class="md:w-2xl">
        <div class="space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-[#176B65]">Step {{ $setupStep }} of 2</p>
                    <flux:heading size="xl">
                        {{ $setupStep === 1 ? 'Choose the medicine and count method' : 'Prepare the scale' }}
                    </flux:heading>
                    <flux:text>
                        {{ $setupStep === 1 ? 'Start with the exact medicine you are counting.' : 'Use a saved calibration or create a new one.' }}
                    </flux:text>
                </div>
                @if ($setupStep === 2)
                    <flux:badge
                        :color="str_ends_with($this->status, 'Stable reading') ? 'teal' : ($isDisconnected ? 'red' : 'amber')">
                        {{ $this->status }}</flux:badge>
                @endif
            </div>

            <x-form-errors />

            @if ($setupStep === 1)
                <div class="space-y-5">
                    <flux:input wire:model.live.debounce.300ms="search" label="Search medicines"
                        placeholder="Generic name or code" icon="magnifying-glass" />
                    <flux:select wire:model.live="medicineId" label="Medicine">
                        <option value="">Choose a medicine</option>
                        @foreach ($this->medicines as $m)
                            <option value="{{ $m->id }}" wire:key="wizard-medicine-{{ $m->id }}">
                                {{ $m->identity() }}</option>
                        @endforeach
                    </flux:select>

                    @if ($this->medicine)
                        <div class="flex items-center justify-between gap-4 rounded-xl bg-zinc-50 px-4 py-3">
                            <span class="text-sm text-zinc-600">Current balance</span>
                            <strong>{{ $this->medicine->quantity === null ? 'Not counted' : number_format($this->medicine->quantity) . ' pieces' }}</strong>
                        </div>
                    @endif

                    <div class="rounded-xl border border-zinc-200 p-4">
                        <flux:heading>Enter a manual count</flux:heading>
                        <flux:text>Use this when staff have manually verified every piece.</flux:text>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div class="flex-1">
                                <flux:input wire:model.live="manualQuantity" label="Verified quantity" type="number"
                                    min="0" max="1000000" />
                            </div>
                            <flux:button variant="primary" wire:click="continueManually"
                                :disabled="!$medicineId || $manualQuantity === null" wire:loading.attr="disabled"
                                wire:target="continueManually">Continue manually</flux:button>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 text-sm text-zinc-400"><span
                            class="h-px flex-1 bg-zinc-200"></span><span>or</span><span
                            class="h-px flex-1 bg-zinc-200"></span></div>

                    <div class="rounded-xl border border-[#176B65]/25 bg-[#EAF1EF] p-4">
                        <flux:heading>Use the medicine scale</flux:heading>
                        <flux:text>Reserve the scale, prepare its calibration, then review live estimates.</flux:text>
                        <flux:button variant="primary" wire:click="continueWithScale" :disabled="!$medicineId"
                            wire:loading.attr="disabled" wire:target="continueWithScale" class="mt-4">Use the scale
                        </flux:button>
                    </div>
                </div>
            @else
                <div class="space-y-5">
                    <div
                        class="flex flex-col gap-3 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-medium text-teal-900">{{ $this->medicine?->identity() }}</p>
                            <p class="text-sm text-teal-800">Scale reserved for you while this window remains open.</p>
                        </div>
                        <flux:button size="sm" wire:click="backToMedicine">Change medicine</flux:button>
                    </div>

                    @if ($isDisconnected)
                        <flux:callout variant="warning">The scale is disconnected. Restore the WEB connection before
                            applying or capturing a calibration.</flux:callout>
                    @endif

                    <div class="space-y-4 rounded-xl border border-zinc-200 p-4">
                        <div>
                            <flux:heading>Use a saved calibration</flux:heading>
                            <flux:text>Select the profile for this exact medicine.</flux:text>
                        </div>
                        <flux:select wire:model.live="profileId" label="Saved calibration">
                            <option value="">Choose a calibration</option>
                            @foreach ($this->profiles as $p)
                                <option value="{{ $p->id }}" wire:key="wizard-profile-{{ $p->id }}">
                                    Version {{ $p->version }} — {{ $p->calibrated_at->format('d M Y H:i') }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:button variant="primary" wire:click="loadProfile"
                            :disabled="!$profileId || $isDisconnected" wire:loading.attr="disabled"
                            wire:target="loadProfile">Apply selected calibration</flux:button>
                    </div>

                    <div class="space-y-4 rounded-xl border border-zinc-200 p-4">
                        <div>
                            <flux:heading>Create a new calibration</flux:heading>
                            <flux:text>Complete these steps on the physical scale while the OLED shows WEB.</flux:text>
                        </div>
                        <ol class="grid gap-2 text-sm text-zinc-700 sm:grid-cols-2">
                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>1.</strong> Press NEW.</li>
                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>2.</strong> Empty tray, press TARE.</li>
                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>3.</strong> Add one piece, press SAMPLE.
                            </li>
                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>4.</strong> Add nine more, press SAMPLE.
                            </li>
                            <li class="rounded-lg bg-zinc-50 px-3 py-2 sm:col-span-2"><strong>5.</strong> Press
                                CALIBRATE, then save it here.</li>
                        </ol>
                        <flux:button wire:click="saveCalibration" :disabled="!$this->captureReady"
                            wire:confirm="Save this calibration for the selected medicine?" wire:loading.attr="disabled"
                            wire:target="saveCalibration">Save new calibration</flux:button>
                    </div>

                    @if ($this->profileApplied && !$hasFreshTare)
                        <flux:callout variant="warning">Calibration applied. Empty the tray and press TARE on the scale
                            before continuing.</flux:callout>
                    @elseif($this->profileApplied && $hasFreshTare)
                        <flux:callout variant="success">Scale setup is complete. You can continue to the live count.
                        </flux:callout>
                    @endif

                    @error('setup')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <div
                        class="flex flex-col-reverse gap-3 border-t border-zinc-200 pt-5 sm:flex-row sm:justify-between">
                        <flux:button wire:click="cancelSetup">Cancel and release scale</flux:button>
                        <flux:button variant="primary" wire:click="beginLiveCount"
                            :disabled="!$this->profileApplied || !$hasFreshTare" wire:loading.attr="disabled"
                            wire:target="beginLiveCount">Continue to live count</flux:button>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>

    @if ($countingStarted)
        <div class="mx-auto max-w-3xl space-y-6">
            @if (false)
                <div class="space-y-6">
                    <x-inventory-card>
                        <div class="flex items-center gap-3">
                            <span
                                class="grid size-8 place-items-center rounded-full bg-[#176B65] text-sm font-semibold text-white">1</span>
                            <div>
                                <flux:heading size="lg">Choose what you are counting</flux:heading>
                                <flux:text>Select one medicine and how its quantity was obtained.</flux:text>
                            </div>
                        </div>

                        <div class="mt-6 space-y-5">
                            <flux:input wire:model.live.debounce.300ms="search" label="Search medicines"
                                placeholder="Generic name or code" icon="magnifying-glass" />
                            <flux:select wire:model.live="medicineId" label="Medicine">
                                <option value="">Choose a medicine</option>
                                @foreach ($this->medicines as $m)
                                    <option value="{{ $m->id }}" wire:key="select-{{ $m->id }}">
                                        {{ $m->identity() }}</option>
                                @endforeach
                            </flux:select>

                            @if ($this->medicine)
                                <div class="flex items-center justify-between gap-4 rounded-xl bg-zinc-50 px-4 py-3">
                                    <span class="text-sm text-zinc-600">Recorded balance</span>
                                    <strong
                                        class="text-lg text-zinc-950">{{ $this->medicine->quantity === null ? 'Not counted' : number_format($this->medicine->quantity) . ' pieces' }}</strong>
                                </div>
                            @endif

                            <flux:select wire:model.live="method" label="How are you counting?">
                                <option value="scale">Use the medicine scale</option>
                                <option value="manual">Enter a manually verified count</option>
                            </flux:select>

                            @if ($method === 'manual')
                                <flux:input wire:model="manualQuantity" label="Verified piece count" type="number"
                                    min="0" />
                            @endif
                        </div>
                    </x-inventory-card>

                    @if ($method === 'scale')
                        <x-inventory-card>
                            <div class="flex items-center gap-3">
                                <span
                                    class="grid size-8 place-items-center rounded-full bg-[#176B65] text-sm font-semibold text-white">2</span>
                                <div>
                                    <flux:heading size="lg">Prepare the scale</flux:heading>
                                    <flux:text>Reserve it, then use a saved calibration or create a new one.</flux:text>
                                </div>
                            </div>

                            <div class="mt-6 space-y-5">
                                @if (!$sessionId)
                                    <div class="rounded-xl border border-zinc-200 p-4">
                                        <p class="font-medium text-zinc-950">The scale is available</p>
                                        <p class="mt-1 text-sm leading-6 text-zinc-600">Reserve it for the selected
                                            medicine before touching the physical buttons.</p>
                                        <flux:button variant="primary" wire:click="acquire" :disabled="!$medicineId"
                                            wire:loading.attr="disabled" wire:target="acquire"
                                            class="mt-4 w-full sm:w-auto">
                                            Reserve scale for this medicine
                                        </flux:button>
                                    </div>
                                @else
                                    <div
                                        class="flex flex-col gap-3 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="font-medium text-teal-900">Scale reserved for you</p>
                                            <p class="text-sm text-teal-800">Keep this page open while counting.</p>
                                        </div>
                                        <flux:button wire:click="finish" size="sm" wire:loading.attr="disabled"
                                            wire:target="finish">Release scale</flux:button>
                                    </div>

                                    <div class="space-y-3 rounded-xl border border-zinc-200 p-4">
                                        <div>
                                            <p class="font-medium text-zinc-950">Use a saved calibration</p>
                                            <p class="mt-1 text-sm text-zinc-600">Recommended when this medicine
                                                already has a profile.</p>
                                        </div>
                                        <flux:select wire:model.live="profileId" label="Saved calibration">
                                            <option value="">Choose a calibration</option>
                                            @foreach ($this->profiles as $p)
                                                <option value="{{ $p->id }}"
                                                    wire:key="profile-{{ $p->id }}">Version
                                                    {{ $p->version }} — {{ $p->calibrated_at->format('d M Y H:i') }}
                                                </option>
                                            @endforeach
                                        </flux:select>
                                        <flux:button variant="primary" wire:click="loadProfile"
                                            :disabled="!$profileId || $isDisconnected" wire:loading.attr="disabled"
                                            wire:target="loadProfile" class="w-full sm:w-auto">
                                            Use selected calibration
                                        </flux:button>
                                        @if ($this->profileApplied)
                                            <p class="text-sm font-medium text-teal-700">Calibration applied.
                                                {{ $hasFreshTare ? 'Fresh tare received.' : 'Empty the tray and press TARE on the scale.' }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="space-y-4 rounded-xl border border-zinc-200 p-4">
                                        <div>
                                            <p class="font-medium text-zinc-950">Create a new calibration</p>
                                            <p class="mt-1 text-sm text-zinc-600">Use this only when there is no
                                                suitable saved calibration.</p>
                                        </div>
                                        <ol class="grid gap-2 text-sm text-zinc-700 sm:grid-cols-2">
                                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>1.</strong> Press NEW.
                                            </li>
                                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>2.</strong> Empty tray,
                                                press TARE.</li>
                                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>3.</strong> Add one
                                                piece, press SAMPLE.</li>
                                            <li class="rounded-lg bg-zinc-50 px-3 py-2"><strong>4.</strong> Add nine
                                                more, press SAMPLE.</li>
                                            <li class="rounded-lg bg-zinc-50 px-3 py-2 sm:col-span-2">
                                                <strong>5.</strong> Press CALIBRATE, then save it below.
                                            </li>
                                        </ol>
                                        <flux:button wire:click="saveCalibration" :disabled="!$this->captureReady"
                                            wire:confirm="Save this new calibration for the selected medicine?"
                                            wire:loading.attr="disabled" wire:target="saveCalibration"
                                            class="w-full sm:w-auto">
                                            Save new calibration
                                        </flux:button>
                                        @if (!$this->captureReady)
                                            <p class="text-xs leading-5 text-zinc-500">This button becomes available
                                                after the scale completes all five calibration steps while showing WEB.
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </x-inventory-card>
                    @endif
                </div>
            @endif

            <div class="space-y-6">
                <div
                    class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-medium text-zinc-950">{{ $this->medicine?->identity() }}</p>
                        <p class="text-sm text-zinc-500">
                            {{ $method === 'manual' ? 'Manually verified count' : 'Medicine scale count' }}</p>
                    </div>
                    <flux:button size="sm" wire:click="startCount">Change setup</flux:button>
                </div>
                <x-inventory-card>
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <flux:heading size="lg">Count all stock</flux:heading>
                                <flux:badge
                                    :color="$method === 'manual' ? 'zinc' : (str_ends_with($this->status, 'Stable reading') ? 'teal' : ($isDisconnected ? 'red' : 'amber'))">
                                    {{ $displayStatus }}</flux:badge>
                            </div>
                            <flux:text>Only a confirmed count changes inventory.</flux:text>
                        </div>
                    </div>

                    <div class="py-9 text-center">
                        <p class="text-7xl font-semibold tracking-tight text-[#176B65]">
                            {{ $method === 'manual' ? $manualQuantity ?? '--' : $displayEstimate ?? '--' }}</p>
                        <p class="mt-2 text-sm text-zinc-500">
                            {{ $method === 'manual' ? 'manually verified pieces' : 'estimated pieces' }}</p>
                    </div>

                    @if ($method === 'scale')
                        <div
                            class="flex flex-col gap-1 border-t border-zinc-200 pt-4 text-xs text-zinc-500 sm:flex-row sm:justify-between">
                            <span>{{ $this->scale?->name ?? 'Scale not provisioned' }}</span>
                            <span>{{ isset($this->reading['received_at']) ? 'Updated ' . max(0, now()->getTimestamp() - $this->reading['received_at']) . ' seconds ago' : 'No reading received' }}</span>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-zinc-600">A stable reading does not guarantee an accurate
                            piece count and is not dispensing verification.</p>
                    @endif
                </x-inventory-card>

                <x-inventory-card>
                    <flux:heading size="lg">Review before saving</flux:heading>
                    <p class="mt-1 text-sm leading-6 text-zinc-600">The saved quantity replaces the current balance; it
                        does not add to it.</p>

                    <div class="mt-5 space-y-5">
                        <flux:callout>
                            {{ $method === 'manual' ? 'Confirm that the quantity includes all stock for this medicine.' : 'Put all stock for this medicine on the scale. Do not save a partial tray as the total balance.' }}
                        </flux:callout>
                        <flux:checkbox wire:model.live="identityConfirmed"
                            label="I checked the medicine identity and included all stock." />
                        <flux:input wire:model="reason" label="Reason for this count" />
                        <flux:textarea wire:model="notes" label="Notes (optional)" rows="2" />

                        <flux:button variant="primary" wire:click="review"
                            :disabled="!$this->readyToReview || !$identityConfirmed" wire:loading.attr="disabled"
                            wire:target="review" class="w-full">
                            {{ $displayEstimate !== null && $method === 'scale' ? 'Review and save ' . $displayEstimate . ' pieces' : 'Review inventory count' }}
                        </flux:button>

                        @if (!$this->readyToReview && $method === 'scale')
                            <p class="text-center text-xs leading-5 text-zinc-500">Complete the highlighted “Do this
                                next” instruction before saving.</p>
                        @endif
                    </div>
                </x-inventory-card>
            </div>
        </div>
    @endif

    <flux:modal wire:model="showReview" class="md:w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Save this inventory count?</flux:heading>
                <flux:text>This is the final confirmation. Live readings never save automatically.</flux:text>
            </div>
            <x-form-errors />
            <p class="font-medium">{{ $this->medicine?->identity() }}</p>
            <div class="rounded-xl bg-zinc-50 p-4">
                <p class="text-sm text-zinc-500">Recorded balance → new balance</p>
                <p class="mt-1 text-3xl font-semibold">{{ $pending['previous_quantity'] ?? 'Not counted' }} →
                    {{ $pending['display_quantity'] ?? '--' }}</p>
                <p class="mt-2 text-sm text-zinc-600">
                    {{ isset($pending['previous_quantity'], $pending['display_quantity']) ? 'Adjustment: ' . sprintf('%+d', $pending['display_quantity'] - $pending['previous_quantity']) : 'This will be the first confirmed balance.' }}
                </p>
            </div>
            <flux:badge>{{ ($pending['source'] ?? '') === 'manual' ? 'Manually verified' : 'Scale estimate' }}
            </flux:badge>
            <p class="text-sm leading-6 text-zinc-600">Saving creates a read-only history record. Corrections must be
                entered as a new audited count.</p>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <flux:button wire:click="$set('showReview', false)">Go back</flux:button>
                <flux:button variant="primary" wire:click="confirm" wire:loading.attr="disabled"
                    wire:target="confirm">Save inventory count</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
