<?php

namespace Tests\Unit\Services\Ai;

use Anthropic\Client;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\ClaudeInvoiceReader;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Runs the real SDK against canned HTTP responses, so the request we build and the way we read the answer are both checked.
 */
class ClaudeInvoiceReaderTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    public function test_sends_the_pdf_with_a_schema_and_returns_the_fields(): void
    {
        $fields = ['supplier_name' => 'Furnitori SH.P.K.', 'supplier_nui' => '811234567', 'document_number' => 'F-118', 'document_date' => '2026-06-15', 'currency' => 'EUR', 'net_amount' => 100, 'tax_amount' => 18, 'gross_amount' => 118, 'lines' => []];
        $reader = $this->readerAnswering(new Response(200, ['Content-Type' => 'application/json'], json_encode($this->message([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => json_encode($fields)]]))));

        $this->assertSame($fields, $reader->read('%PDF-1.4 bill', 'application/pdf'));

        $request = $this->sent[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 bill')], $body['messages'][0]['content'][0]['source']);
        $this->assertSame('document', $body['messages'][0]['content'][0]['type']);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    public function test_sends_a_photo_as_an_image_block(): void
    {
        $reader = $this->readerAnswering(new Response(200, ['Content-Type' => 'application/json'], json_encode($this->message([['type' => 'text', 'text' => '{"lines":[]}']]))));

        $reader->read('jpeg-bytes', 'image/jpeg');

        $block = json_decode((string) $this->sent[0]['request']->getBody(), true)['messages'][0]['content'][0];
        $this->assertSame('image', $block['type']);
        $this->assertSame('image/jpeg', $block['source']['media_type']);
    }

    public function test_a_refusal_is_reported_as_unreadable(): void
    {
        $reader = $this->readerAnswering(new Response(200, ['Content-Type' => 'application/json'], json_encode([...$this->message([]), 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]])));

        $this->expectException(AiUnavailableException::class);
        $this->expectExceptionMessage('could not be read automatically');
        $reader->read('%PDF', 'application/pdf');
    }

    public function test_a_rate_limit_and_a_server_error_get_their_own_messages(): void
    {
        $error = fn (int $status, string $type): Response => new Response($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'x']]));

        try {
            $this->readerAnswering($error(429, 'rate_limit_error'))->read('%PDF', 'application/pdf');
            $this->fail('A rate limit must not look like a result.');
        } catch (AiUnavailableException $exception) {
            $this->assertStringContainsString('busy right now', $exception->getMessage());
        }

        try {
            $this->readerAnswering($error(529, 'overloaded_error'))->read('%PDF', 'application/pdf');
            $this->fail('An overload must not look like a result.');
        } catch (AiUnavailableException $exception) {
            $this->assertStringContainsString('temporarily unavailable', $exception->getMessage());
        }

        try {
            $this->readerAnswering($error(401, 'authentication_error'))->read('%PDF', 'application/pdf');
            $this->fail('A rejected key must not look like a result.');
        } catch (AiUnavailableException $exception) {
            $this->assertStringContainsString('key was rejected', $exception->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function message(array $content): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => $content,
            'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1200, 'output_tokens' => 150],
        ];
    }

    private function readerAnswering(Response $response): ClaudeInvoiceReader
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->sent));

        return new ClaudeInvoiceReader(new Client(apiKey: 'test-key', requestOptions: ['transporter' => new HttpClient(['handler' => $stack, 'http_errors' => false]), 'maxRetries' => 0]));
    }
}
