<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'commission_rate' => ['required', 'numeric', 'between:0,100'],
            'advance_percentage' => ['required', 'numeric', 'between:0,100'],
            'final_payment_days' => ['required', 'integer', 'between:1,60'],
        ];
    }
}