<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::query()->updateOrCreate(['key' => 'commission_rate'], ['value' => '10']);
        Setting::query()->updateOrCreate(['key' => 'advance_percentage'], ['value' => '30']);
        Setting::query()->updateOrCreate(['key' => 'hold_minutes'], ['value' => '15']);
        Setting::query()->updateOrCreate(['key' => 'final_payment_days'], ['value' => '7']);
        Setting::query()->updateOrCreate(['key' => 'listing_fee'], ['value' => '5000']);

        // Support contact details live here rather than only in the footer, so
        // the assistant reads the real ones from a tool instead of being left
        // to produce something plausible. An empty value means "not published"
        // and is answered as such - never filled in with a guess.
        Setting::query()->updateOrCreate(['key' => 'support_email'], ['value' => 'hello@billboardbd.com']);
        Setting::query()->updateOrCreate(['key' => 'support_phone'], ['value' => '']);
    }
}
