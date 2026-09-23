<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillboardRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'size' => ['sometimes', 'required', 'string', 'max:50'],
            'type' => ['sometimes', 'required', 'in:unipole,multipole,gantry,rooftop,freestanding,static,backlit,frontlit,led,neon,wall'],
            'daily_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0'],
            'pricing_mode' => ['sometimes', 'required', 'in:daily,monthly'],
            'photo' => ['nullable', 'string'],
            'rating' => ['nullable', 'numeric', 'between:0,5'],
            'status' => ['sometimes', 'required', 'in:available,booked,hidden'],
            'permit_expiry_date' => ['sometimes', 'required', 'date'],
        ];
    }
}