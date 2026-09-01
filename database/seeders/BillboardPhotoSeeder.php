<?php

namespace Database\Seeders;

use App\Models\Billboard;
use Illuminate\Database\Seeder;

/**
 * Links any image committed at frontend/public/billboards/<id>.jpg to its
 * billboard row's `photo` column (as a root-relative URL the SPA serves itself).
 * Idempotent - runs clean every `db:seed`, and does nothing for ids with no file.
 */
class BillboardPhotoSeeder extends Seeder
{
    public function run(): void
    {
        $dir = base_path('frontend/public/billboards');
        $linked = 0;

        foreach (glob("{$dir}/*.jpg") ?: [] as $file) {
            $id = (int) pathinfo($file, PATHINFO_FILENAME);
            if ($id <= 0) {
                continue;
            }

            $linked += Billboard::query()
                ->whereKey($id)
                ->update(['photo' => "/billboards/{$id}.jpg"]);
        }

        $this->command?->info("BillboardPhotoSeeder: linked {$linked} billboard photo(s).");
    }
}
