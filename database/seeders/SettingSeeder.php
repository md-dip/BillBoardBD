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
    }
}