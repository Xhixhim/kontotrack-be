<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_accountant_requests_owner_submits_and_accountant_accepts(): void
    {
        [$accountant, $owner, $business] = $this->accountingTeam();

        $requestId = $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/document-requests", [
            'title' => 'Bank statement June 2026',
            'category' => 'bank_statement',
            'due_on' => '2026-07-25',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

        $notifications = $this->actingAs($owner)->getJson('/api/v1/me/notifications')->json('data');
        $this->assertSame([['document_requested', 'Bank statement June 2026']], array_map(fn (array $item): array => [$item['type'], $item['title']], $notifications));

        $statement = Document::factory()->for($business)->create(['category' => 'bank_statement']);
        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/document-requests/{$requestId}/submit", ['document_id' => $statement->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.document.id', $statement->id);

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/document-requests/{$requestId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_request.accepted', 'subject_id' => $requestId]);
    }

    public function test_sending_a_submission_back_reopens_the_request_with_a_note(): void
    {
        [$accountant, $owner, $business] = $this->accountingTeam();
        $request = DocumentRequest::query()->create(['business_id' => $business->id, 'title' => 'Signed contract', 'category' => 'contract', 'status' => 'open']);
        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/document-requests/{$request->id}/submit", ['document_id' => Document::factory()->for($business)->create()->id])->assertOk();

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/document-requests/{$request->id}/reject", ['review_note' => 'The page with signatures is missing.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.document', null)
            ->assertJsonPath('data.review_note', 'The page with signatures is missing.');
    }

    public function test_rejects_submitting_a_document_of_another_business_with_422(): void
    {
        [, $owner, $business] = $this->accountingTeam();
        $request = DocumentRequest::query()->create(['business_id' => $business->id, 'title' => 'Invoice', 'category' => 'invoice', 'status' => 'open']);

        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/document-requests/{$request->id}/submit", ['document_id' => Document::factory()->create()->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_id' => 'Choose a document that belongs to this business.']);
    }

    public function test_cannot_accept_a_request_that_was_not_submitted(): void
    {
        [$accountant, , $business] = $this->accountingTeam();
        $request = DocumentRequest::query()->create(['business_id' => $business->id, 'title' => 'Invoice', 'category' => 'invoice', 'status' => 'open']);

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/document-requests/{$request->id}/accept")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_request' => 'Only submitted requests can be accepted or sent back.']);
    }

    public function test_assistant_cannot_create_requests_and_auditor_cannot_submit(): void
    {
        [, , $business] = $this->accountingTeam();
        $assistant = $this->addMember($business, 'assistant_accountant');
        $auditor = $this->addMember($business, 'auditor');
        $request = DocumentRequest::query()->create(['business_id' => $business->id, 'title' => 'Invoice', 'category' => 'invoice', 'status' => 'open']);

        $this->actingAs($assistant)->postJson("/api/v1/businesses/{$business->id}/document-requests", ['title' => 'X', 'category' => 'other'])->assertForbidden();
        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/document-requests/{$request->id}/submit", ['document_id' => 1])->assertForbidden();
    }

    public function test_overdue_request_is_a_warning_in_the_checks(): void
    {
        [$accountant, , $business] = $this->accountingTeam();
        DocumentRequest::query()->create(['business_id' => $business->id, 'title' => 'Payroll list', 'category' => 'payroll', 'status' => 'open', 'due_on' => '2026-07-10']);

        $check = collect($this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/controls")->json('data.checks'))->firstWhere('key', 'document_requests');

        $this->assertSame('warning', $check['severity']);
        $this->assertSame('Payroll list', $check['items'][0]['title']);
    }

    /** @return array{User, User, Business} */
    private function accountingTeam(): array
    {
        $business = Business::factory()->create();

        return [$this->addMember($business, 'accountant'), $this->addMember($business, 'owner'), $business];
    }

    private function addMember(Business $business, string $role): User
    {
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
