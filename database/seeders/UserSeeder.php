<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name'     => 'Site Admin',
                'password' => 'password',
                'role'     => 'admin',
                'phone'    => '01711000000',
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'client@test.com'],
            [
                'name'     => 'Rahim Advertising Ltd.',
                'password' => 'password',
                'role'     => 'client',
                'phone'    => '01811000000',
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'owner@test.com'],
            [
                'name'     => 'Karim Outdoor Media',
                'password' => 'password',
                'role'     => 'owner',
                'phone'    => '01911000000',
            ]
        );
    }
}