<?php

namespace App\Services\Shared\Assistant;

use App\Models\Setting;
use App\Models\User;
use App\Services\Client\AssistantTools as ClientTools;
use App\Services\Owner\AssistantTools as OwnerTools;
use Rag\Retrieval\RetrievalPipeline;

/**
 * Which tools the BillboardBD Assistant may call, and who may call them.
 *
 * The tool list is built from the authenticated user's role, so a client is
 * never even shown that owner earnings tools exist. That matters more than it
 * looks: a tool the model cannot see is a tool no prompt can talk it into
 * calling. The per-actor classes then re-scope every query to the same user, so
 * authorisation holds at both layers.
 */
class ToolRegistry
{
    public function __construct(
        private readonly ClientTools $clientTools,
        private readonly OwnerTools $ownerTools,
        private readonly RetrievalPipeline $retriever,
    ) {}

    /** Roles the assistant has been built for. Admin tooling is not written yet. */
    public function supports(User $user): bool
    {
        return in_array($user->role, ['client', 'owner'], true);
    }

    /**
     * Tool definitions for this user's role, in a stable order - the tool block
     * is part of the cached prompt prefix, and reordering it would throw the
     * cache away on every request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(User $user): array
    {
        $tools = [$this->knowledgeBaseDefinition(), $this->platformSettingsDefinition()];

        return match ($user->role) {
            'client' => [...$tools, ...$this->clientTools->definitions()],
            'owner' => [...$tools, ...$this->ownerTools->definitions()],
            default => $tools,
        };
    }

    /**
     * Run one tool call. Anything the role is not entitled to is refused here
     * even if the model somehow names it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function dispatch(string $name, array $input, User $user): array
    {
        if ($name === 'search_knowledge_base') {
            return $this->searchKnowledgeBase($input, $user);
        }

        if ($name === 'get_platform_settings') {
            return $this->platformSettings();
        }

        $allowed = array_column($this->definitions($user), 'name');
        if (! in_array($name, $allowed, true)) {
            return ['error' => "You are not permitted to use the tool '{$name}'."];
        }

        return match ($user->role) {
            'client' => $this->clientTools->handle($name, $input, $user),
            'owner' => $this->ownerTools->handle($name, $input, $user),
            default => ['error' => 'No tools are available for this account type.'],
        };
    }

    /**
     * The retrieval half of the assistant. Semantic search over the knowledge
     * base - policy prose plus every row this user may see, written up as
     * sentences at ingestion time.
     *
     * @return array<string, mixed>
     */
    private function knowledgeBaseDefinition(): array
    {
        return [
            'name' => 'search_knowledge_base',
            'description' => "Search BillboardBD's knowledge base for anything written in plain language: how the platform works, why something happened, what a stage or a rule means, HOW TO CONTACT THE BILLBOARDBD TEAM (email and phone), who has the authority to decide what, which page of the site to go to for a given task, and write-ups of this user's own billboards, bookings, earnings and payouts. Use it first for explanatory and practical questions (\"how does the hold work\", \"why was this rejected\", \"how do I reach support\", \"where do I upload proof\"). Always search here before telling the user you do not know something. It returns passages, not live figures - when an exact current number matters, use the specific tool for it as well.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => "What to look for, in the user's own words. A full question works better than keywords."],
                ],
                'required' => ['query'],
            ],
        ];
    }

    /**
     * Retrieval is scoped by RetrievalPipeline using the authenticated user, so a
     * passage belonging to another account cannot come back however the query
     * is phrased. The model supplies only the words to search for.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function searchKnowledgeBase(array $input, User $user): array
    {
        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            return ['error' => 'Give a query to search for.'];
        }

        $hits = $this->retriever->retrieve($user, $query);

        return [
            'passages_found' => count($hits),
            'passages' => array_map(fn (array $hit) => [
                'title' => $hit['title'],
                'text' => $hit['content'],
                'kind' => $hit['source_type'],
                'relevance' => round($hit['score'], 3),
            ], $hits),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformSettingsDefinition(): array
    {
        return [
            'name' => 'get_platform_settings',
            'description' => "The current platform rules and the official support contact details: commission rate, advance percentage, how long a slot hold lasts, the final payment window, the board listing fee, and the BillboardBD team's email and phone. Call this whenever an answer needs one of those, and ALWAYS before giving anyone a contact address. Never state any of them from memory - an admin can change them at any time, and an invented support address sends the user to nobody.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass,
                'required' => [],
            ],
        ];
    }

    /**
     * The live values, never a copy baked into a prompt or a help page. This is
     * the tool that keeps the assistant from confidently quoting a commission
     * rate the admin changed last month.
     *
     * @return array<string, mixed>
     */
    private function platformSettings(): array
    {
        return [
            'commission_rate_percent' => (float) Setting::get('commission_rate', 10),
            'commission_rate_means' => "The platform's cut of a booking. It is frozen onto each booking when the advance is paid, so older bookings keep the rate they were sold at.",
            'advance_percentage' => (float) Setting::get('advance_percentage', 30),
            'advance_percentage_means' => 'Share of the booking total a client pays up front to submit a request.',
            'hold_minutes' => (int) Setting::get('hold_minutes', 15),
            'hold_minutes_means' => 'How long chosen dates stay locked while the client completes the request.',
            'final_payment_days' => (int) Setting::get('final_payment_days', 7),
            'final_payment_days_means' => 'Days a client has to pay the balance after the owner accepts.',
            'listing_fee_bdt' => (float) Setting::get('listing_fee', 5000),
            'listing_fee_means' => 'One-time fee an owner pays to submit a board for listing. Refunded automatically if the admin rejects the listing.',

            // Contact details are served as data, not left to the model. A
            // language model asked to hand over an email address will produce a
            // plausible one whether or not it has ever seen the real one, and a
            // wrong support address is worse than none - the user writes to
            // nobody and assumes they have been ignored. Empty means the
            // platform has not published that channel.
            'support_email' => (string) Setting::get('support_email', 'hello@billboardbd.com'),
            'support_phone' => (string) Setting::get('support_phone', ''),
            'support_contact_means' => 'The only contact details you may give out. If a value here is empty, that channel is not published - say so; never substitute one of your own.',
        ];
    }
}
