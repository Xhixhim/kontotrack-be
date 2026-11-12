<?php

namespace Tests\Unit\Services\Ai;

use Anthropic\Client;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\AssistantTool;
use App\Services\Ai\ClaudeAssistantModel;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the real SDK tool loop against canned HTTP responses.
 */
class ClaudeAssistantModelTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    public function test_runs_the_lookup_the_model_asks_for_and_returns_its_final_text(): void
    {
        $received = null;
        $tool = new AssistantTool('unpaid_invoices', 'Unpaid invoices.', ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']], 'required' => ['kind'], 'additionalProperties' => false], function (array $input) use (&$received): array {
            $received = $input;

            return ['parties' => [['name' => 'Drini SH.P.K.', 'open_total' => '118.00']]];
        });
        $model = $this->modelAnswering([
            $this->message([['type' => 'text', 'text' => 'Po e shikoj.'], ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'unpaid_invoices', 'input' => ['kind' => 'sale']]], 'tool_use'),
            $this->message([['type' => 'text', 'text' => 'Drini SH.P.K. ka borxh 118,00 EUR.']], 'end_turn'),
        ]);

        $answer = $model->answer('Be exact.', [['role' => 'user', 'content' => 'Kush më ka borxh?']], [$tool]);

        $this->assertSame('Drini SH.P.K. ka borxh 118,00 EUR.', $answer);
        $this->assertSame(['kind' => 'sale'], $received);
        $this->assertCount(2, $this->sent);
        $first = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('claude-opus-5-5', $first['model']);
        $this->assertSame('Be exact.', $first['system']);
        $this->assertSame('medium', $first['output_config']['effort']);
        $this->assertSame('default', $first['fallbacks']);
        $this->assertSame('unpaid_invoices', $first['tools'][0]['name']);
        $this->assertSame('object', $first['tools'][0]['input_schema']['type']);
        $this->assertArrayNotHasKey('thread', $first);
        $this->assertArrayNotHasKey('thinking', $first);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $this->sent[0]['request']->getHeaderLine('anthropic-beta'));
        $second = json_decode((string) $this->sent[1]['request']->getBody(), true);
        $this->assertSame('assistant', $second['messages'][1]['role']);
        $result = $second['messages'][2]['content'][0];
        $this->assertSame(['tool_result', 'toolu_1'], [$result['type'], $result['tool_use_id']]);
        $this->assertStringContainsString('Drini SH.P.K.', is_string($result['content']) ? $result['content'] : json_encode($result['content'], JSON_UNESCAPED_UNICODE));
    }

    public function test_a_failing_lookup_is_reported_to_the_model_not_thrown(): void
    {
        $tool = new AssistantTool('profit_and_loss', 'Profit and loss.', ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false], fn (): array => throw new RuntimeException('SQLSTATE secret detail'));
        $model = $this->modelAnswering([
            $this->message([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'profit_and_loss', 'input' => []]], 'tool_use'),
            $this->message([['type' => 'text', 'text' => 'Nuk munda ta lexoj raportin.']], 'end_turn'),
        ]);

        $this->assertSame('Nuk munda ta lexoj raportin.', $model->answer('x', [['role' => 'user', 'content' => 'Fitimi?']], [$tool]));

        $toolResult = json_encode(json_decode((string) $this->sent[1]['request']->getBody(), true)['messages'][2]['content'][0]);
        $this->assertStringContainsString('This lookup failed', $toolResult);
        $this->assertStringNotContainsString('SQLSTATE', $toolResult);
    }

    public function test_a_refusal_and_an_empty_answer_are_not_shown_as_answers(): void
    {
        foreach ([[$this->message([], 'refusal'), 'cannot help with this question'], [$this->message([], 'max_tokens'), 'did not finish its answer']] as [$response, $expected]) {
            try {
                $this->modelAnswering([$response])->answer('x', [['role' => 'user', 'content' => 'Pyetje']], []);
                $this->fail('An unusable response must not be returned as an answer.');
            } catch (AiUnavailableException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage());
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function message(array $content, string $stopReason): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => $content,
            'stop_reason' => $stopReason, 'stop_sequence' => null, 'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
            ...($stopReason === 'refusal' ? ['stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]] : []),
        ];
    }

    /** @param list<array<string, mixed>> $responses */
    private function modelAnswering(array $responses): ClaudeAssistantModel
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler(array_map(fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body)), $responses)));
        $stack->push(Middleware::history($this->sent));

        return new ClaudeAssistantModel(new Client(apiKey: 'test-key', requestOptions: ['transporter' => new HttpClient(['handler' => $stack, 'http_errors' => false]), 'maxRetries' => 0]));
    }
}
