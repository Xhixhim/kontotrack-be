<?php

namespace Tests\Feature\Api\V1;

use App\Models\Counterparty;
use App\Models\User;
use App\Support\Localizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class ApiLocalizationTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
    }

    public function test_answers_in_english_unless_albanian_is_asked_for(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/businesses', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.legal_name.0', 'The legal name field is required.');
        $this->actingAs($user)->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])->postJson('/api/v1/businesses', [])
            ->assertJsonPath('errors.legal_name.0', 'The legal name field is required.');
    }

    public function test_validation_errors_and_their_summary_are_in_albanian(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withHeaders(['Accept-Language' => 'sq'])->postJson('/api/v1/businesses', ['nui' => '123'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.legal_name.0', 'Fusha emri ligjor është e detyrueshme.')
            ->assertJsonPath('errors.nui.0', 'NUI ka 9 shifra, si në certifikatën e regjistrimit të biznesit.')
            ->assertJsonPath('errors.vat_status.0', 'Fusha statusi i TVSH-së është e detyrueshme.');

        $this->assertSame('Fusha emri ligjor është e detyrueshme. (dhe 2 gabime të tjera)', $response->json('message'));
    }

    public function test_business_rule_errors_are_in_albanian(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $draftId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'sq-XK'])->postJson("/api/v1/businesses/{$business->id}/invoices/{$draftId}/send", ['to' => 'klient@example.test'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.invoice.0', 'Lëshoje faturën para se ta dërgosh.')
            ->assertJsonPath('message', 'Lëshoje faturën para se ta dërgosh.');
    }

    public function test_permission_and_sign_in_errors_are_in_albanian(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->withHeaders(['Accept-Language' => 'sq'])->getJson('/api/v1/businesses')->assertUnauthorized()->assertJsonPath('message', 'Duhet të hysh në llogari.');
        $this->actingAs($auditor)->withHeaders(['Accept-Language' => 'sq'])->postJson("/api/v1/businesses/{$business->id}/counterparties", ['type' => 'customer', 'name' => 'X'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Nuk ke leje për këtë veprim.');
        $this->withHeaders(['Accept-Language' => 'sq'])->postJson('/api/v1/login', ['email' => 'nobody@example.test', 'password' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Këto kredenciale nuk përputhen me të dhënat tona.');
    }

    public function test_automatic_checks_are_in_albanian_with_their_numbers(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        Counterparty::factory()->for($business)->customer()->count(2)->create(['nui' => null]);

        $checks = collect($this->actingAs($user)->withHeaders(['Accept-Language' => 'sq'])->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk()->json('data.checks'))->keyBy('key');

        $this->assertSame('NUI i klientëve dhe furnitorëve', $checks['counterparty_nui']['label']);
        $this->assertSame('2 klientë ose furnitorë aktivë nuk kanë NUI; përmbledhja e TVSH-së i tregon si të munguar.', $checks['counterparty_nui']['detail']);
        $this->assertSame('Asnjë arkë nuk ka gjendje negative.', $checks['negative_cash']['detail']);
        $this->assertSame('warning', $checks['counterparty_nui']['severity']);
    }

    public function test_messages_with_numbers_and_names_are_translated(): void
    {
        app()->setLocale('sq');

        $this->assertSame('Pagat për 05.2026 janë miratuar dhe postuar.', Localizer::translate('The payroll for 2026-05 is approved and posted.'));
        $this->assertSame('Nuk janë llogaritur paga për 05.2026 (3 punëtorë).', Localizer::translate('No payroll has been calculated for 2026-05 (3 employee(s)).'));
        $this->assertSame('12 fatura të klientëve kanë kaluar afatin, me 1540.50 të papaguara.', Localizer::translate('12 customer invoice(s) are past due, with 1540.50 outstanding.'));
        $this->assertSame('Nuk ka artikull aktiv me kodin KAFE-1.', Localizer::translate('There is no active item with the code KAFE-1.'));
        $this->assertSame('Kjo do ta linte arkën "Arka kryesore" me -25.00 më 2026-06-10. Paraja në arkë nuk mund të jetë negative, prandaj së pari regjistro arkëtimet që mungojnë.', Localizer::translate('This would leave the cash account "Arka kryesore" at -25.00 on 2026-06-10. Cash on hand cannot be negative, so record the missing cash receipts first.'));
        $this->assertSame('Rreshti 4: Fusha emri është e detyrueshme.', Localizer::translate('Line 4: Fusha emri është e detyrueshme.'));
        $this->assertSame('A sentence nobody translated 7 times.', Localizer::translate('A sentence nobody translated 7 times.'));

        app()->setLocale('en');
        $this->assertSame('The payroll for 2026-05 is approved and posted.', Localizer::translate('The payroll for 2026-05 is approved and posted.'));
    }

    public function test_every_number_template_fills_the_same_numbers_it_takes(): void
    {
        foreach (require lang_path('sq/templates.php') as $english => $albanian) {
            $taken = substr_count($english, '#');
            preg_match_all('/#(\d+)?/', $albanian, $placeholders);
            $explicit = array_filter($placeholders[1], fn (string $index): bool => $index !== '');
            $sequential = count($placeholders[0]) - count($explicit);

            $this->assertLessThanOrEqual($taken, $sequential + count(array_unique($explicit)), "Too many placeholders in: {$albanian}");
            $this->assertLessThanOrEqual($taken, max([0, ...array_map('intval', $explicit)]), "Placeholder out of range in: {$albanian}");
        }

        foreach (json_decode((string) file_get_contents(lang_path('sq.json')), true) as $english => $albanian) {
            $this->assertNotSame('', trim($albanian), "Empty translation for: {$english}");
        }
    }
}
