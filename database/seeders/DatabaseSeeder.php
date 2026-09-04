<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Order matters: settings first (the booking math reads them), then
     * billboards and users. UnassignedBillboardsOwnerSeeder goes last - it
     * sweeps up every board the seeders before it did not claim.
     */
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            BillboardSeeder::class,
            BillboardPhotoSeeder::class,
            UserSeeder::class,
            OwnerDemoSeeder::class,
            UnassignedBillboardsOwnerSeeder::class,
        ]);
    }
}
