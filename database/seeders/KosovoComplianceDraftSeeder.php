<?php

namespace Database\Seeders;

use App\Models\ComplianceRuleSet;
use App\Models\User;
use App\Services\Compliance\ComplianceRuleSetService;
use Illuminate\Database\Seeder;

/**
 * Creates DRAFT Kosovo rule sets for VAT, payroll, and the tax calendar so a system admin can check
 * them against the official texts and approve them in Compliance settings. Nothing here is approved,
 * and a domain that already has any XK rule set is left alone.
 *
 * php artisan db:seed --class=KosovoComplianceDraftSeeder
 */
class KosovoComplianceDraftSeeder extends Seeder
{
    public function run(ComplianceRuleSetService $ruleSets): void
    {
        $admin = User::query()->where('is_system_admin', true)->orderBy('id')->first();

        if ($admin === null) {
            $this->command?->error('Create a system admin first; rule set drafts record who created them.');

            return;
        }

        foreach ($this->drafts() as $draft) {
            if (ComplianceRuleSet::query()->where('jurisdiction', 'XK')->where('domain', $draft['domain'])->exists()) {
                $this->command?->warn("Skipped {$draft['domain']}: an XK rule set already exists.");

                continue;
            }

            $ruleSets->createDraft($admin, $draft);
            $this->command?->info("Created draft XK {$draft['domain']} rule set. Verify it, then approve it in Compliance settings.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function drafts(): array
    {
        return [
            [
                'jurisdiction' => 'XK',
                'domain' => 'vat',
                'version' => '2026.1',
                'valid_from' => '2026-01-01',
                'source_title' => 'Law No. 05/L-037 on Value Added Tax',
                'source_url' => 'https://www.atk-ks.org/wp-content/uploads/2017/07/LAW_NO._05_L-037_ON_VALUE_ADDED_TAX___ANNEX.pdf',
                'rules' => [
                    [
                        'rule_key' => 'vat_codes',
                        'description' => 'Standard 18%, reduced 8% (goods and services listed in the law annex), capital purchases, exports, services supplied abroad, exempt supplies with and without credit, reverse charge (foreign services and domestic construction), and amounts outside VAT. atk_sales_box / atk_purchase_box are the VAT return boxes used by the ATK sales and purchase books. Check the current reduced-rate list before approving.',
                        'configuration' => ['codes' => [
                            ['code' => 'S18', 'label' => 'TVSH 18% · Standard', 'rate_percent' => '18', 'treatment' => 'taxable', 'atk_sales_box' => '12', 'atk_purchase_box' => '43'],
                            ['code' => 'R8', 'label' => 'TVSH 8% · Reduced', 'rate_percent' => '8', 'treatment' => 'taxable', 'atk_sales_box' => '14', 'atk_purchase_box' => '45'],
                            ['code' => 'INV18', 'label' => 'TVSH 18% · Blerje investive / Capital purchase', 'rate_percent' => '18', 'treatment' => 'taxable', 'atk_sales_box' => '12', 'atk_purchase_box' => '47'],
                            ['code' => 'INV8', 'label' => 'TVSH 8% · Blerje investive / Capital purchase', 'rate_percent' => '8', 'treatment' => 'taxable', 'atk_sales_box' => '14', 'atk_purchase_box' => '49'],
                            ['code' => 'Z0', 'label' => 'Eksport mallrash · Export of goods', 'rate_percent' => '0', 'treatment' => 'zero_rated', 'atk_sales_box' => '11', 'atk_purchase_box' => '31'],
                            ['code' => 'ZS', 'label' => 'Shërbime jashtë vendit · Services supplied abroad', 'rate_percent' => '0', 'treatment' => 'zero_rated', 'atk_sales_box' => '10a', 'atk_purchase_box' => '31'],
                            ['code' => 'EC', 'label' => 'E liruar me të drejtë kreditimi · Exempt with credit', 'rate_percent' => '0', 'treatment' => 'zero_rated', 'atk_sales_box' => '10c', 'atk_purchase_box' => '31'],
                            ['code' => 'E', 'label' => 'E liruar pa të drejtë kreditimi · Exempt', 'rate_percent' => '0', 'treatment' => 'exempt', 'atk_sales_box' => '9', 'atk_purchase_box' => '31'],
                            ['code' => 'RC', 'label' => 'Ngarkesë e kundërt · Reverse charge', 'rate_percent' => '0', 'treatment' => 'exempt', 'atk_sales_box' => '10b', 'atk_purchase_box' => '65'],
                            ['code' => 'N', 'label' => 'Jashtë TVSH · Outside VAT', 'rate_percent' => '0', 'treatment' => 'non_vat'],
                        ]],
                    ],
                    [
                        'rule_key' => 'registration_threshold',
                        'description' => 'Mandatory VAT registration once annual turnover passes EUR 30,000. Warn at 80%.',
                        'configuration' => ['amount' => '30000.00', 'warning_percent' => '80'],
                    ],
                ],
            ],
            [
                'jurisdiction' => 'XK',
                'domain' => 'payroll',
                'version' => '2026.1',
                'valid_from' => '2026-01-01',
                'source_title' => 'Personal income tax bands and Kosovo Pension Savings Trust contributions (PwC Tax Summaries; verify against the current Law on Personal Income Tax and Law No. 04/L-101 on Pension Funds)',
                'source_url' => 'https://taxsummaries.pwc.com/kosovo/individual/taxes-on-personal-income',
                'rules' => [
                    [
                        'rule_key' => 'pension_contributions',
                        'description' => 'Mandatory 5% employee and 5% employer contribution on gross salary.',
                        'configuration' => ['employee_percent' => '5', 'employer_percent' => '5'],
                    ],
                    [
                        'rule_key' => 'income_tax',
                        'description' => 'Monthly bands for the primary employer: 0% up to EUR 250, 8% from 250 to 450, 10% above 450 (EUR 3,000 / 5,400 a year). Secondary employment is taxed at a flat 10%.',
                        'configuration' => [
                            'primary_brackets' => [
                                ['up_to' => '250.00', 'rate_percent' => '0'],
                                ['up_to' => '450.00', 'rate_percent' => '8'],
                                ['up_to' => null, 'rate_percent' => '10'],
                            ],
                            'secondary_rate_percent' => '10',
                        ],
                    ],
                ],
            ],
            [
                'jurisdiction' => 'XK',
                'domain' => 'tax_calendar',
                'version' => '2026.1',
                'valid_from' => '2026-01-01',
                'source_title' => 'Law No. 03/L-222 on Tax Administration and Procedures, Law No. 05/L-037 on VAT, Law No. 06/L-032 on Accounting, Financial Reporting and Auditing',
                'source_url' => 'https://www.atk-ks.org/wp-content/uploads/2017/07/Ligji-_03-L-222_E.pdf',
                'rules' => [
                    [
                        'rule_key' => 'deadlines',
                        'description' => 'Monthly VAT return by the 20th; monthly wage-tax and pension declaration by the 15th; quarterly corporate income tax instalments by the 15th after the quarter; annual corporate income tax return by 31 March; annual financial statements to the KCFR by 30 April. Confirm each date with ATK before approving.',
                        'configuration' => ['deadlines' => [
                            ['type' => 'vat_return', 'title' => 'Deklarata e TVSH-së · VAT return', 'frequency' => 'monthly', 'due_day' => 20, 'months_after_period' => 1, 'applies_to' => 'vat_registered'],
                            ['type' => 'payroll_declaration', 'title' => 'Tatimi në paga dhe kontributet · Wage tax and pension', 'frequency' => 'monthly', 'due_day' => 15, 'months_after_period' => 1, 'applies_to' => 'employers'],
                            ['type' => 'cit_instalment', 'title' => 'Kësti i tatimit në fitim · Corporate income tax instalment', 'frequency' => 'quarterly', 'due_day' => 15, 'months_after_period' => 1, 'applies_to' => 'all'],
                            ['type' => 'cit_annual_return', 'title' => 'Deklarata vjetore e tatimit në fitim · Annual corporate income tax return', 'frequency' => 'annual', 'due_day' => 31, 'due_month' => 3, 'applies_to' => 'all'],
                            ['type' => 'kcfr_financial_statements', 'title' => 'Pasqyrat financiare në KKRF · Financial statements to KCFR', 'frequency' => 'annual', 'due_day' => 30, 'due_month' => 4, 'applies_to' => 'all'],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
