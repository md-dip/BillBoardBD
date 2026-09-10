<?php

namespace App\Services\Shared\Assistant;

use RuntimeException;

/**
 * The assistant cannot answer right now - no API key configured, the upstream
 * model is down, or the account is rate limited. Separate from a normal failure
 * so the controller can answer 503 with something a user can act on, rather
 * than a 500 that reads like the whole site broke.
 */
class AssistantUnavailableException extends RuntimeException {}
