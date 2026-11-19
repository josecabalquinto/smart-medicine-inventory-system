<?php

namespace App\Services;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    use ProfileValidationRules;

    /** @param array<string, mixed> $data */
    public function save(User $actor, ?int $id, array $data): User
    {
        $target = $id ? User::findOrFail($id) : new User;
        Gate::forUser($actor)->authorize($id ? 'update' : 'create', $id ? $target : User::class);
        $data['email'] = Str::lower(trim($data['email'] ?? ''));
        $d = Validator::make($data, ['name' => $this->nameRules(), 'email' => $this->emailRules($id), 'password' => [$id ? 'nullable' : 'required', 'string', 'min:12', 'max:255'], 'role' => 'required|in:admin,staff', 'active' => 'required|boolean'])->validate();

        return Cache::lock('manage-users', 10)->block(5, fn () => DB::transaction(function () use ($target, $d) {
            if ($target->exists) {
                $target->refresh();
            }
            if ($target->exists && $target->isAdmin() && (! $d['active'] || $d['role'] !== 'admin') && User::where('role', 'admin')->where('active', true)->count() <= 1) {
                throw ValidationException::withMessages(['active' => 'Keep at least one active administrator.']);
            }
            if (empty($d['password'])) {
                unset($d['password']);
            }
            $target->fill($d)->save();

            return $target;
        }));
    }
}
