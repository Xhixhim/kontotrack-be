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
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('last_sent_at')->nullable()->after('issued_at');
            $table->string('last_sent_to')->nullable()->after('last_sent_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true)->after('deactivated_at');
            // What the last reminder email contained, so an unchanged list is not emailed again every morning.
            $table->char('notification_digest_hash', 64)->nullable()->after('email_notifications');
            $table->timestamp('notification_digest_sent_at')->nullable()->after('notification_digest_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_notifications', 'notification_digest_hash', 'notification_digest_sent_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['last_sent_at', 'last_sent_to']);
        });
    }
};
