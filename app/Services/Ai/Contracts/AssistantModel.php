<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\AssistantTool;

interface AssistantModel
{
    /**
     * Answer the last message of a conversation, calling the given tools as often as needed.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  list<AssistantTool>  $tools
     *
     * @throws AiUnavailableException
     */
    public function answer(string $instructions, array $messages, array $tools): string;
}
