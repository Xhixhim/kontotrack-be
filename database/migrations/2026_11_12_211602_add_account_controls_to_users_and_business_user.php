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
            $table->timestamp('deactivated_at')->nullable()->after('is_system_admin');
        });

        Schema::table('business_user', function (Blueprint $table) {
            // Abilities the owner has taken away from this member on top of what the role allows.
            $table->json('restrictions')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_user', function (Blueprint $table) {
            $table->dropColumn('restrictions');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
