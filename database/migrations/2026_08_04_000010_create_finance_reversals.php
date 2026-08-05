<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('reversal_of_movement_id')->nullable()->after('status')
                ->constrained('stock_movements')->restrictOnDelete();
            $table->unique(['organization_id', 'reversal_of_movement_id'], 'stock_reversal_compensation_unique');
        });

        Schema::create('finance_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_reference');
            $table->string('original_record_type');
            $table->unsignedBigInteger('original_record_id');
            $table->text('reason');
            $table->foreignId('reversed_by_api_client_id')->constrained('api_clients')->restrictOnDelete();
            $table->timestampTz('reversed_at');
            $table->foreignId('compensating_stock_movement_id')->nullable()
                ->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->unique(
                ['organization_id', 'original_record_type', 'original_record_id'],
                'finance_reversal_original_unique'
            );
            $table->index(['organization_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reversals');
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique('stock_reversal_compensation_unique');
            $table->dropForeign(['reversal_of_movement_id']);
            $table->dropColumn('reversal_of_movement_id');
        });
    }
};
