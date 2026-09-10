<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Shared\Rag\Retriever;
use Illuminate\Console\Command;

/**
 * Measure the retrieval half of the assistant against a fixed set of questions.
 *
 *   php artisan rag:benchmark
 *   php artisan rag:benchmark --k=6 --set=storage/app/rag-benchmark.json
 *
 * Retrieval quality is the one part of a RAG system that can be measured
 * without a language model in the loop, and it is where most of the quality
 * lives - a correct answer the retriever never surfaced is not recoverable
 * downstream. This runs the same questions through each mode so the value of
 * fusion is a number rather than an opinion.
 *
 * Recall@K is the headline, not top-1: every one of the K documents is handed
 * to the model, so a wanted document at rank 4 has done its job. Rank is
 * reported alongside because a lower one leaves more room in the context.
 */
class RagBenchmark extends Command
{
    protected $signature = 'rag:benchmark
        {--k=6 : how many documents count as retrieved}
        {--set= : path to a JSON question set, defaults to the built-in one}
        {--mode= : test one mode only (hybrid, vector, lexical)}';

    protected $description = "Measure the assistant's retrieval quality across modes";

    /**
     * Each case: the account asking, the question, and the kind of document
     * that has to reach the model for the question to be answerable.
     */
    private const DEFAULT_SET = [
        ['owner', 'how did my boards earn this year', 'board_earnings'],
        ['owner', 'my earnings in July', 'board_earnings'],
        ['owner', 'why has my payout not arrived yet', 'policy'],
        ['owner', 'what is the proof of posting rule', 'policy'],
        ['owner', 'list of payments sent to me', 'payout'],
        ['owner', 'which advertisers are waiting on me', 'booking'],
        ['client', 'what happens to my advance if rejected', 'policy'],
        ['client', 'when is my final payment due', 'policy'],
        ['client', 'how long do my dates stay held', 'policy'],
    ];

    public function handle(): int
    {
        $k = (int) $this->option('k');
        $cases = $this->cases();

        if ($cases === []) {
            $this->error('No usable cases - the question set needs an account of each role that exists.');

            return self::FAILURE;
        }

        $modes = $this->option('mode') ? [(string) $this->option('mode')] : ['vector', 'lexical', 'hybrid'];
        $rows = [];

        foreach ($modes as $mode) {
            config(['rag.retrieval.mode' => $mode]);
            app()->forgetInstance(Retriever::class);
            $retriever = app(Retriever::class);

            $found = 0;
            $ranks = [];

            $this->newLine();
            $this->line("<comment>{$mode}</comment>");

            foreach ($cases as [$user, $question, $want]) {
                $types = array_column($retriever->retrieve($user, $question, $k), 'source_type');
                $position = array_search($want, $types, true);

                if ($position !== false) {
                    $found++;
                    $ranks[] = $position + 1;
                }

                $this->line(sprintf(
                    '  %s  %s',
                    $position === false ? '<fg=red>miss  </>' : '<info>#'.($position + 1).'    </info>',
                    $question,
                ));
            }

            $rows[] = [
                $mode,
                sprintf('%d/%d', $found, count($cases)),
                sprintf('%.0f%%', 100 * $found / count($cases)),
                $ranks ? sprintf('%.2f', array_sum($ranks) / count($ranks)) : '-',
            ];
        }

        $this->newLine();
        $this->table(['Mode', "Recall@{$k}", '', 'Mean rank'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0: User, 1: string, 2: string}>
     */
    private function cases(): array
    {
        $path = (string) $this->option('set');
        $set = self::DEFAULT_SET;

        if ($path !== '') {
            $decoded = json_decode((string) file_get_contents(base_path($path)), true);
            if (! is_array($decoded)) {
                $this->error("Could not read a question set from {$path}");

                return [];
            }
            $set = $decoded;
        }

        // One account per role, reused across cases - the point is retrieval,
        // not which particular owner is asking.
        $users = [];
        $cases = [];

        foreach ($set as [$role, $question, $want]) {
            $users[$role] ??= User::query()->where('role', $role)->first();

            if ($users[$role]) {
                $cases[] = [$users[$role], $question, $want];
            }
        }

        return $cases;
    }
}
