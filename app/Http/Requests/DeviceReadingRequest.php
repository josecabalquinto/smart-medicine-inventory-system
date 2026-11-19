<?php

namespace App\Http\Requests;

use App\Services\DeviceReadingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('scale');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => 'required|string|max:100', 'boot_id' => 'required|uuid', 'sequence' => 'required|integer|min:0|max:9007199254740991',
            'calibration_session_id' => 'required|uuid', 'applied_profile_id' => 'present|nullable|integer|min:1',
            'applied_profile_version' => 'present|nullable|integer|min:1', 'tare_revision' => 'present|nullable|uuid',
            'calibration_stage' => ['required', Rule::in(DeviceReadingService::STAGES)],
            'current_raw' => 'required|numeric|between:-1000000000000,1000000000000',
            'current_tare_raw' => 'present|nullable|numeric|between:-1000000000000,1000000000000',
            'estimated_count' => 'present|nullable|integer|min:0|max:1000000',
            'stable' => 'required|boolean', 'calibrated' => 'required|boolean', 'firmware_version' => 'required|string|max:100',
        ];
    }
}
