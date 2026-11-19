<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceAcknowledgementRequest;
use App\Http\Requests\DeviceCalibrationRequest;
use App\Http\Requests\DeviceReadingRequest;
use App\Models\CountingSession;
use App\Services\CalibrationService;
use App\Services\DeviceReadingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function reading(DeviceReadingRequest $request, DeviceReadingService $service): JsonResponse
    {
        $r = $service->ingest($request->attributes->get('scale'), $request->validated());

        return response()->json(['accepted' => true, 'sequence' => $r['sequence'], 'received_at' => $r['received_at']]);
    }

    public function calibration(DeviceCalibrationRequest $request, CalibrationService $service): JsonResponse
    {
        $service->capture($request->attributes->get('scale'), $request->validated());

        return response()->json(['captured' => true, 'saved_profile' => false]);
    }

    public function commands(Request $request, CalibrationService $service): JsonResponse
    {
        $scale = $request->attributes->get('scale');
        $session = CountingSession::where('scale_id', $scale->id)->where('expires_at', '>', now())->first();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'active_session' => $session ? ['id' => $session->id, 'expires_at' => $session->expires_at->toIso8601String()] : null,
            'commands' => $service->commands($scale),
        ]);
    }

    public function ack(DeviceAcknowledgementRequest $request, string $command, CalibrationService $service): JsonResponse
    {
        $service->ack($request->attributes->get('scale'), $command, $request->validated());

        return response()->json(['acknowledged' => true]);
    }
}
