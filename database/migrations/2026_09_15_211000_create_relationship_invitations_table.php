<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['relationship_id', 'accepted_at', 'canceled_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_invitations');
    }
};
