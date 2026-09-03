<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An owner listing a new board: same core fields as Shared\StoreBillboardRequest,
 * but `photo` and `permit_document` are real uploaded files (not strings) and
 * both are required, and a valid future permit expiry date must be supplied.
 * `status` / `rating` / `listing_status` are server-controlled and never taken
 * from the request.
 */
class StoreBillboardListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'permit_expiry_date' => ['required', 'date', 'after:today'],
            'photo' => ['required', 'image', 'max:5120'],
            'permit_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
