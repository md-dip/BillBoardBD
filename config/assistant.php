<?php

/**
 * BillboardBD Assistant - the natural-language layer over the API.
 *
 * The assistant never sees the database directly. It answers by calling the
 * tools in App\Services\Shared\Assistant\ToolRegistry, every one of which
 * scopes its query to the authenticated user server-side, so a question can
 * never reach another actor's rows however it is phrased.
 */
return [
    // Anthropic API key. Without it the assistant endpoint answers 503 rather
    // than pretending the feature exists.
    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ASSISTANT_MODEL', 'claude-opus-5'),

    // Answers are short - a few sentences plus a small table at most.
    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 4096),

    // Ceiling on request -> tool -> request cycles for a single question. Stops
    // a confused model from looping the database forever on one message.
    'max_tool_rounds' => (int) env('ASSISTANT_MAX_TOOL_ROUNDS', 6),

    // How many prior messages of the conversation the client may replay to us.
    // Older turns are dropped rather than rejected.
    'max_history_messages' => (int) env('ASSISTANT_MAX_HISTORY_MESSAGES', 20),

    // Rows any single tool may hand back to the model. Keeps a broad question
    // ("show my bookings") from filling the context window.
    'max_rows' => (int) env('ASSISTANT_MAX_ROWS', 25),
];
