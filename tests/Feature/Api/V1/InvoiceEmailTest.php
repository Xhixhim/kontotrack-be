<?php

namespace Tests\Feature\Api\V1;

use App\Mail\InvoiceMail;
use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class InvoiceEmailTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
    }

    public function test_emails_an_issued_invoice_with_the_pdf_attached(): void
    {
        Mail::fake();
        [$user, $business] = $this->bookkeepingBusiness();
        $business->update(['trade_name' => 'Kafe Drini', 'email' => 'zyra@kafedrini.test']);
        $invoiceId = $this->issuedSale($user, $business);
        $number = Invoice::query()->findOrFail($invoiceId)->invoice_number;

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/send", [
            'to' => 'klient@example.test',
            'cc' => ['kontabilist@example.test'],
            'message' => 'Faleminderit për bashkëpunimin.',
        ])->assertOk()->assertJsonPath('data.last_sent_to', 'klient@example.test');

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($number): bool {
            $mail->assertHasTo('klient@example.test');
            $mail->assertHasCc('kontabilist@example.test');
            $mail->assertHasReplyTo('zyra@kafedrini.test');
            $mail->assertSeeInText('Faleminderit për bashkëpunimin.');
            $mail->assertSeeInText($number);
            $mail->assertSeeInText('118,00');

            return str_contains($mail->envelope()->subject, $number)
                && count($mail->attachments()) === 1
                && $mail->envelope()->from->name === 'Kafe Drini';
        });
        $this->assertNotNull(Invoice::query()->findOrFail($invoiceId)->last_sent_at);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'invoice.emailed', 'subject_id' => $invoiceId, 'user_id' => $user->id]);
    }

    public function test_rejects_sending_a_draft_with_422(): void
    {
        Mail::fake();
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $draftId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$draftId}/send", ['to' => 'klient@example.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice' => 'Issue the invoice before sending it.']);

        Mail::assertNothingSent();
    }

    public function test_rejects_sending_a_purchase_bill_with_422(): void
    {
        Mail::fake();
        [$user, $business] = $this->bookkeepingBusiness();
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $billId = $this->createDraft($user, $business, ['kind' => 'purchase', 'counterparty_id' => $supplier->id, 'invoice_number' => 'F-77'], 'S18');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$billId}/send", ['to' => 'furnitor@example.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        Mail::assertNothingSent();
    }

    public function test_rejects_an_invalid_recipient_with_422(): void
    {
        Mail::fake();
        [$user, $business] = $this->bookkeepingBusiness();
        $invoiceId = $this->issuedSale($user, $business);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/send", ['to' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_only_bookkeepers_can_send_and_only_inside_their_business(): void
    {
        Mail::fake();
        [$user, $business] = $this->bookkeepingBusiness();
        $invoiceId = $this->issuedSale($user, $business);
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);
        [$otherUser, $otherBusiness] = [User::factory()->create(), Business::factory()->create()];
        $otherBusiness->users()->attach($otherUser, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/send", ['to' => 'klient@example.test'])->assertForbidden();
        $this->actingAs($otherUser)->postJson("/api/v1/businesses/{$otherBusiness->id}/invoices/{$invoiceId}/send", ['to' => 'klient@example.test'])->assertNotFound();

        Mail::assertNothingSent();
    }

    private function issuedSale(User $user, Business $business): int
    {
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        return $invoiceId;
    }
}
