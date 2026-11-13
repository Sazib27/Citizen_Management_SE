<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_updates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('complaint_id')
                ->constrained('complaints')
                ->cascadeOnDelete();

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['complaint_id', 'created_at']);
        });

        Schema::table('complaints', function (Blueprint $table) {
            if (! Schema::hasColumn('complaints', 'rating')) {
                // Citizen satisfaction score, 1-5, set once the citizen
                // has read the resolution.
                $table->unsignedTinyInteger('rating')->nullable()->after('admin_reply');
            }

            if (! Schema::hasColumn('complaints', 'rated_at')) {
                $table->timestamp('rated_at')->nullable()->after('rating');
            }

            if (! Schema::hasColumn('complaints', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('resolved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rated_at', 'closed_at']);
        });

        Schema::dropIfExists('complaint_updates');
    }
};
