<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\AskAssistantRequest;
use App\Services\Shared\Assistant\AssistantService;
use App\Services\Shared\Assistant\AssistantUnavailableException;
use App\Services\Shared\Assistant\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The BillboardBD Assistant endpoint - one natural-language question in, one
 * answer out. Stateless: the browser replays the conversation each time, so
 * nothing about a chat is persisted server-side.
 */
class AssistantController extends Controller
{
    /**
     * Whether the panel should render at all, plus a few starter questions.
     * Asked on page load so an unconfigured install shows nothing rather than a
     * chat box that fails on first use.
     */
    public function status(Request $request, ToolRegistry $registry): JsonResponse
    {
        $user = $request->user();
        $enabled = (string) config('assistant.api_key') !== '' && $registry->supports($user);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $enabled,
                'suggestions' => $enabled ? $this->suggestions($user->role) : [],
            ],
            'message' => null,
        ]);
    }

    public function ask(AskAssistantRequest $request, AssistantService $assistant, ToolRegistry $registry): JsonResponse
    {
        $user = $request->user();

        if (! $registry->supports($user)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'The assistant is available to clients and billboard owners.',
            ], 403);
        }

        try {
            $answer = $assistant->ask(
                $user,
                $request->string('message')->toString(),
                $request->input('history', []),
            );
        } catch (AssistantUnavailableException $e) {
            // Deliberately 503, not 500: the site is fine, this one feature is
            // not, and the message is written to be shown to the user as-is.
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => $answer,
            'message' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function suggestions(string $role): array
    {
        return match ($role) {
            'client' => [
                'Find an LED board in Banani under 2 lakh a month',
                'What is happening with my bookings right now?',
                'How much do I still owe on my current campaign?',
            ],
            'owner' => [
                'Which of my boards earned the most this year?',
                'How much have I actually been paid out so far?',
                'Are any booking requests waiting on me?',
            ],
            default => [],
        };
    }
}
