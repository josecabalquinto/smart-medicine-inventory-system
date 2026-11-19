<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->user() && ! $request->user()->active) {
            auth()->logout();
            abort(403, 'This account is disabled.');
        }

        return $next($request);
    }
}
