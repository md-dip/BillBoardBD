<?php

namespace App\Console\Commands;

use App\Models\Billboard;
use Illuminate\Console\Command;

/**
 * Turn the hand-generated billboard images dropped into a source folder
 * (named "#<id> - <title>.png|.jfif|.jpg") into clean web-ready JPEGs at
 * frontend/public/billboards/<id>.jpg, and point each billboard row's `photo`
 * column at "/billboards/<id>.jpg".
 *
 * frontend/public/ is committed and served by Vite at the site root, so the
 * photos travel with git and need no storage:link / APP_URL on another machine.
 *
 *   php artisan billboards:import-photos
 *   php artisan billboards:import-photos --force        # re-encode existing ones
 *   php artisan billboards:import-photos --source="storage/app/public/Board picture"
 */
class ImportBillboardPhotos extends Command
{
    protected $signature = 'billboards:import-photos
        {--source=storage/app/public/Board picture : folder holding the "#id - title.ext" images}
        {--force : re-encode even if the target jpg already exists}
        {--quality=82 : JPEG quality}';

    protected $description = 'Normalise hand-made billboard images into frontend/public/billboards and link them';

    public function handle(): int
    {
        $srcDir = base_path((string) $this->option('source'));
        $dstDir = base_path('frontend/public/billboards');

        if (! is_dir($srcDir)) {
            $this->error("Source folder not found: {$srcDir}");

            return self::FAILURE;
        }

        if (! is_dir($dstDir) && ! mkdir($dstDir, 0775, true)) {
            $this->error("Could not create {$dstDir}");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $quality = (int) $this->option('quality');
        $imported = $skipped = $linked = $unmatched = 0;

        foreach (glob("{$srcDir}/*") ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            if (! preg_match('/#?(\d+)\s*-/', basename($file), $m)) {
                $this->line("  <fg=yellow>skip</> (no id in name): ".basename($file));
                $unmatched++;

                continue;
            }

            $id = (int) $m[1];
            $target = "{$dstDir}/{$id}.jpg";

            if (is_file($target) && ! $force) {
                $skipped++;
            } else {
                $img = @imagecreatefromstring((string) file_get_contents($file));
                if (! $img) {
                    $this->line("  <fg=red>fail</> (unreadable image): ".basename($file));

                    continue;
                }
                $w = imagesx($img);
                $h = imagesy($img);
                $canvas = imagecreatetruecolor($w, $h);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // flatten alpha
                imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
                imagejpeg($canvas, $target, $quality);
                imagedestroy($img);
                imagedestroy($canvas);
                $imported++;
            }

            $linked += Billboard::query()->whereKey($id)->update(['photo' => "/billboards/{$id}.jpg"]);
        }

        $this->newLine();
        $this->info("imported {$imported}  |  skipped (already there) {$skipped}  |  db rows linked {$linked}  |  name had no id {$unmatched}");
        $this->line("images -> ".$dstDir);
        $this->line("commit them:  git add frontend/public/billboards/");

        return self::SUCCESS;
    }
}
