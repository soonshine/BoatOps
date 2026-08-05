<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_reference');
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('posting_kind');
            $table->string('direction');
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('description');
            $table->string('status')->default('POSTED');
            $table->foreignId('reversal_of_posting_id')->nullable()->constrained('cash_postings')->restrictOnDelete();
            $table->foreignId('recorded_by_api_client_id')->nullable()->constrained('api_clients')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference'], 'cash_posting_external_unique');
            $table->unique(['organization_id', 'source_type', 'source_id'], 'cash_posting_source_unique');
            $table->unique(['organization_id', 'reversal_of_posting_id'], 'cash_posting_reversal_unique');
            $table->index(['organization_id', 'cash_account_id', 'occurred_at'], 'cash_posting_activity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_postings');
    }
};
