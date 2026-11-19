<?php

namespace App\Services;

use App\Models\CalibrationProfile;
use App\Models\CountingSession;
use App\Models\DeviceCommand;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CalibrationService
{
    public function __construct(private CountingSessionService $sessions, private DeviceReadingService $readings) {}

    /** @return array<string, float> */
    public function factor(float $tare, float $one, float $ten): array
    {
        if (! is_finite($tare) || ! is_finite($one) || ! is_finite($ten)) {
            $this->fail('Samples must be finite.');
        }
        $netOne = $one - $tare;
        $netTen = $ten - $tare;
        $factor = $netTen / 10;
        if (! is_finite($factor) || $factor === 0.0 || $netOne === 0.0 || ($netOne > 0) !== ($factor > 0) || abs($netOne / $factor - 1) > 0.75) {
            $this->fail('Samples are zero, inconsistent, or do not detect one piece. Recalibrate.');
        }

        return ['one_piece_net' => $netOne, 'ten_piece_net' => $netTen, 'raw_units_per_piece' => $factor];
    }

    /** @param array<string, mixed> $data */
    public function capture(Scale $scale, array $data): void
    {
        Cache::lock('scale-'.$scale->id, 10)->block(5, function () use ($scale, $data) {
            $s = CountingSession::whereKey($data['counting_session_id'])->where('scale_id', $scale->id)->where('expires_at', '>', now())->first();
            $r = $this->readings->latest($scale);
            if (! $s || ! $r || ! $r['calibrated'] || $r['calibration_stage'] !== 'ready' || $r['boot_id'] !== $data['boot_id'] || $r['sequence'] !== $data['sequence'] || $r['calibration_session_id'] !== $data['calibration_session_id'] || $s->calibration_session_id !== $data['calibration_session_id'] || $r['applied_profile_id'] !== null) {
                $this->fail('Capture does not match the authorized active session and ready reading.');
            }
            $this->factor($data['tare_raw'], $data['one_piece_raw'], $data['ten_piece_raw']);
            Cache::put('capture-'.$scale->id, $data, now()->addMinutes(3));
        });
    }

    public function save(User $user, string $sessionId, string $notes = ''): CalibrationProfile
    {
        $s = $this->sessions->owned($user, $sessionId);

        return Cache::lock('scale-'.$s->scale_id, 10)->block(5, function () use ($user, $sessionId, $notes) {
            $s = $this->sessions->owned($user, $sessionId);
            $scale = Scale::findOrFail($s->scale_id);
            $c = Cache::get('capture-'.$s->scale_id);
            $r = $this->readings->latest($scale);
            if (! $c || ! $r || $c['counting_session_id'] !== $s->id || $c['boot_id'] !== $r['boot_id'] || $c['calibration_session_id'] !== $r['calibration_session_id'] || ! $r['calibrated']) {
                $this->fail('No matching captured calibration. Complete the physical calibration first.');
            }

            return DB::transaction(function () use ($user, $s, $c, $notes) {
                $q = CalibrationProfile::where('medicine_id', $s->medicine_id)->where('scale_id', $s->scale_id);
                $version = (clone $q)->max('version') + 1;
                $q->update(['active' => false]);
                $p = CalibrationProfile::create(array_merge($this->factor($c['tare_raw'], $c['one_piece_raw'], $c['ten_piece_raw']), ['medicine_id' => $s->medicine_id, 'scale_id' => $s->scale_id, 'calibration_session_id' => $c['calibration_session_id'], 'original_tare_raw' => $c['tare_raw'], 'version' => $version, 'sample_quantity' => 10, 'calibrated_by' => $user->id, 'calibrated_at' => now(), 'notes' => $notes, 'active' => true]));
                // Apply through the same acknowledged protocol, including a fresh tare, even for a newly saved capture.
                $s->update(['calibration_profile_id' => null, 'acknowledged' => false, 'tare_revision' => null]);
                Cache::forget('capture-'.$s->scale_id);

                return $p;
            });
        });
    }

    public function apply(User $user, string $sessionId, int $profileId): DeviceCommand
    {
        $s = $this->sessions->owned($user, $sessionId);

        return Cache::lock('scale-'.$s->scale_id, 10)->block(5, function () use ($user, $sessionId, $profileId) {
            $s = $this->sessions->owned($user, $sessionId);
            $scale = Scale::findOrFail($s->scale_id);
            $p = CalibrationProfile::whereKey($profileId)->where('medicine_id', $s->medicine_id)->where('scale_id', $s->scale_id)->where('active', true)->firstOrFail();
            if (! $scale->boot_id || $this->readings->status($scale) === 'Disconnected') {
                $this->fail('Connect the scale before loading a profile.');
            }

            return DB::transaction(function () use ($s, $scale, $p) {
                DeviceCommand::where('scale_id', $scale->id)->where('status', 'pending')->update(['status' => 'superseded']);
                $s->update(['calibration_profile_id' => $p->id, 'acknowledged' => false, 'tare_revision' => null, 'boot_id' => $scale->boot_id, 'calibration_session_id' => $this->readings->latest($scale)['calibration_session_id']]);

                return DeviceCommand::create(['id' => (string) Str::uuid(), 'scale_id' => $scale->id, 'counting_session_id' => $s->id, 'calibration_profile_id' => $p->id, 'boot_id' => $scale->boot_id, 'expected_calibration_session_id' => $this->readings->latest($scale)['calibration_session_id'], 'expires_at' => now()->addSeconds(60)]);
            });
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function commands(Scale $scale): array
    {
        DeviceCommand::where('scale_id', $scale->id)->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);

        return DeviceCommand::where('scale_id', $scale->id)->where('status', 'pending')->where('boot_id', $scale->boot_id)->get()->filter(fn ($c) => CountingSession::whereKey($c->counting_session_id)->where('expires_at', '>', now())->exists())->map(function ($c) {
            $p = CalibrationProfile::findOrFail($c->calibration_profile_id);

            return ['expected_calibration_session_id' => $c->expected_calibration_session_id, 'command_id' => $c->id, 'type' => 'apply_profile', 'counting_session_id' => $c->counting_session_id, 'boot_id' => $c->boot_id, 'profile_id' => $p->id, 'profile_version' => $p->version, 'raw_units_per_piece' => $p->raw_units_per_piece, 'expires_at' => $c->expires_at->toIso8601String()];
        })->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function ack(Scale $scale, string $commandId, array $data): void
    {
        Cache::lock('scale-'.$scale->id, 10)->block(5, function () use ($scale, $commandId, $data) {
            $c = DeviceCommand::whereKey($commandId)->where('scale_id', $scale->id)->firstOrFail();
            $s = CountingSession::findOrFail($c->counting_session_id);
            $p = CalibrationProfile::findOrFail($c->calibration_profile_id);
            if ($c->status === 'acknowledged' && $s->calibration_session_id === $data['calibration_session_id'] && $data['success'] && $data['profile_id'] === $p->id && $data['profile_version'] === $p->version && $data['boot_id'] === $c->boot_id) {
                return;
            }
            $scale->refresh();
            if ($c->status !== 'pending' || $c->expires_at->isPast() || $s->expires_at->isPast() || $scale->boot_id !== $data['boot_id'] || $c->boot_id !== $data['boot_id'] || $data['profile_id'] !== $p->id || $data['profile_version'] !== $p->version || $c->expected_calibration_session_id !== $s->calibration_session_id || ($data['success'] && $data['calibration_session_id'] === $s->calibration_session_id)) {
                $this->fail('Expired, superseded, or mismatched acknowledgement.');
            }
            DB::transaction(function () use ($c, $s, $data) {
                $c->update(['status' => $data['success'] ? 'acknowledged' : 'failed', 'error' => $data['error'] ?? null]);
                $s->update(['acknowledged' => $data['success'], 'calibration_session_id' => $data['success'] ? $data['calibration_session_id'] : $s->calibration_session_id, 'tare_revision' => null]);
            });
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['calibration' => $message]);
    }
}
