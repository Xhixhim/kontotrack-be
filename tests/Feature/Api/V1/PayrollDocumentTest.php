<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PayrollDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_contract_is_filed_with_an_employee_and_a_payslip_with_a_payroll_run(): void
    {
        [$accountant, $business] = $this->business('accountant');
        $employee = Employee::factory()->for($business)->create(['first_name' => 'Arta', 'last_name' => 'Krasniqi']);
        $runId = $this->payrollRun($business, 2026, 6);

        $contractId = $this->upload($accountant, $business, ['category' => 'contract', 'employee_id' => $employee->id])
            ->assertCreated()
            ->assertJsonPath('data.employee.name', 'Arta Krasniqi')
            ->json('data.id');
        $this->upload($accountant, $business, ['category' => 'payroll', 'payroll_run_id' => $runId])
            ->assertCreated()
            ->assertJsonPath('data.payroll_run.period', '2026-06');
        $this->upload($accountant, $business, ['category' => 'other'])->assertCreated();

        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/documents")->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/documents?employee_id={$employee->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $contractId);
        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/documents?payroll_run_id={$runId}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_documents_filed_with_payroll_do_not_exist_for_members_without_the_payroll_permission(): void
    {
        [$accountant, $business] = $this->business('accountant');
        $assistant = User::factory()->create();
        $business->users()->attach($assistant, ['role' => 'assistant_accountant', 'status' => 'active']);
        $employee = Employee::factory()->for($business)->create();
        $contractId = $this->upload($accountant, $business, ['category' => 'contract', 'employee_id' => $employee->id])->json('data.id');
        $this->upload($accountant, $business, ['category' => 'other'])->assertCreated();

        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($assistant)->get("/api/v1/businesses/{$business->id}/documents/{$contractId}/download")->assertNotFound();
        $this->actingAs($assistant)->upload($assistant, $business, ['category' => 'contract', 'employee_id' => $employee->id])->assertForbidden();
        $this->actingAs($accountant)->get("/api/v1/businesses/{$business->id}/documents/{$contractId}/download")->assertOk();

        $this->assertSame(2, Document::query()->where('business_id', $business->id)->count());
    }

    public function test_rejects_an_employee_or_payroll_run_of_another_business_with_422(): void
    {
        [$accountant, $business] = $this->business('accountant');
        $foreignEmployee = Employee::factory()->create();
        $foreignRun = $this->payrollRun(Business::factory()->create(), 2026, 6);

        $this->upload($accountant, $business, ['category' => 'contract', 'employee_id' => $foreignEmployee->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->upload($accountant, $business, ['category' => 'payroll', 'payroll_run_id' => $foreignRun])->assertUnprocessable()->assertJsonValidationErrors('payroll_run_id');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_existing_document_can_be_filed_with_an_employee_later(): void
    {
        [$accountant, $business] = $this->business('accountant');
        $employee = Employee::factory()->for($business)->create(['first_name' => 'Besnik', 'last_name' => 'Gashi']);
        $documentId = $this->upload($accountant, $business, ['category' => 'contract'])->json('data.id');

        $this->actingAs($accountant)->putJson("/api/v1/businesses/{$business->id}/documents/{$documentId}", ['employee_id' => $employee->id])
            ->assertOk()
            ->assertJsonPath('data.employee.name', 'Besnik Gashi');
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'document.updated', 'subject_id' => $documentId]);
    }

    /** @param array<string, mixed> $attributes */
    private function upload(User $user, Business $business, array $attributes)
    {
        return $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/documents", [
            'file' => UploadedFile::fake()->create('dokument.pdf', 20, 'application/pdf'),
            ...$attributes,
        ], ['Accept' => 'application/json']);
    }

    private function payrollRun(Business $business, int $year, int $month): int
    {
        return DB::table('payroll_runs')->insertGetId([
            'business_id' => $business->id, 'period_year' => $year, 'period_month' => $month, 'pay_date' => sprintf('%04d-%02d-28', $year, $month), 'status' => 'approved',
            'gross_total' => '0.00', 'employee_contribution_total' => '0.00', 'employer_contribution_total' => '0.00', 'income_tax_total' => '0.00', 'net_total' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{User, Business} */
    private function business(string $role): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }
}
