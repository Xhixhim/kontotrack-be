<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Tools\BetaRunnableTool;
use App\Services\Ai\Contracts\AssistantModel;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClaudeAssistantModel implements AssistantModel
{
    /** Enough for a question that needs several lookups; a runaway loop stops here. */
    private const MAX_TURNS = 10;

    public function __construct(private Client $client) {}

    public function answer(string $instructions, array $messages, array $tools): string
    {
        $runnableTools = array_map(fn (AssistantTool $tool): BetaRunnableTool => new BetaRunnableTool(
            definition: ['name' => $tool->name, 'description' => $tool->description, 'inputSchema' => $tool->inputSchema],
            run: function (array $input) use ($tool): string {
                try {
                    return json_encode(($tool->handler)($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                } catch (Throwable $exception) {
                    // The model gets a short reason and can try other arguments; details stay in our log.
                    Log::warning('An assistant lookup failed.', ['tool' => $tool->name, 'error' => $exception->getMessage()]);

                    return json_encode(['error' => 'This lookup failed. Check the arguments (dates are YYYY-MM-DD) or answer without it.']);
                }
            },
        ), $tools);

        try {
            $message = $this->client->beta->messages->toolRunner(
                maxTokens: 16000,
                messages: $messages,
                model: (string) config('kontotrack.ai.model'),
                tools: $runnableTools,
                maxIterations: self::MAX_TURNS,
                extraParams: [
                    'system' => $instructions,
                    'outputConfig' => ['effort' => 'medium'],
                    // If a safety classifier declines the request, Anthropic retries it on a suitable model instead of failing.
                    'fallbacks' => 'default',
                    'betas' => ['server-side-fallback-2026-07-01'],
                ],
                // Each request carries the whole conversation; nothing is kept on Anthropic's side between requests.
                thread: false,
            )->runUntilDone();
        } catch (AuthenticationException $exception) {
            Log::error('Claude rejected the API key.', ['error' => $exception->getMessage()]);

            throw new AiUnavailableException('The assistant is not set up correctly on this server. Tell the administrator the AI key was rejected.', previous: $exception);
        } catch (RateLimitException $exception) {
            throw new AiUnavailableException('The assistant is busy right now. Try again in a minute.', previous: $exception);
        } catch (APIStatusException $exception) {
            Log::error('The assistant request failed.', ['status' => $exception->status, 'error' => $exception->getMessage()]);

            throw new AiUnavailableException($exception->status >= 500 ? 'The assistant is temporarily unavailable. Try again later.' : 'The assistant could not answer this question.', previous: $exception);
        } catch (APIConnectionException $exception) {
            throw new AiUnavailableException('The assistant could not be reached. Check the server\'s internet connection.', previous: $exception);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiUnavailableException('The assistant cannot help with this question.');
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        if (trim($text) === '') {
            throw new AiUnavailableException('The assistant did not finish its answer. Ask a narrower question.');
        }

        return trim($text);
    }
}
