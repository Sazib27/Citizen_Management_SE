<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The original table only ever had `otp_hash`; there is no
         * plain `otp` column to drop. MySQL tolerated the unconditional
         * drop, but SQLite raises "no such column" and aborts the whole
         * migration run. Guard the drop so the migration is portable.
         */
        if (! Schema::hasColumn('email_verification_otps', 'otp')) {
            return;
        }

        Schema::table('email_verification_otps', function (Blueprint $table) {
            $table->dropColumn('otp');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_verification_otps', 'otp')) {
            return;
        }

        Schema::table('email_verification_otps', function (Blueprint $table) {
            $table->string('otp')->nullable();
        });
    }
};
