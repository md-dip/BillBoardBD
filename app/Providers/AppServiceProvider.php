<?php

namespace App\Providers;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\ProofOfPosting;
use App\Observers\RagReindexObserver;
use App\Services\Client\AssistantTools as ClientAssistantTools;
use App\Services\Owner\AssistantTools as OwnerAssistantTools;
use App\Services\Owner\OwnerLedgerService;
use App\Services\Shared\Rag\DocumentBuilders\BoardEarningsDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\PolicyDocumentBuilder;
use App\Services\Shared\Rag\Embeddings\EmbeddingClient;
use App\Services\Shared\Rag\Embeddings\HashingEmbeddingClient;
use App\Services\Shared\Rag\Embeddings\VoyageEmbeddingClient;
use App\Services\Shared\Rag\Retriever;
use App\Services\Shared\Rag\Scoring\LexicalScorer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The assistant's tool classes take the row cap as a constructor value
        // so it stays one configured number rather than a literal repeated
        // through every query. Nothing else about them needs wiring.
        $this->app->bind(ClientAssistantTools::class, fn () => new ClientAssistantTools(
            (int) config('assistant.max_rows'),
        ));

        $this->app->bind(OwnerAssistantTools::class, fn ($app) => new OwnerAssistantTools(
            $app->make(OwnerLedgerService::class),
            (int) config('assistant.max_rows'),
        ));

        // One embedding client for the whole request: ingestion and retrieval
        // MUST use the same one, or a question would be embedded into a
        // different space from the documents and every score would be noise.
        $this->app->singleton(EmbeddingClient::class, function () {
            $config = config('rag.embeddings');

            return match ($config['driver']) {
                // Deterministic and offline - what the test suite runs on, so
                // the pipeline can be exercised without calling out anywhere.
                'hashing' => new HashingEmbeddingClient((int) $config['hashing']['dimensions']),
                default => new VoyageEmbeddingClient(
                    apiKey: (string) ($config['voyage']['key'] ?? ''),
                    model: (string) $config['voyage']['model'],
                    endpoint: (string) $config['voyage']['endpoint'],
                    timeout: (int) $config['voyage']['timeout'],
                    batchSize: (int) $config['voyage']['batch_size'],
                ),
            };
        });

        $this->app->bind(PolicyDocumentBuilder::class, fn () => new PolicyDocumentBuilder(
            resource_path((string) config('rag.ingestion.policy_path')),
            (int) config('rag.ingestion.chunk_chars'),
        ));

        $this->app->bind(BoardEarningsDocumentBuilder::class, fn ($app) => new BoardEarningsDocumentBuilder(
            $app->make(OwnerLedgerService::class),
            (int) config('rag.ingestion.board_month_history'),
        ));

        $this->app->bind(Retriever::class, fn ($app) => new Retriever(
            $app->make(EmbeddingClient::class),
            $app->make(LexicalScorer::class),
            (int) config('rag.retrieval.top_k'),
            (float) config('rag.retrieval.min_score'),
            (string) config('rag.retrieval.mode'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep the assistant's knowledge base current. Every one of these
        // models can change what a document says without the document itself
        // being touched, so each write queues a narrow re-index.
        Booking::observe(RagReindexObserver::class);
        Billboard::observe(RagReindexObserver::class);
        Payment::observe(RagReindexObserver::class);
        ProofOfPosting::observe(RagReindexObserver::class);
        Payout::observe(RagReindexObserver::class);

        // Point the emailed password-reset link at the React app instead of a
        // Laravel page. Without this the default notification calls
        // route('password.reset'), which does not exist in this API-only app,
        // so sending the mail would blow up with a RouteNotFoundException.
        //
        // The frontend reads both query params straight back out of the URL and
        // posts them to /api/reset-password - see shared/pages/ResetPassword.jsx.
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $base = rtrim((string) config('services.sslcommerz.frontend_url'), '/');

            return $base.'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }
}
