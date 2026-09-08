<?php

namespace App\Console\Commands;

use App\Services\Shared\Rag\DocumentBuilders\BillboardDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\BoardEarningsDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\BookingDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\PayoutDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\PolicyDocumentBuilder;
use App\Services\Shared\Rag\Embeddings\EmbeddingClient;
use App\Services\Shared\Rag\Indexer;
use Illuminate\Console\Command;

/**
 * Build the assistant's knowledge base.
 *
 *   php artisan rag:index                     # only what has changed
 *   php artisan rag:index --force             # re-embed everything
 *   php artisan rag:index --only=policy       # one source type
 *
 * Unchanged documents are skipped by content hash, so running this repeatedly
 * costs almost nothing. Use --force after switching embedding model, since
 * vectors from two models cannot be compared and the old ones are ignored by
 * the retriever until they are rewritten.
 */
class RagIndex extends Command
{
    protected $signature = 'rag:index
        {--force : re-embed every document even if its text has not changed}
        {--only= : limit to one source type (policy, billboard, booking, board_earnings, payout)}';

    protected $description = "Build the BillboardBD Assistant's retrieval knowledge base";

    public function handle(Indexer $indexer, EmbeddingClient $embeddings): int
    {
        $builders = [
            app(PolicyDocumentBuilder::class),
            app(BillboardDocumentBuilder::class),
            app(BookingDocumentBuilder::class),
            app(BoardEarningsDocumentBuilder::class),
            app(PayoutDocumentBuilder::class),
        ];

        if ($only = (string) $this->option('only')) {
            $builders = array_values(array_filter($builders, fn ($b) => $b->sourceType() === $only));

            if ($builders === []) {
                $this->error("Unknown source type: {$only}");

                return self::FAILURE;
            }
        }

        $this->line("Embedding model: {$embeddings->model()}");

        $started = microtime(true);
        $report = $indexer->rebuild($builders, (bool) $this->option('force'));
        $elapsed = round(microtime(true) - $started, 2);

        $this->table(
            ['Source', 'Written', 'Unchanged', 'Removed'],
            collect($report)->map(fn (array $row, string $type) => [
                $type, $row['written'], $row['unchanged'], $row['removed'],
            ])->values()->all(),
        );

        $written = collect($report)->sum('written');
        $this->info("Indexed {$written} document(s) in {$elapsed}s.");

        return self::SUCCESS;
    }
}
