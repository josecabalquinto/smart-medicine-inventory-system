<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\CountingSession;
use App\Models\DeviceCommand;
use App\Models\Scale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceReadingService
{
    public const STAGES = ['new', 'tared', 'one_sample', 'ten_sample', 'ready', 'profile_pending', 'tare_required'];

    private const NEXT = [
        'new' => ['new', 'tared'], 'tared' => ['tared', 'one_sample'],
        'one_sample' => ['one_sample', 'ten_sample'], 'ten_sample' => ['ten_sample', 'ready'],
        'ready' => ['ready', 'tared'], 'profile_pending' => ['profile_pending', 'tare_required'],
        'tare_required' => ['tare_required', 'ready'],
    ];

    /** @return array<string, mixed>|null */
    public function latest(Scale $scale): ?array
    {
        return Cache::get('reading-'.$scale->id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function ingest(Scale $scale, array $data): array
    {
        // Normalize validated JSON numbers so 100000 and 100000.0 share the same zero point.
        foreach (['sequence', 'applied_profile_id', 'applied_profile_version', 'estimated_count'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['current_raw', 'current_tare_raw'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = (float) $data[$field];
            }
        }
        $data['stable'] = (bool) $data['stable'];
        $data['calibrated'] = (bool) $data['calibrated'];
        if (($data['applied_profile_id'] === null) !== ($data['applied_profile_version'] === null)) {
            $this->fail('Profile ID and version must be supplied together.');
        }

        return Cache::lock('scale-'.$scale->id, 10)->block(5, function () use ($scale, $data) {
            $scale->refresh();
            if (! $scale->active || $data['device_id'] !== $scale->device_identifier) {
                $this->fail('Device inactive or identity mismatch.');
            }
            $old = $this->latest($scale);
            $changed = $scale->boot_id !== $data['boot_id'];
            if ($changed && DB::table('device_boots')->where('scale_id', $scale->id)->where('boot_id', $data['boot_id'])->exists()) {
                $this->fail('Retired boot ID.');
            }
            if (! $changed && $data['sequence'] <= $scale->last_sequence) {
                $this->fail('Duplicate or out-of-order sequence.');
            }
            if ($changed && ($data['calibration_stage'] !== 'new' || $data['applied_profile_id'] !== null || $data['calibrated'])) {
                $this->fail('A new boot must begin at NEW without an applied profile.');
            }
            $session = CountingSession::where('scale_id', $scale->id)->where('expires_at', '>', now())->first();
            $newCalibration = $data['calibration_stage'] === 'new' && (! $old || $old['calibration_session_id'] !== $data['calibration_session_id'] || $old['calibration_stage'] !== 'new');
            if (! $changed && $old && ! $newCalibration) {
                $expectedStage = $session && $session->acknowledged && $old['calibration_session_id'] !== $session->calibration_session_id ? 'tare_required' : $old['calibration_stage'];
                if (! in_array($data['calibration_stage'], self::NEXT[$expectedStage] ?? [], true)) {
                    $this->fail('Invalid calibration stage transition.');
                }
                if ($data['calibration_session_id'] !== ($session->calibration_session_id ?? $old['calibration_session_id'])) {
                    $this->fail('Calibration session mismatch; start with NEW.');
                }
            }
            if ($data['calibration_stage'] === 'new' && ($data['calibrated'] || $data['applied_profile_id'] !== null || $data['tare_revision'] !== null)) {
                $this->fail('NEW must clear calibration, profile and tare.');
            }
            foreach (['current_raw', 'current_tare_raw'] as $key) {
                if (isset($data[$key]) && ! is_finite((float) $data[$key])) {
                    $this->fail('Raw readings must be finite.');
                }
            }
            if ($data['calibration_stage'] === 'tare_required' && ($data['tare_revision'] !== null || $data['current_tare_raw'] !== null || $data['estimated_count'] !== null)) {
                $this->fail('Profile application must clear the current tare and estimate.');
            }
            $tare = null;
            if ($data['tare_revision'] !== null) {
                if ($data['current_tare_raw'] === null) {
                    $this->fail('A tare revision requires a raw zero point.');
                }
                $tare = DB::table('device_tares')->where('scale_id', $scale->id)->where('tare_revision', $data['tare_revision'])->first();
                if ($tare && ($tare->boot_id !== $data['boot_id'] || $tare->calibration_session_id !== $data['calibration_session_id'] || (float) $tare->tare_raw !== (float) $data['current_tare_raw'])) {
                    $this->fail('Tare identifiers cannot be reused across sessions or changed zero points.');
                }
            }
            DB::transaction(function () use ($scale, $data, $changed, $newCalibration, $session, $tare) {
                if ($data['tare_revision'] !== null && ! $tare) {
                    DB::table('device_tares')->insert(['scale_id' => $scale->id, 'tare_revision' => $data['tare_revision'], 'boot_id' => $data['boot_id'], 'calibration_session_id' => $data['calibration_session_id'], 'tare_raw' => $data['current_tare_raw'], 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($session && $session->acknowledged && $data['calibration_session_id'] === $session->calibration_session_id && $data['applied_profile_id'] === $session->calibration_profile_id && $data['calibration_stage'] === 'ready' && $data['tare_revision'] !== null) {
                    $session->update(['tare_revision' => $data['tare_revision']]);
                }
                if ($changed) {
                    DB::table('device_boots')->insert(['scale_id' => $scale->id, 'boot_id' => $data['boot_id'], 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($changed || $newCalibration) {
                    DeviceCommand::where('scale_id', $scale->id)->where('status', 'pending')->update(['status' => 'superseded']);
                    $session?->update(['calibration_profile_id' => null, 'acknowledged' => false, 'tare_revision' => null, 'boot_id' => $data['boot_id'], 'calibration_session_id' => $data['calibration_session_id']]);
                    Cache::forget('capture-'.$scale->id);
                }
                $scale->update(['boot_id' => $data['boot_id'], 'last_sequence' => $data['sequence'], 'revision' => $scale->revision + 1, 'last_seen_at' => now(), 'firmware_version' => $data['firmware_version']]);
            });
            $data['received_at'] = now()->getTimestamp();
            $data['scale_id'] = $scale->id;
            Cache::put('reading-'.$scale->id, $data, now()->addDay());
            Cache::put('snapshot-'.$scale->id.'-'.$data['boot_id'].'-'.$data['sequence'], $data, now()->addMinutes(2));

            return $data;
        });
    }

    public function status(?Scale $scale): string
    {
        if (! $scale || ! $scale->active || ! $scale->last_seen_at) {
            return 'Disconnected';
        }
        $settings = ApplicationSetting::findOrFail(1);
        if ($scale->last_seen_at->lt(now()->subSeconds($settings->offline_seconds))) {
            return 'Disconnected';
        }
        $r = $this->latest($scale);
        if (! $r || $r['received_at'] < now()->getTimestamp() - $settings->stale_seconds) {
            return 'Reading stale';
        }
        if (DeviceCommand::where('scale_id', $scale->id)->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
            return 'Saved profile pending application';
        }
        if ($r['calibration_stage'] === 'tare_required' || ($r['calibrated'] && ! $r['tare_revision'])) {
            return 'Fresh tare required';
        }
        if (! $r['calibrated']) {
            return $r['calibration_stage'] === 'new' ? 'Calibration required' : 'Calibration in progress';
        }

        return $r['stable'] ? 'Connected - Stable reading' : 'Connected - Unsettled reading';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['reading' => $message]);
    }
}
