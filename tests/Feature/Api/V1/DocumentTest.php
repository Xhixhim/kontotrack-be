<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_owner_uploads_a_lease_linked_to_a_supplier_and_date(): void
    {
        [$owner, $business] = $this->member('owner');
        $landlord = Counterparty::factory()->for($business)->supplier()->create();

        $response = $this->actingAs($owner)->post("/api/v1/businesses/{$business->id}/documents", [
            'file' => UploadedFile::fake()->create('qiraja.pdf', 120, 'application/pdf'),
            'category' => 'lease',
            'counterparty_id' => $landlord->id,
            'document_date' => '2026-01-01',
            'notes' => 'Office lease 2026',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.category', 'lease')
            ->assertJsonPath('data.document_date', '2026-01-01');
        $document = Document::query()->sole();
        $this->assertSame($landlord->id, $document->counterparty_id);
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame('document.uploaded', AuditLog::query()->where('subject_type', Document::class)->sole()->action);
    }

    public function test_rejects_linking_a_contact_of_another_business_with_422(): void
    {
        [$owner, $business] = $this->member('owner');

        $this->actingAs($owner)->post("/api/v1/businesses/{$business->id}/documents", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            'category' => 'contract',
            'counterparty_id' => Counterparty::factory()->create()->id,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['counterparty_id' => 'Choose a client or supplier that belongs to this business.']);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_auditor_cannot_upload_documents(): void
    {
        [$auditor, $business] = $this->member('auditor');

        $this->actingAs($auditor)->post("/api/v1/businesses/{$business->id}/documents", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            'category' => 'other',
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_accountant_corrects_document_details_and_the_change_is_audited(): void
    {
        [$accountant, $business] = $this->member('accountant');
        $document = Document::factory()->for($business)->create(['category' => 'other']);

        $this->actingAs($accountant)->putJson("/api/v1/businesses/{$business->id}/documents/{$document->id}", ['category' => 'certificate', 'document_date' => '2025-12-15'])
            ->assertOk()
            ->assertJsonPath('data.category', 'certificate');

        $auditLog = AuditLog::query()->where('action', 'document.updated')->sole();
        $this->assertEquals(['category' => 'other', 'document_date' => null], $auditLog->old_values);
        $this->assertEquals(['category' => 'certificate', 'document_date' => '2025-12-15'], $auditLog->new_values);
    }

    public function test_assistant_cannot_change_document_details(): void
    {
        [$assistant, $business] = $this->member('assistant_accountant');
        $document = Document::factory()->for($business)->create();

        $this->actingAs($assistant)->putJson("/api/v1/businesses/{$business->id}/documents/{$document->id}", ['category' => 'invoice'])->assertForbidden();
    }

    public function test_review_stores_the_confirmed_extracted_fields(): void
    {
        [$accountant, $business] = $this->member('accountant');
        $document = Document::factory()->for($business)->invoice()->create();

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/documents/{$document->id}/review", ['extracted_data' => [
            'supplier_name' => 'Furnitori A SH.P.K.',
            'document_number' => 'F-100',
            'document_date' => '2026-06-15',
            'gross_amount' => '118.00',
        ]])->assertOk()
            ->assertJsonPath('data.review_status', 'reviewed')
            ->assertJsonPath('data.extracted_data.document_number', 'F-100');

        $this->assertSame($accountant->id, $document->refresh()->reviewed_by);
    }

    public function test_filters_documents_by_category_contact_review_status_and_date(): void
    {
        [$accountant, $business] = $this->member('accountant');
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $match = Document::factory()->for($business)->create(['category' => 'invoice', 'counterparty_id' => $supplier->id, 'document_date' => '2026-06-10']);
        Document::factory()->for($business)->create(['category' => 'invoice', 'counterparty_id' => $supplier->id, 'document_date' => '2026-05-10']);
        Document::factory()->for($business)->create(['category' => 'receipt', 'document_date' => '2026-06-10']);

        $this->actingAs($accountant)
            ->getJson("/api/v1/businesses/{$business->id}/documents?category=invoice&counterparty_id={$supplier->id}&review_status=pending&from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.counterparty.id', $supplier->id);
    }

    public function test_download_returns_404_for_a_document_of_another_business(): void
    {
        [$accountant, $business] = $this->member('accountant');
        $foreign = Document::factory()->create();

        $this->actingAs($accountant)->get("/api/v1/businesses/{$business->id}/documents/{$foreign->id}/download")->assertNotFound();
    }

    /** @return array{User, Business} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }
}
