<?php

namespace Database\Seeders;

use App\Models\Billboard;
use Illuminate\Database\Seeder;

class BillboardSeeder extends Seeder
{
    public function run(): void
    {
        $billboards = [
            [
                'title' => 'Gulshan Circle Digital Billboard',
                'description' => 'Prime location facing Gulshan-1 roundabout',
                'latitude' => 23.7808,
                'longitude' => 90.4142,
                'address' => 'Gulshan Circle 1, Dhaka',
                'size' => '20x10 ft',
                'type' => 'digital',
                'daily_rate' => 15000,
                'rating' => 4.5,
                'status' => 'available',
            ],
            [
                'title' => 'Banani 11 Roadside Billboard',
                'description' => 'High visibility on Kemal Ataturk Avenue',
                'latitude' => 23.7936,
                'longitude' => 90.4066,
                'address' => 'Road 11, Banani, Dhaka',
                'size' => '15x8 ft',
                'type' => 'traditional',
                'daily_rate' => 8000,
                'rating' => 4.2,
                'status' => 'available',
            ],
            [
                'title' => 'Dhanmondi 27 LED Screen',
                'description' => 'Large LED display near Dhanmondi Lake',
                'latitude' => 23.7461,
                'longitude' => 90.3742,
                'address' => 'Road 27, Dhanmondi, Dhaka',
                'size' => '25x12 ft',
                'type' => 'digital',
                'daily_rate' => 18000,
                'rating' => 4.7,
                'status' => 'available',
            ],
            [
                'title' => 'Motijheel Commercial Area Billboard',
                'description' => 'Business district, heavy office traffic',
                'latitude' => 23.7328,
                'longitude' => 90.4172,
                'address' => 'Motijheel C/A, Dhaka',
                'size' => '20x10 ft',
                'type' => 'traditional',
                'daily_rate' => 10000,
                'rating' => 4.0,
                'status' => 'available',
            ],
            [
                'title' => 'Uttara Sector 7 Billboard',
                'description' => 'Facing Uttara main road',
                'latitude' => 23.8759,
                'longitude' => 90.3795,
                'address' => 'Sector 7, Uttara, Dhaka',
                'size' => '18x9 ft',
                'type' => 'digital',
                'daily_rate' => 12000,
                'rating' => 4.3,
                'status' => 'booked',
            ],
        ];

        foreach ($billboards as $billboard) {
            Billboard::create($billboard);
        }
    }
}