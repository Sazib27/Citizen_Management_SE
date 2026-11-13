<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_payments', 'currency')) {
                $table->string('currency', 3)->default('BDT')->after('amount');
            }

            if (! Schema::hasColumn('tax_payments', 'gateway_response')) {
                $table->json('gateway_response')->nullable()->after('notes');
            }
        });

        /*
         * Database-level idempotency guard. The application already uses
         * firstOrCreate(), but without a unique index a concurrent IPN +
         * success callback pair can still insert duplicate payment rows.
         */
        Schema::table('tax_payments', function (Blueprint $table) {
            $table->unique(
                ['tax_assessment_id', 'reference', 'method'],
                'tax_payments_assessment_reference_method_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tax_payments', function (Blueprint $table) {
            $table->dropUnique('tax_payments_assessment_reference_method_unique');
        });

        Schema::table('tax_payments', function (Blueprint $table) {
            if (Schema::hasColumn('tax_payments', 'gateway_response')) {
                $table->dropColumn('gateway_response');
            }

            if (Schema::hasColumn('tax_payments', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
