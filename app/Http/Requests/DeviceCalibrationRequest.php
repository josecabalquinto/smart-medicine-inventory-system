<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceCalibrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('scale');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => 'required|string|max:100', 'counting_session_id' => 'required|uuid', 'boot_id' => 'required|uuid',
            'sequence' => 'required|integer|min:0', 'calibration_session_id' => 'required|uuid',
            'tare_raw' => 'required|numeric|between:-1000000000000,1000000000000',
            'one_piece_raw' => 'required|numeric|between:-1000000000000,1000000000000',
            'ten_piece_raw' => 'required|numeric|between:-1000000000000,1000000000000',
        ];
    }
}
