<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Order matters: settings first (the booking math reads them), then
     * billboards and users.
     */
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            BillboardSeeder::class,
            UserSeeder::class,
        ]);
    }
}