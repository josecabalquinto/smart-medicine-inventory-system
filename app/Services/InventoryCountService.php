<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\CalibrationProfile;
use App\Models\InventoryCount;
use App\Models\Medicine;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InventoryCountService
{
    public function __construct(private CountingSessionService $sessions, private DeviceReadingService $readings) {}

    /** @param array<string, mixed> $input */
    public function save(User $user, array $input): InventoryCount
    {
        $d = Validator::make($input, [
            'medicine_id' => 'required|integer', 'stock_revision' => 'required|integer|min:0',
            'source' => 'required|in:scale,manual', 'quantity' => 'nullable|integer|min:0|max:1000000',
            'reason' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'required|uuid', 'session_id' => 'nullable|uuid',
            'boot_id' => 'nullable|uuid', 'sequence' => 'nullable|integer|min:0', 'identity_confirmed' => 'required|accepted',
        ])->validate();
        ksort($d);
        $hash = hash('sha256', json_encode([$user->id, $d], JSON_THROW_ON_ERROR));
        $medicine = Medicine::whereKey($d['medicine_id'])->firstOrFail();
        Gate::forUser($user)->authorize('count', $medicine);
        if ($medicine->simulation && (! app()->environment('local', 'testing') || ! config('inventory.simulation'))) {
            abort(403);
        }

        return Cache::lock('count-idempotency-'.$d['idempotency_key'], 15)->block(5, function () use ($user, $d, $hash, $medicine) {
            $existing = InventoryCount::where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    $this->fail('Idempotency key conflicts with an earlier request.');
                }

                return $existing;
            }
            // The scale lock serializes telemetry/session changes with snapshot validation and commit.
            $lock = $d['source'] === 'scale' ? 'scale-'.($this->sessions->owned($user, $d['session_id'] ?? '')->scale_id) : 'medicine-'.$medicine->id;

            return Cache::lock($lock, 10)->block(5, function () use ($user, $d, $hash, $medicine) {
                return DB::transaction(function () use ($user, $d, $hash, $medicine) {
                    $existing = InventoryCount::where('idempotency_key', $d['idempotency_key'])->first();
                    if ($existing) {
                        if (! hash_equals($existing->payload_hash, $hash)) {
                            $this->fail('Idempotency key conflicts with an earlier request.');
                        }

                        return $existing;
                    }
                    $medicine->refresh();
                    Gate::forUser($user)->authorize('count', $medicine);
                    $audit = [];
                    if ($d['source'] === 'manual') {
                        if (! isset($d['quantity'])) {
                            $this->fail('Enter a manually verified count.');
                        }
                        $quantity = $d['quantity'];
                    } else {
                        [$quantity,$audit] = $this->resolveSnapshot($user, $medicine, $d);
                    }
                    $previous = $medicine->quantity;
                    $updated = Medicine::whereKey($medicine->id)->where('stock_revision', $d['stock_revision'])->where('active', true)->update(['quantity' => $quantity, 'stock_revision' => DB::raw('stock_revision + 1'), 'updated_at' => now()]);
                    if ($updated !== 1) {
                        $this->fail('Stock changed since review. Reload and review the adjustment again.');
                    }

                    return InventoryCount::create(array_merge($audit, [
                        'count_method' => $d['source'], 'medicine_id' => $medicine->id, 'previous_quantity' => $previous, 'new_quantity' => $quantity,
                        'difference' => $previous === null ? null : $quantity - $previous, 'source' => $medicine->simulation ? 'simulation' : ($d['source'] === 'scale' ? 'physical_scale' : 'manual'),
                        'reason' => $d['reason'], 'notes' => $d['notes'] ?? null, 'user_id' => $user->id,
                        'idempotency_key' => $d['idempotency_key'], 'payload_hash' => $hash,
                    ]));
                });
            });
        });
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{int, array<string, mixed>}
     */
    private function resolveSnapshot(User $user, Medicine $medicine, array $d): array
    {
        $s = $this->sessions->owned($user, $d['session_id'] ?? '');
        $scale = Scale::findOrFail($s->scale_id);
        $this->sessions->checkMode($medicine, $scale);
        $p = CalibrationProfile::find($s->calibration_profile_id);
        $r = Cache::get('snapshot-'.$scale->id.'-'.($d['boot_id'] ?? '').'-'.($d['sequence'] ?? ''));
        $latest = $this->readings->latest($scale);
        $settings = ApplicationSetting::findOrFail(1);
        if (! $r || ! $latest || ! $scale->last_seen_at || $scale->last_seen_at->lt(now()->subSeconds($settings->offline_seconds)) || $latest['received_at'] < now()->getTimestamp() - $settings->stale_seconds || $r['received_at'] < now()->getTimestamp() - $settings->stale_seconds || ! $r['stable'] || ! $r['calibrated'] || $r['calibration_stage'] !== 'ready') {
            $this->fail('The reviewed scale reading is stale, unsettled, disconnected, or uncalibrated.');
        }
        if (! $p || ! $p->active || ! $s->acknowledged || $s->medicine_id !== $medicine->id || $p->medicine_id !== $medicine->id || $p->scale_id !== $scale->id || $r['applied_profile_id'] !== $p->id || $r['applied_profile_version'] !== $p->version || $r['boot_id'] !== $s->boot_id || $r['boot_id'] !== $scale->boot_id || $r['calibration_session_id'] !== $s->calibration_session_id || ! $r['tare_revision'] || $s->tare_revision !== $r['tare_revision'] || $r['current_tare_raw'] === null) {
            $this->fail('Profile acknowledgement, medicine association, or fresh tare is missing.');
        }
        foreach (['boot_id', 'calibration_session_id', 'tare_revision', 'current_tare_raw', 'applied_profile_id', 'applied_profile_version'] as $field) {
            if ($latest[$field] !== $r[$field]) {
                $this->fail('The scale context changed after review. Review a new reading.');
            }
        }
        if (! $latest['stable'] || ! $latest['calibrated'] || $latest['calibration_stage'] !== 'ready') {
            $this->fail('The scale is no longer ready.');
        }
        $estimate = round(($r['current_raw'] - $r['current_tare_raw']) / $p->raw_units_per_piece);
        if (! is_finite($estimate) || $estimate < 0 || $estimate > 1000000 || $r['estimated_count'] === null || $r['estimated_count'] !== (int) $estimate) {
            $this->fail('The device estimate does not match the raw reading and profile.');
        }

        return [(int) $estimate, [
            'scale_id' => $scale->id, 'calibration_profile_id' => $p->id, 'profile_version' => $p->version,
            'boot_id' => $r['boot_id'], 'counting_session_id' => $s->id, 'calibration_session_id' => $r['calibration_session_id'],
            'tare_revision' => $r['tare_revision'], 'reading_sequence' => $r['sequence'],
        ]];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['count' => $message]);
    }
}
