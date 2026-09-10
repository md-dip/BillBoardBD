<?php

namespace App\Services\Shared\Assistant;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\ToolUseBlock;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The BillboardBD Assistant - one natural-language entry point over the API.
 *
 * The model answers nothing from its own memory. Every fact in a reply comes
 * back from a tool in ToolRegistry, each of which runs a scoped query as the
 * signed-in user. That is the whole design: the language model chooses WHICH
 * question to ask the database and how to word the answer; the database decides
 * WHAT is true. Prices, dates, availability and money therefore cannot be
 * hallucinated - at worst the assistant says it could not find something.
 */
class AssistantService
{
    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * Answer one question.
     *
     * @param  array<int, array{role: string, content: string}>  $history  Prior turns, oldest first.
     * @return array{reply: string, tools_used: array<int, string>, sources: array<int, string>}
     */
    public function ask(User $user, string $question, array $history = []): array
    {
        $apiKey = (string) config('assistant.api_key');
        if ($apiKey === '') {
            throw new AssistantUnavailableException(
                'The assistant is not configured. Set ANTHROPIC_API_KEY in .env.'
            );
        }

        $client = new Client(apiKey: $apiKey);
        $tools = $this->tools->definitions($user);
        $system = $this->system($user);
        $maxRounds = (int) config('assistant.max_tool_rounds');

        $messages = [...$this->sanitiseHistory($history), ['role' => 'user', 'content' => $question]];
        $toolsUsed = [];
        $sources = [];

        try {
            $response = $this->create($client, $system, $tools, $messages);

            $round = 0;
            while ($response->stopReason === 'tool_use') {
                $round++;

                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = ['role' => 'user', 'content' => $this->runTools($response->content, $user, $toolsUsed, $sources)];

                // Last round spends its tool results and then asks for an
                // answer with no tools offered, so a model stuck in a lookup
                // loop still has to produce something the user can read.
                $response = $round >= $maxRounds
                    ? $this->create($client, $system, [], $messages)
                    : $this->create($client, $system, $tools, $messages);
            }
        } catch (AuthenticationException $e) {
            Log::error('Assistant: rejected API key.', ['message' => $e->getMessage()]);
            throw new AssistantUnavailableException('The assistant is not configured correctly.', 0, $e);
        } catch (RateLimitException $e) {
            throw new AssistantUnavailableException('The assistant is busy right now. Try again in a moment.', 0, $e);
        } catch (APIConnectionException $e) {
            throw new AssistantUnavailableException('Could not reach the assistant. Check your connection.', 0, $e);
        } catch (AnthropicException $e) {
            Log::error('Assistant: upstream failure.', ['message' => $e->getMessage()]);
            throw new AssistantUnavailableException('The assistant could not answer that right now.', 0, $e);
        }

        $reply = $this->textOf($response->content);

        Log::info('Assistant answered.', [
            'user_id' => $user->id,
            'role' => $user->role,
            'tools_used' => $toolsUsed,
            'retrieved' => count($sources),
        ]);

        return [
            'reply' => $reply !== '' ? $reply : 'Sorry - I could not put an answer together for that one.',
            'tools_used' => $toolsUsed,
            // What retrieval actually pulled in, shown under the answer. Worth
            // surfacing: it is the difference between an answer a user can
            // check and one they have to take on faith.
            'sources' => array_values(array_unique($sources)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function create(Client $client, array $system, array $tools, array $messages): mixed
    {
        return $client->messages->create(
            model: (string) config('assistant.model'),
            maxTokens: (int) config('assistant.max_tokens'),
            system: $system,
            tools: $tools === [] ? null : $tools,
            messages: $messages,
        );
    }

    /**
     * Execute every tool call in one assistant turn and return the matching
     * tool_result blocks. All of them go back in a single user message - split
     * across several, the model quietly stops making parallel calls.
     *
     * @param  array<int, mixed>  $content
     * @param  array<int, string>  $toolsUsed
     * @param  array<int, string>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function runTools(array $content, User $user, array &$toolsUsed, array &$sources): array
    {
        $results = [];

        foreach ($content as $block) {
            if (! $block instanceof ToolUseBlock) {
                continue;
            }

            $toolsUsed[] = $block->name;

            try {
                $result = $this->tools->dispatch($block->name, (array) $block->input, $user);
            } catch (\Throwable $e) {
                // A broken tool must come back as a tool_result, not vanish -
                // dropping it leaves the model waiting on an answer forever.
                Log::warning('Assistant: tool failed.', [
                    'tool' => $block->name,
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);

                $results[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $block->id,
                    'content' => 'That lookup failed. Tell the user you could not retrieve it.',
                    'isError' => true,
                ];

                continue;
            }

            if ($block->name === 'search_knowledge_base') {
                foreach ($result['passages'] ?? [] as $passage) {
                    $sources[] = (string) ($passage['title'] ?? '');
                }
            }

            $results[] = [
                'type' => 'tool_result',
                'toolUseID' => $block->id,
                'content' => $this->encode($result),
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function encode(array $result): string
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return '{"error":"Could not encode the result."}';
        }

        // A runaway result would push the real conversation out of context.
        // The row caps in the tool classes should prevent this; the truncation
        // is here so an unexpected shape degrades instead of breaking.
        return strlen($json) > 60000
            ? substr($json, 0, 60000).'... (truncated)'
            : $json;
    }

    /**
     * @param  array<int, mixed>  $content
     */
    private function textOf(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if (($block->type ?? null) === 'text') {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * The conversation arrives from the browser, so none of it is trusted. Only
     * plain user/assistant text survives, the newest few turns of it, and the
     * result must start on a user turn.
     *
     * @param  array<int, mixed>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitiseHistory(array $history): array
    {
        $clean = [];

        foreach ($history as $turn) {
            $role = is_array($turn) ? ($turn['role'] ?? null) : null;
            $content = is_array($turn) ? ($turn['content'] ?? null) : null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content)) {
                continue;
            }

            $content = trim($content);
            if ($content === '') {
                continue;
            }

            $clean[] = ['role' => $role, 'content' => mb_substr($content, 0, 4000)];
        }

        $clean = array_slice($clean, -(int) config('assistant.max_history_messages'));

        while ($clean !== [] && $clean[0]['role'] !== 'user') {
            array_shift($clean);
        }

        return array_values($clean);
    }

    /**
     * Two blocks on purpose. The rules are identical for every user of a given
     * role, so they sit in the cached prefix; the name and today's date change
     * per request and go after it, where they cannot cost a cache hit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function system(User $user): array
    {
        return [
            [
                'type' => 'text',
                'text' => $this->rules($user),
                'cacheControl' => ['type' => 'ephemeral'],
            ],
            [
                'type' => 'text',
                'text' => sprintf(
                    'You are speaking with %s (account type: %s). Today is %s.',
                    $user->name,
                    $user->role,
                    now()->toFormattedDateString(),
                ),
            ],
        ];
    }

    private function rules(User $user): string
    {
        $shared = <<<'TXT'
        You are the BillboardBD Assistant. BillboardBD is a platform for renting outdoor
        billboard advertising space in Dhaka, Bangladesh. All money is Bangladeshi Taka (BDT).

        HOW YOU ANSWER

        Every fact you state must come from a tool call in this conversation. You have no
        reliable knowledge of this platform's data. Never state a price, a date, an
        availability, a status, or an amount of money that a tool did not just return to
        you. If a tool returns nothing, say plainly that you could not find it - never fill
        the gap with a guess or an example.

        You have two kinds of lookup. search_knowledge_base retrieves written explanations
        and summaries - reach for it on "how does this work", "why did this happen", how to
        contact the BillboardBD team, who decides what, which page to go to for a task, and
        for background about the user's own records. The other tools return live rows -
        exact balances, current availability, today's figures. Explain from the knowledge
        base; quantify from a specific tool; use both when a question needs each. If a
        retrieved passage and a live tool disagree, the live tool is right - the passage
        was written earlier.

        NEVER say you do not have something without searching for it first. "I don't have
        that information", "that's outside what I can help with", "check the website" - none
        of those may be your answer unless you have already called search_knowledge_base and
        it came back with nothing useful. The knowledge base holds contact details, platform
        policy and how-to guidance that no other tool returns, so a refusal that skipped the
        lookup is simply a wrong answer. Search, then answer or decline.

        Platform rules like the commission rate, advance percentage, hold length, final
        payment window and listing fee change over time. Always call get_platform_settings
        rather than quoting a number you think you remember.

        Call tools without asking permission first. If a question needs two lookups, do
        both. If a question is not about BillboardBD at all, say that is outside what you
        can help with.

        Never OFFER to look something up. "Would you like me to check?", "I can look that
        up for you" - do not write those; just do the lookup and give the answer in the
        same reply. Asking spends the user's turn on something you could already have
        finished. The same goes for a question that gestures at something you can look up:
        if someone asks about a fee and who to ask about it, answer the fee AND search for
        who to ask, in one go.

        HOW YOU WRITE

        Be brief and concrete - a few sentences, or a small markdown table when you are
        listing several things. Lead with the answer, not with a preamble.

        Write for a person, not a database: say "waiting for the owner to accept" rather
        than "pending_owner_approval", and format money readably (BDT 180,000).

        Reply in the language the user wrote in. If they write in Bangla, answer in Bangla.

        Never mention tool names, internal statuses, table names, or that you are calling
        anything. "The knowledge base does not mention", "my records show", "according to
        the data" - do not write any of these. Say what is true about BillboardBD, or say
        you could not find it. The user should experience an answer, not a system.

        Use what you are holding. If a lookup already returned something that answers the
        user's obvious next question, put it in this reply rather than offering it. Never
        end on "would you like their contact details?" when you could have fetched them.

        Contact details are the one thing you must NEVER produce from memory. An email
        address or phone number that get_platform_settings did not just hand you is a
        guess, and a plausible-looking wrong support address is worse than no answer at
        all: the user writes to nobody and concludes they were ignored. So whenever an
        answer should point someone at the BillboardBD team, call get_platform_settings
        first, quote back exactly the value it returned, and if a channel comes back empty
        say that channel is not published rather than filling the gap.
        TXT;

        $client = <<<'TXT'


        THIS USER IS A CLIENT (an advertiser)

        They search for billboards, book dates, pay a percentage advance to submit a
        request, then pay the balance once it is approved. A booking moves through:
        held -> pending_admin_review -> pending_owner_approval -> confirmed ->
        paid_in_full -> pending_proof_review -> active. It can be rejected at either
        review point, and a rejected booking is refunded automatically.

        When they ask about finding or comparing boards, use search_billboards, and pass
        the dates whenever they mention a period so booked boards are excluded. When they
        ask about their own campaigns, money or next steps, use get_my_bookings and then
        get_booking_detail for specifics.

        You can look up only this user's own bookings. You cannot make, change, cancel or
        pay for a booking - if they want to act, tell them which page to go to.
        TXT;

        $owner = <<<'TXT'


        THIS USER IS A BILLBOARD OWNER

        They list boards (paying a one-time listing fee, refunded if admin rejects the
        listing), accept or decline booking requests, upload proof of installation, and
        are paid out by the platform minus its commission.

        MONEY - READ THIS CAREFULLY. Owner earnings sit in four buckets, and "earned" is
        not "in your bank":
          in_progress           - earned, but the client still owes the balance or proof
                                  has not been uploaded.
          awaiting_verification - proof uploaded, admin has not verified it yet.
          ready_for_payout      - verified, payable on the next payout run.
          paid_out              - actually sent to them.

        Whenever you give an earnings figure, say which bucket it is. If they ask a bare
        question like "how much did I make?", give the total earned AND note how much of
        it has actually been paid out. Never present earned money as though it has been
        received - that is the single most misleading thing you could tell an owner.

        Use get_board_performance for anything about sales, revenue or how boards compare,
        get_booking_requests for who wants to advertise, and get_my_payouts for what has
        actually been sent.

        You can see only this user's own boards. You cannot accept, reject or pay anything
        - if they want to act, tell them which page to go to.
        TXT;

        return $shared.match ($user->role) {
            'client' => $client,
            'owner' => $owner,
            default => '',
        };
    }
}
