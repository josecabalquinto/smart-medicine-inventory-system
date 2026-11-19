<?php

namespace App\Services;

use App\Http\Requests\DeviceAcknowledgementRequest;
use App\Http\Requests\DeviceCalibrationRequest;
use App\Http\Requests\DeviceReadingRequest;
use App\Models\CalibrationProfile;
use App\Models\CountingSession;
use App\Models\Scale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SimulatorService
{
    public function __construct(private DeviceReadingService $readings, private CalibrationService $calibrations) {}

    public function ensureEnabled(): void
    {
        abort_unless(app()->environment('local', 'testing') && config('inventory.simulation'), 403, 'Simulation is available only in local/testing with INVENTORY_SIMULATION=true.');
    }

    public function scale(): Scale
    {
        $this->ensureEnabled();

        return Scale::firstOrCreate(['simulation' => true], ['device_identifier' => 'simulator', 'name' => 'Isolated demo scale', 'active' => true]);
    }

    /** @return array<string, mixed> */
    public function send(string $action, int $quantity = 25): array
    {
        $scale = $this->scale();
        $r = $this->readings->latest($scale);
        if ($action === 'disconnect') {
            $scale->update(['last_seen_at' => now()->subDay()]);

            return $r ?? [];
        }
        if ($action === 'stale') {
            if ($r) {
                $r['received_at'] = now()->subDay()->getTimestamp();
                Cache::put('reading-'.$scale->id, $r, 86400);
            }

            return $r ?? [];
        }
        if (! $r || $action === 'reboot') {
            $r = ['device_id' => $scale->device_identifier, 'boot_id' => (string) Str::uuid(), 'sequence' => 0, 'calibration_session_id' => (string) Str::uuid(), 'applied_profile_id' => null, 'applied_profile_version' => null, 'tare_revision' => null, 'calibration_stage' => 'new', 'current_raw' => 100000, 'current_tare_raw' => null, 'estimated_count' => null, 'stable' => false, 'calibrated' => false, 'firmware_version' => 'simulator-v1'];
        } else {
            $r['sequence']++;
            if ($action === 'out_of_order') {
                $r['sequence'] -= 2;
            }
            if ($action === 'new') {
                $r = array_merge($r, ['calibration_session_id' => (string) Str::uuid(), 'calibration_stage' => 'new', 'applied_profile_id' => null, 'applied_profile_version' => null, 'tare_revision' => null, 'current_tare_raw' => null, 'calibrated' => false, 'estimated_count' => null]);
            }
            if ($action === 'tare') {
                $r['tare_revision'] = (string) Str::uuid();
                $r['current_tare_raw'] = 100000;
                $r['current_raw'] = 100000;
                $r['calibration_stage'] = $r['applied_profile_id'] ? 'ready' : 'tared';
                $r['estimated_count'] = $r['applied_profile_id'] ? 0 : null;
                $r['stable'] = true;
            }
            if ($action === 'sample_one') {
                $r['calibration_stage'] = 'one_sample';
                $r['current_raw'] = 99874.5;
            }
            if ($action === 'sample_ten') {
                $r['calibration_stage'] = 'ten_sample';
                $r['current_raw'] = 98745;
            }
            if ($action === 'calibrate') {
                $r['calibration_stage'] = 'ready';
                $r['calibrated'] = true;
                $r['estimated_count'] = 10;
                $r['stable'] = true;
            }
            if ($action === 'ack') {
                $command = $this->calibrations->commands($scale)[0] ?? null;
                abort_unless($command !== null, 422, 'No pending command.');
                $ack = ['device_id' => $scale->device_identifier, 'boot_id' => $r['boot_id'], 'profile_id' => $command['profile_id'], 'profile_version' => $command['profile_version'], 'calibration_session_id' => (string) Str::uuid(), 'success' => true];
                $this->calibrations->ack($scale, $command['command_id'], Validator::make($ack, (new DeviceAcknowledgementRequest)->rules())->validate());
                $r = array_merge($r, ['applied_profile_id' => $command['profile_id'], 'applied_profile_version' => $command['profile_version'], 'calibration_session_id' => $ack['calibration_session_id'], 'calibration_stage' => 'tare_required', 'tare_revision' => null, 'current_tare_raw' => null, 'calibrated' => true, 'estimated_count' => null]);
            }
            if (in_array($action, ['stable', 'unstable'], true)) {
                $factor = $r['applied_profile_id'] ? CalibrationProfile::whereKey($r['applied_profile_id'])->firstOrFail()->raw_units_per_piece : -125.5;
                $r['current_raw'] = 100000 + $quantity * $factor;
                $r['estimated_count'] = $quantity;
                $r['stable'] = $action === 'stable';
            }
        }
        unset($r['received_at'],$r['scale_id']);
        $r = Validator::make($r, (new DeviceReadingRequest)->rules())->validate();
        $accepted = $this->readings->ingest($scale, $r);
        if ($action === 'calibrate') {
            $s = CountingSession::where('scale_id', $scale->id)->where('expires_at', '>', now())->firstOrFail();
            $capture = ['device_id' => $scale->device_identifier, 'counting_session_id' => $s->id, 'boot_id' => $r['boot_id'], 'sequence' => $r['sequence'], 'calibration_session_id' => $r['calibration_session_id'], 'tare_raw' => 100000, 'one_piece_raw' => 99874.5, 'ten_piece_raw' => 98745];
            $this->calibrations->capture($scale, Validator::make($capture, (new DeviceCalibrationRequest)->rules())->validate());
        }

        return $accepted;
    }
}
