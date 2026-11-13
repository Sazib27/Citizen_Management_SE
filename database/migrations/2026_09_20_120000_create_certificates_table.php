<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The public certificate number. Unlike the previous scheme
             * (which derived it from the user's id and therefore carried
             * no entropy) this is a random, unguessable code so that
             * knowing a user's id does not reveal their certificate.
             */
            $table->string('certificate_number', 32)->unique();

            $table->string('status')->default('valid');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();

            /*
             * A snapshot of the identity fields the certificate attests
             * to. The certificate must keep meaning the same thing even
             * if the citizen later edits their profile.
             */
            $table->string('holder_name');
            $table->string('nid_number', 10);
            $table->date('date_of_birth')->nullable();

            $table->foreignId('issued_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
