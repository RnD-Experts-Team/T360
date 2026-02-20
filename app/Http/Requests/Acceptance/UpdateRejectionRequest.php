<?php

namespace App\Http\Requests\Acceptance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateRejectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'tenant_id'           => 'required|exists:tenants,id',
            'date'                => 'required|date',
            'disputed'            => 'required|in:none,pending,won,lost',
            'carrier_controllable' => 'required|boolean',
            'driver_controllable' => 'required|boolean',
            'rejection_reason'    => 'nullable|string|max:255',

            'type' => 'required|in:advanced_block,block,load',

            // Advanced block fields
            'week_start'      => 'required_if:type,advanced_block|date',
            'week_end'        => 'required_if:type,advanced_block|date|after_or_equal:week_start',
            'impacted_blocks' => 'required_if:type,advanced_block|integer|min:0',
            'expected_blocks' => 'required_if:type,advanced_block|integer|min:0',
            'advance_block_rejection_id' => 'required_if:type,advanced_block|string|max:255|unique:advanced_rejected_blocks,advance_block_rejection_id,' . $this->route('rejection')->id . ',rejection_id',
            // Block fields
            'block_driver_name'    => 'required_if:type,block|nullable|string|max:255',
            'block_start'          => 'required_if:type,block|date',
            'block_end'            => 'required_if:type,block|date|after_or_equal:block_start',
            'rejection_datetime'   => 'required_if:type,block|date|nullable',
            'block_id'            => 'required_if:type,block|string|max:255|unique:rejected_blocks,block_id,' . $this->route('rejection')->id . ',rejection_id',
            // Load fields
            'load_driver_name'       => 'required_if:type,load|nullable|string|max:255',
            'origin_yard_arrival'    => 'required_if:type,load|date',
            'load_rejection_bucket'  => 'required_if:type,load|in:rejected_after_start_time,rejected_0_6_hours_before_start_time,rejected_6_plus_hours_before_start_time|nullable',
            'load_id'               => 'required_if:type,load|string|max:255|unique:rejected_loads,load_id,' . $this->route('rejection')->id . ',rejection_id',
        ];
    }

    protected function prepareForValidation()
    {
        if (!is_null(Auth::user()->tenant_id)) {
            $this->merge(['tenant_id' => Auth::user()->tenant_id]);
        }
    }

    public function messages()
    {
        return [
            'type.required' => 'A rejection type (advanced_block, block, or load) is required.',
            'type.in'       => 'Rejection type must be advanced_block, block, or load.',
        ];
    }
}
