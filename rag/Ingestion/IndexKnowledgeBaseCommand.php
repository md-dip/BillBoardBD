<?php

namespace Rag\Ingestion;

use Illuminate\Console\Command;
use Rag\Embeddings\EmbeddingClient;
use Rag\Ingestion\DocumentBuilders\BillboardDocumentBuilder;
use Rag\Ingestion\DocumentBuilders\BoardEarningsDocumentBuilder;
use Rag\Ingestion\DocumentBuilders\BookingDocumentBuilder;
use Rag\Ingestion\DocumentBuilders\PayoutDocumentBuilder;
use Rag\Ingestion\DocumentBuilders\PolicyDocumentBuilder;

/**
 * The CLI entry point into the ingestion pipeline - runs IngestionPipeline
 * against every document builder.
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
class IndexKnowledgeBaseCommand extends Command
{
    protected $signature = 'rag:index
        {--force : re-embed every document even if its text has not changed}
        {--only= : limit to one source type (policy, billboard, booking, board_earnings, payout)}';

    protected $description = "Build the BillboardBD Assistant's retrieval knowledge base";

    public function handle(IngestionPipeline $indexer, EmbeddingClient $embeddings): int
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
