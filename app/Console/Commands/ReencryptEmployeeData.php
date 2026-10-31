<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Support\BlindIndex;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

#[Signature('kontotrack:reencrypt-employee-data')]
#[Description('Re-encrypt employee personal numbers and IBANs with the current APP_KEY and rebuild their duplicate-check hashes')]
class ReencryptEmployeeData extends Command
{
    /**
     * Run after rotating APP_KEY (keep the old key in APP_PREVIOUS_KEYS until this has run) or after
     * setting KONTOTRACK_BLIND_INDEX_KEY.
     */
    public function handle(): int
    {
        $count = 0;

        Employee::query()->orderBy('id')->chunkById(200, function ($employees) use (&$count): void {
            foreach ($employees as $employee) {
                // Reading decrypts with the current or a previous key; the update writes with the current key.
                $personalNumber = $employee->personal_number;
                $iban = $employee->iban;
                Employee::query()->whereKey($employee->getKey())->toBase()->update([
                    'personal_number' => $personalNumber === null ? null : Crypt::encryptString($personalNumber),
                    'personal_number_hash' => BlindIndex::personalNumber((int) $employee->business_id, $personalNumber),
                    'iban' => $iban === null ? null : Crypt::encryptString($iban),
                ]);
                $count++;
            }
        });

        $this->info("Re-encrypted {$count} employee record(s).");

        return self::SUCCESS;
    }
}
