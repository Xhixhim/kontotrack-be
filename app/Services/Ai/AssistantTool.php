<?php

namespace App\Services\Ai;

use Closure;

/**
 * One read-only lookup the assistant may run on the business's data.
 */
final class AssistantTool
{
    /**
     * @param  array<string, mixed>  $inputSchema  JSON schema of the arguments
     * @param  Closure(array<string, mixed>): array<string, mixed>  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly Closure $handler,
    ) {}
}
