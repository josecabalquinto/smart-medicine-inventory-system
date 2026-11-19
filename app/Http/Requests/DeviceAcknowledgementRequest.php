<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceAcknowledgementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('scale');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['device_id' => 'required|string|max:100', 'boot_id' => 'required|uuid', 'profile_id' => 'required|integer|min:1', 'profile_version' => 'required|integer|min:1', 'calibration_session_id' => 'required|uuid', 'success' => 'required|boolean', 'error' => 'required_if:success,false|nullable|string|max:500'];
    }
}
