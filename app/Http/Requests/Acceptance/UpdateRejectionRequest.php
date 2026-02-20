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
        $rejection = $this->route('rejection');
        $type      = $this->input('type');

        $rules = [
            'tenant_id'            => 'required|exists:tenants,id',
            'date'                 => 'required|date',
            'disputed'             => 'required|in:none,pending,won,lost',
            'carrier_controllable' => 'required|boolean',
            'driver_controllable'  => 'required|boolean',
            'rejection_reason'     => 'nullable|string|max:255',
            'type'                 => 'required|in:advanced_block,block,load',
        ];

        if ($type === 'advanced_block') {
            // Get the existing sub-record's own primary key to ignore in unique check
            $existingId = $rejection->advancedRejectedBlocks()->value('id');

            $rules += [
                'advance_block_rejection_id' => 'required|string|max:255|unique:advanced_rejected_blocks,advance_block_rejection_id,' . $existingId,
                'week_start'      => 'required|date',
                'week_end'        => 'required|date|after_or_equal:week_start',
                'impacted_blocks' => 'required|integer|min:0',
                'expected_blocks' => 'required|integer|min:0',
            ];
        }

        if ($type === 'block') {
            $existingId = $rejection->rejectedBlocks()->value('id');

            $rules += [
                'block_id'           => 'required|string|max:255|unique:rejected_blocks,block_id,' . $existingId,
                'block_driver_name'  => 'nullable|string|max:255',
                'block_start'        => 'required|date',
                'block_end'          => 'required|date|after_or_equal:block_start',
                'rejection_datetime' => 'nullable|date',
            ];
        }

        if ($type === 'load') {
            $existingId = $rejection->rejectedLoads()->value('id');

            $rules += [
                'load_id'               => 'required|string|max:255|unique:rejected_loads,load_id,' . $existingId,
                'load_driver_name'      => 'nullable|string|max:255',
                'origin_yard_arrival'   => 'required|date',
                'load_rejection_bucket' => 'nullable|in:rejected_after_start_time,rejected_0_6_hours_before_start_time,rejected_6_plus_hours_before_start_time',
            ];
        }

        return $rules;
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
