<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI service could not give a usable answer (not configured, unreachable, overloaded, or it declined).
 * The message is written for the person using the app.
 */
class AiUnavailableException extends RuntimeException {}
