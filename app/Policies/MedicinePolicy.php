<?php

namespace App\Policies;

use App\Models\Medicine;
use App\Models\User;

class MedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Medicine $medicine): bool
    {
        return $user->isAdmin();
    }

    public function count(User $user, Medicine $medicine): bool
    {
        return $user->active && $medicine->active;
    }
}
