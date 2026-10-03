<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system_admin')->default(false)->after('password');
        });

        Schema::table('compliance_rule_sets', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('source_url')->constrained('users')->nullOnDelete();
        });

        Schema::create('compliance_rule_domains', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 8);
            $table->string('domain', 40);
            $table->timestamps();
            $table->unique(['jurisdiction', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compliance_rule_domains');

        Schema::table('compliance_rule_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_system_admin');
        });
    }
};
