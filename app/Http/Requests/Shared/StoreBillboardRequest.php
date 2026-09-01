<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillboardRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['required', 'string', 'max:255'],
            'size' => ['required', 'string', 'max:50'],
            'type' => ['required', 'in:unipole,multipole,gantry,rooftop,freestanding,static,backlit,frontlit,led,neon,wall'],
            'daily_rate' => ['required', 'numeric', 'min:0'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0'],
            'pricing_mode' => ['required', 'in:daily,monthly'],
            'photo' => ['nullable', 'string'],
            'rating' => ['nullable', 'numeric', 'between:0,5'],
            'status' => ['nullable', 'in:available,booked,hidden'],
            'permit_expiry_date' => ['required', 'date'],
        ];
    }
}