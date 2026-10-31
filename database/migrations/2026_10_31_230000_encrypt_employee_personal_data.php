<?php

use App\Support\BlindIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts employee personal numbers and IBANs at rest, adds a blind index for the per-business
 * uniqueness check, and masks the plain values earlier employee updates left in the audit log.
 */
return new class extends Migration
{
    private const SENSITIVE = ['personal_number', 'iban'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('personal_number_hash', 64)->nullable()->after('personal_number');
            $table->text('personal_number_encrypted')->nullable()->after('personal_number_hash');
            $table->text('iban_encrypted')->nullable()->after('iban');
        });

        DB::table('employees')->orderBy('id')->chunkById(500, function ($employees): void {
            foreach ($employees as $employee) {
                DB::table('employees')->where('id', $employee->id)->update([
                    'personal_number_hash' => BlindIndex::personalNumber((int) $employee->business_id, $employee->personal_number),
                    'personal_number_encrypted' => $this->encrypt($employee->personal_number),
                    'iban_encrypted' => $this->encrypt($employee->iban),
                ]);
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_business_personal_number_unique');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['personal_number', 'iban']);
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->renameColumn('personal_number_encrypted', 'personal_number');
            $table->renameColumn('iban_encrypted', 'iban');
            $table->unique(['business_id', 'personal_number_hash'], 'employees_business_personal_number_hash_unique');
        });

        $this->maskAuditLogs();
    }

    /**
     * Reverse the migrations. Masked audit values cannot be restored.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('personal_number_plain', 20)->nullable();
            $table->string('iban_plain', 34)->nullable();
        });

        DB::table('employees')->orderBy('id')->chunkById(500, function ($employees): void {
            foreach ($employees as $employee) {
                DB::table('employees')->where('id', $employee->id)->update([
                    'personal_number_plain' => $employee->personal_number === null ? null : Crypt::decryptString($employee->personal_number),
                    'iban_plain' => $employee->iban === null ? null : Crypt::decryptString($employee->iban),
                ]);
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_business_personal_number_hash_unique');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['personal_number', 'iban', 'personal_number_hash']);
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->renameColumn('personal_number_plain', 'personal_number');
            $table->renameColumn('iban_plain', 'iban');
            $table->unique(['business_id', 'personal_number'], 'employees_business_personal_number_unique');
        });
    }

    private function encrypt(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString($value);
    }

    private function maskAuditLogs(): void
    {
        DB::table('audit_logs')->where('subject_type', 'App\\Models\\Employee')->orderBy('id')->chunkById(500, function ($logs): void {
            foreach ($logs as $log) {
                $changes = [];
                foreach (['old_values', 'new_values'] as $column) {
                    $values = $log->{$column} === null ? null : json_decode((string) $log->{$column}, true);
                    if (! is_array($values) || array_intersect(array_keys($values), self::SENSITIVE) === []) {
                        continue;
                    }
                    foreach (self::SENSITIVE as $key) {
                        if (array_key_exists($key, $values)) {
                            $values[$key] = $values[$key] === null || $values[$key] === '' ? $values[$key] : '••••'.mb_substr((string) $values[$key], -4);
                        }
                    }
                    $changes[$column] = json_encode($values, JSON_UNESCAPED_UNICODE);
                }
                if ($changes !== []) {
                    DB::table('audit_logs')->where('id', $log->id)->update($changes);
                }
            }
        });
    }
};
