<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\User;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Support\Facades\DB;

class ChartTemplateService
{
    /**
     * Standard chart of accounts for Kosovo businesses, ordered like the financial statements under the
     * Kosovo Accounting Standards: classes 1–2 assets, 3 equity, 4 liabilities, 5 revenue, 6 expenses.
     * Kosovo has no single mandatory chart, so this is a starting point the accountant can extend.
     *
     * @var list<array{code: string, name: string, account_type: string, normal_balance: string, parent?: string, purpose?: string}>
     */
    public const KOSOVO_STANDARD = [
        ['code' => '1', 'name' => 'Pasuritë afatgjata · Non-current assets', 'account_type' => 'asset', 'normal_balance' => 'debit'],
        ['code' => '1100', 'name' => 'Prona, impiantet dhe pajisjet · Property, plant and equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1', 'purpose' => 'fixed_assets'],
        ['code' => '1110', 'name' => 'Toka · Land', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1100'],
        ['code' => '1120', 'name' => 'Ndërtesat · Buildings', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1100'],
        ['code' => '1130', 'name' => 'Makineri dhe pajisje · Machinery and equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1100'],
        ['code' => '1140', 'name' => 'Automjetet · Vehicles', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1100'],
        ['code' => '1150', 'name' => 'Mobilie dhe pajisje zyre · Furniture and office equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1100'],
        ['code' => '1160', 'name' => 'Pajisje kompjuterike · IT equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1100'],
        ['code' => '1200', 'name' => 'Pasuritë e patrupëzuara · Intangible assets', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1'],
        ['code' => '1290', 'name' => 'Zhvlerësimi i akumuluar · Accumulated depreciation', 'account_type' => 'asset', 'normal_balance' => 'credit', 'parent' => '1', 'purpose' => 'accumulated_depreciation'],

        ['code' => '2', 'name' => 'Pasuritë qarkulluese · Current assets', 'account_type' => 'asset', 'normal_balance' => 'debit'],
        ['code' => '2100', 'name' => 'Stoqet e mallrave · Inventory of goods', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2'],
        ['code' => '2110', 'name' => 'Lëndët e para dhe materialet · Raw materials and supplies', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2'],
        ['code' => '2200', 'name' => 'Llogaritë e arkëtueshme nga klientët · Trade receivables', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2', 'purpose' => 'accounts_receivable'],
        ['code' => '2210', 'name' => 'Paradhëniet për furnitorët · Advances to suppliers', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2'],
        ['code' => '2300', 'name' => 'TVSH e zbritshme · Input VAT', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2', 'purpose' => 'vat_input'],
        ['code' => '2310', 'name' => 'Tatimi në fitim i parapaguar · Prepaid corporate income tax', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2'],
        ['code' => '2400', 'name' => 'Arka · Cash on hand', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2'],
        ['code' => '2500', 'name' => 'Banka · Bank account', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2'],
        ['code' => '2600', 'name' => 'Shpenzimet e parapaguara · Prepaid expenses', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent' => '2'],

        ['code' => '3', 'name' => 'Kapitali · Equity', 'account_type' => 'equity', 'normal_balance' => 'credit'],
        ['code' => '3100', 'name' => 'Kapitali themelor · Share capital', 'account_type' => 'equity', 'normal_balance' => 'credit', 'parent' => '3'],
        ['code' => '3200', 'name' => 'Rezervat · Reserves', 'account_type' => 'equity', 'normal_balance' => 'credit', 'parent' => '3'],
        ['code' => '3300', 'name' => 'Fitimi i mbajtur · Retained earnings', 'account_type' => 'equity', 'normal_balance' => 'credit', 'parent' => '3'],
        ['code' => '3400', 'name' => 'Fitimi ose humbja e vitit · Profit or loss for the year', 'account_type' => 'equity', 'normal_balance' => 'credit', 'parent' => '3'],
        ['code' => '3500', 'name' => 'Tërheqjet e pronarit · Owner drawings', 'account_type' => 'equity', 'normal_balance' => 'debit', 'parent' => '3'],

        ['code' => '4', 'name' => 'Detyrimet · Liabilities', 'account_type' => 'liability', 'normal_balance' => 'credit'],
        ['code' => '4100', 'name' => 'Kreditë afatgjata · Long-term loans', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4'],
        ['code' => '4200', 'name' => 'Kreditë afatshkurtra · Short-term loans', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4'],
        ['code' => '4300', 'name' => 'Llogaritë e pagueshme ndaj furnitorëve · Trade payables', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4', 'purpose' => 'accounts_payable'],
        ['code' => '4310', 'name' => 'Paradhëniet nga klientët · Advances from customers', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4'],
        ['code' => '4400', 'name' => 'TVSH e pagueshme · Output VAT', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4', 'purpose' => 'vat_output'],
        ['code' => '4500', 'name' => 'Pagat neto të pagueshme · Net salaries payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4', 'purpose' => 'net_salaries_payable'],
        ['code' => '4510', 'name' => 'Kontributet pensionale të pagueshme · Pension contributions payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4', 'purpose' => 'pension_contributions_payable'],
        ['code' => '4520', 'name' => 'Tatimi në paga i pagueshëm · Wage tax payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4', 'purpose' => 'income_tax_payable'],
        ['code' => '4530', 'name' => 'Tatimi në burim i pagueshëm · Withholding tax payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4'],
        ['code' => '4600', 'name' => 'Tatimi në fitim i pagueshëm · Corporate income tax payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4'],
        ['code' => '4700', 'name' => 'Detyrimet e tjera · Other payables', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent' => '4'],

        ['code' => '5', 'name' => 'Të hyrat · Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit'],
        ['code' => '5100', 'name' => 'Të hyrat nga shitjet · Sales revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '5', 'purpose' => 'sales_revenue'],
        ['code' => '5110', 'name' => 'Shitja e mallrave · Sales of goods', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '5100'],
        ['code' => '5120', 'name' => 'Shitja e shërbimeve · Sales of services', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '5100'],
        ['code' => '5200', 'name' => 'Të hyrat e tjera · Other income', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '5'],
        ['code' => '5300', 'name' => 'Të hyrat financiare · Finance income', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '5'],
        ['code' => '5400', 'name' => 'Fitimi nga shitja e pasurive · Gain on disposal of assets', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '5'],

        ['code' => '6', 'name' => 'Shpenzimet · Expenses', 'account_type' => 'expense', 'normal_balance' => 'debit'],
        ['code' => '6100', 'name' => 'Kostoja e mallrave të shitura · Cost of goods sold', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6200', 'name' => 'Blerjet dhe shpenzimet · Purchases and expenses', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6', 'purpose' => 'purchase_expense'],
        ['code' => '6300', 'name' => 'Pagat bruto · Gross salaries', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6', 'purpose' => 'salary_expense'],
        ['code' => '6310', 'name' => 'Kontributi pensional i punëdhënësit · Employer pension contribution', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6', 'purpose' => 'employer_contribution_expense'],
        ['code' => '6400', 'name' => 'Shpenzimet e zhvlerësimit · Depreciation expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6', 'purpose' => 'depreciation_expense'],
        ['code' => '6410', 'name' => 'Humbja nga shitja e pasurive · Loss on disposal of assets', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6', 'purpose' => 'asset_disposal_loss'],
        ['code' => '6500', 'name' => 'Qiraja · Rent', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6510', 'name' => 'Shërbimet komunale · Utilities', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6520', 'name' => 'Karburanti dhe transporti · Fuel and transport', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6530', 'name' => 'Telefoni dhe interneti · Phone and internet', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6540', 'name' => 'Shërbimet profesionale · Professional services', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6550', 'name' => 'Mirëmbajtja dhe riparimet · Repairs and maintenance', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6560', 'name' => 'Reklama dhe marketingu · Advertising and marketing', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6570', 'name' => 'Shpenzimet bankare · Bank charges', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6580', 'name' => 'Sigurimet · Insurance', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6590', 'name' => 'Shpenzimet e tjera operative · Other operating expenses', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6600', 'name' => 'Shpenzimet e interesit · Interest expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
        ['code' => '6700', 'name' => 'Shpenzimi i tatimit në fitim · Corporate income tax expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent' => '6'],
    ];

    public function __construct(private AccountRepository $accountRepository) {}

    /**
     * Add the template accounts whose codes the business does not use yet. Existing accounts are never changed,
     * and a posting purpose is mapped only when it is still unmapped and its template account was created now.
     *
     * @return array{created: list<string>, skipped: list<string>, mapped: list<string>}
     */
    public function applyKosovoStandard(Business $business, User $user): array
    {
        return DB::transaction(function () use ($business, $user): array {
            $existing = $business->accounts()->lockForUpdate()->pluck('id', 'code')->all();
            $mappedPurposes = $business->postingAccounts()->pluck('purpose')->all();
            $created = [];
            $skipped = [];
            $mapped = [];

            foreach (self::KOSOVO_STANDARD as $template) {
                if (isset($existing[$template['code']])) {
                    $skipped[] = $template['code'];

                    continue;
                }

                $account = $this->accountRepository->createForBusiness($business, [
                    'code' => $template['code'],
                    'name' => $template['name'],
                    'account_type' => $template['account_type'],
                    'normal_balance' => $template['normal_balance'],
                    'parent_id' => isset($template['parent']) ? ($existing[$template['parent']] ?? null) : null,
                    'is_system' => false,
                    'is_active' => true,
                ]);
                $existing[$template['code']] = $account->getKey();
                $created[] = $template['code'];

                if (isset($template['purpose']) && ! in_array($template['purpose'], $mappedPurposes, true)) {
                    $business->postingAccounts()->create(['purpose' => $template['purpose'], 'account_id' => $account->getKey()]);
                    $mapped[] = $template['purpose'];
                }
            }

            if ($created !== []) {
                $business->auditLogs()->create([
                    'user_id' => $user->getKey(),
                    'action' => 'account.template_applied',
                    'subject_type' => Account::class,
                    'new_values' => ['template' => 'kosovo_standard', 'created_codes' => $created, 'skipped_codes' => $skipped],
                ]);
            }

            if ($mapped !== []) {
                $business->auditLogs()->create([
                    'user_id' => $user->getKey(),
                    'action' => 'posting_accounts.updated',
                    'subject_type' => BusinessPostingAccount::class,
                    'new_values' => $business->postingAccounts()->whereIn('purpose', $mapped)->pluck('account_id', 'purpose')->all(),
                ]);
            }

            return ['created' => $created, 'skipped' => $skipped, 'mapped' => $mapped];
        });
    }
}
