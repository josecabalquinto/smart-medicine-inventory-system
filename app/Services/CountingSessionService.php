<?php

namespace App\Services;

use App\Models\CountingSession;
use App\Models\Medicine;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CountingSessionService
{
    public function acquire(User $user, Medicine $medicine, Scale $scale): CountingSession
    {
        Gate::forUser($user)->authorize('count', $medicine);
        $this->checkMode($medicine, $scale);

        return Cache::lock('scale-'.$scale->id, 10)->block(5, function () use ($user, $medicine, $scale) {
            return DB::transaction(function () use ($user, $medicine, $scale) {
                $old = CountingSession::where('scale_id', $scale->id)->first();
                if ($old && $old->expires_at->isFuture()) {
                    if ($old->user_id === $user->id && $old->medicine_id === $medicine->id) {
                        return $old;
                    }
                    throw ValidationException::withMessages(['session' => 'The scale is reserved. Finish the existing session or wait for it to expire.']);
                }
                $old?->delete();

                return CountingSession::create(['id' => (string) Str::uuid(), 'scale_id' => $scale->id, 'medicine_id' => $medicine->id, 'user_id' => $user->id, 'expires_at' => now()->addSeconds(config('inventory.session_seconds'))]);
            });
        });
    }

    public function owned(User $user, string $id): CountingSession
    {
        $s = CountingSession::find($id);
        if (! $user->active || ! $s || $s->user_id !== $user->id || $s->expires_at->isPast()) {
            throw ValidationException::withMessages(['session' => 'Your scale session expired or belongs to another staff member.']);
        }
        Gate::forUser($user)->authorize('count', Medicine::findOrFail($s->medicine_id));

        return $s;
    }

    public function release(User $user, string $id): void
    {
        $this->owned($user, $id)->delete();
    }

    public function checkMode(Medicine $medicine, Scale $scale): void
    {
        if (! $scale->active || $medicine->simulation !== $scale->simulation) {
            throw ValidationException::withMessages(['session' => 'The medicine and scale must belong to the same inventory mode.']);
        }
        if ($scale->simulation && (! app()->environment('local', 'testing') || ! config('inventory.simulation'))) {
            abort(403, 'Simulation is disabled.');
        }
    }
}
