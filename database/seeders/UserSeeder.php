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

        // The second owner exists so no billboard in the catalogue is left
        // unowned - UnassignedBillboardsOwnerSeeder hands it every board none
        // of the others claim.
        User::query()->updateOrCreate(
            ['email' => 'owner2@test.com'],
            [
                'name'     => 'Dhaka Outdoor Network',
                'password' => 'password',
                'role'     => 'owner',
                'phone'    => '01911000002',
            ]
        );
    }
}