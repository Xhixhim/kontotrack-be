<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Contracts\InvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['kontotrack.ai.api_key' => 'test-key']);
        $this->user = User::factory()->create();
        $this->business = Business::factory()->create();
        $this->business->users()->attach($this->user, ['role' => 'accountant', 'status' => 'active']);
    }

    public function test_reads_an_invoice_and_stores_the_fields_for_review(): void
    {
        $document = $this->storedDocument('application/pdf', '%PDF-1.4 supplier bill');
        $received = [];
        $this->readerReturning([
            'supplier_name' => '  Furnitori SH.P.K. ', 'supplier_nui' => '811 234 567', 'document_number' => 'F-2026/118', 'document_date' => '2026-06-15',
            'currency' => 'eur', 'net_amount' => 100, 'tax_amount' => 18.0, 'gross_amount' => 118.004,
            'lines' => [['description' => 'Kafe 1 kg', 'quantity' => 10, 'unit_price' => 10, 'vat_rate_percent' => 18, 'net_amount' => 100], ['description' => '', 'quantity' => null, 'unit_price' => null, 'vat_rate_percent' => null, 'net_amount' => null]],
        ], $received);

        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/documents/{$document->id}/read")
            ->assertOk()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.extracted_data.supplier_name', 'Furnitori SH.P.K.')
            ->assertJsonPath('data.extracted_data.supplier_nui', '811234567')
            ->assertJsonPath('data.extracted_data.document_date', '2026-06-15')
            ->assertJsonPath('data.extracted_data.currency', 'EUR')
            ->assertJsonPath('data.extracted_data.net_amount', '100.00')
            ->assertJsonPath('data.extracted_data.gross_amount', '118.00')
            ->assertJsonPath('data.extracted_data.source', 'ocr')
            ->assertJsonCount(1, 'data.extracted_data.lines');

        $this->assertSame(['%PDF-1.4 supplier bill', 'application/pdf'], $received);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $this->business->id, 'action' => 'document.read_automatically', 'subject_id' => $document->id, 'user_id' => $this->user->id]);
    }

    public function test_reading_again_puts_a_reviewed_document_back_to_pending(): void
    {
        $document = $this->storedDocument('image/jpeg', 'jpeg-bytes', ['review_status' => 'reviewed', 'reviewed_by' => $this->user->id, 'reviewed_at' => now()]);
        $received = [];
        $this->readerReturning(['supplier_name' => null, 'supplier_nui' => null, 'document_number' => null, 'document_date' => 'not a date', 'currency' => null, 'net_amount' => 'abc', 'tax_amount' => null, 'gross_amount' => null, 'lines' => 'none'], $received);

        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/documents/{$document->id}/read")
            ->assertOk()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.extracted_data.document_date', null)
            ->assertJsonPath('data.extracted_data.net_amount', null)
            ->assertJsonPath('data.extracted_data.lines', []);

        $this->assertNull($document->refresh()->reviewed_by);
    }

    public function test_answers_503_when_reading_is_not_set_up_or_the_service_fails(): void
    {
        $document = $this->storedDocument('application/pdf', '%PDF');
        $this->app->bind(InvoiceReader::class, fn () => new class implements InvoiceReader
        {
            public function read(string $contents, string $mimeType): array
            {
                throw new AiUnavailableException('Automatic reading is busy right now. Try again in a minute, or type the fields in.');
            }
        });

        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/documents/{$document->id}/read")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Automatic reading is busy right now. Try again in a minute, or type the fields in.');

        config(['kontotrack.ai.api_key' => null]);
        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/documents/{$document->id}/read")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Automatic reading is not set up on this server.');
        $this->actingAs($this->user)->getJson('/api/v1/features')->assertOk()->assertJsonPath('data.document_reading', false);
        $this->assertNull($document->refresh()->extracted_data);
    }

    public function test_rejects_a_file_type_that_cannot_be_read_with_422(): void
    {
        $document = $this->storedDocument('text/plain', 'notes');

        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/documents/{$document->id}/read")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }

    public function test_only_bookkeepers_read_documents_and_only_their_own(): void
    {
        $document = $this->storedDocument('application/pdf', '%PDF');
        $foreign = Document::factory()->create();
        $auditor = User::factory()->create();
        $this->business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$this->business->id}/documents/{$document->id}/read")->assertForbidden();
        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/documents/{$foreign->id}/read")->assertNotFound();
    }

    /** @param array<string, mixed> $attributes */
    private function storedDocument(string $mimeType, string $contents, array $attributes = []): Document
    {
        $path = "businesses/{$this->business->id}/documents/".uniqid('doc', true);
        Storage::disk('local')->put($path, $contents);

        return Document::factory()->for($this->business)->create(['disk' => 'local', 'path' => $path, 'mime_type' => $mimeType, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $received
     */
    private function readerReturning(array $fields, array &$received): void
    {
        $this->app->bind(InvoiceReader::class, function () use ($fields, &$received) {
            return new class($fields, $received) implements InvoiceReader
            {
                /**
                 * @param  array<string, mixed>  $fields
                 * @param  list<string>  $received
                 */
                public function __construct(private array $fields, private array &$received) {}

                public function read(string $contents, string $mimeType): array
                {
                    $this->received = [$contents, $mimeType];

                    return $this->fields;
                }
            };
        });
    }
}
