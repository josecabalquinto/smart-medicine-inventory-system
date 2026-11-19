<?php

namespace App\Http\Middleware;

use App\Models\Scale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_if(strlen($request->getContent()) > 8192, 413, 'Payload too large.');
        abort_unless(app()->environment('local', 'testing') || $request->secure(), 403, 'Device API requires HTTPS.');
        $scale = Scale::where('device_identifier', $request->header('X-Device-ID'))->where('active', true)->where('simulation', false)->first();
        abort_unless($scale && $scale->credential_hash && $request->bearerToken() && Hash::check($request->bearerToken(), $scale->credential_hash), 401, 'Invalid device credential.');
        abort_if($request->has('device_id') && $request->input('device_id') !== $scale->device_identifier, 403, 'Device identity mismatch.');
        $request->attributes->set('scale', $scale);

        return $next($request);
    }
}
