<?php

namespace App\Policies;

use App\Models\Scale;
use App\Models\User;

class ScalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active;
    }

    public function update(User $user, Scale $scale): bool
    {
        return $user->isAdmin();
    }
}
