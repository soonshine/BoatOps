<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_reference');
            $table->string('name');
            $table->string('account_type');
            $table->char('currency', 3);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('cost_scope');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_reference');
            $table->string('name');
            $table->string('category');
            $table->string('unit');
            $table->char('currency', 3);
            $table->decimal('minimum_stock_quantity', 14, 3)->default(0);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'status', 'category']);
        });

        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('location_key');
            $table->string('location_type');
            $table->foreignId('boat_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('average_unit_cost_minor', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'item_id', 'location_key'], 'stock_balance_location_unique');
            $table->index(['organization_id', 'boat_id']);
        });

        Schema::create('fuel_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_reference');
            $table->foreignId('boat_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->string('station_name');
            $table->decimal('liters', 12, 3);
            $table->unsignedBigInteger('price_per_liter_minor');
            $table->unsignedBigInteger('total_amount_minor');
            $table->char('currency', 3);
            $table->decimal('fuel_level_before_percent', 5, 2)->nullable();
            $table->decimal('fuel_level_after_percent', 5, 2)->nullable();
            $table->decimal('engine_hours', 12, 2)->nullable();
            $table->string('handled_by');
            $table->string('receipt_reference', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('POSTED');
            $table->foreignId('recorded_by_api_client_id')->nullable()->constrained('api_clients')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'boat_id', 'occurred_at']);
            $table->index(['organization_id', 'trip_id']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_reference');
            $table->foreignId('boat_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('total_amount_minor');
            $table->char('currency', 3);
            $table->string('handled_by');
            $table->string('receipt_reference', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('POSTED');
            $table->foreignId('recorded_by_api_client_id')->nullable()->constrained('api_clients')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'boat_id', 'occurred_at']);
            $table->index(['organization_id', 'trip_id']);
        });

        Schema::create('expense_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
            $table->index(['organization_id', 'expense_category_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_reference');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('boat_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('movement_type');
            $table->timestampTz('occurred_at');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost_minor', 18, 6);
            $table->unsignedBigInteger('total_cost_amount_minor');
            $table->char('currency', 3);
            $table->string('from_location_key')->nullable();
            $table->string('to_location_key')->nullable();
            $table->string('handled_by');
            $table->string('receipt_reference', 500)->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('POSTED');
            $table->foreignId('recorded_by_api_client_id')->nullable()->constrained('api_clients')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'item_id', 'occurred_at']);
            $table->index(['organization_id', 'trip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('fuel_logs');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('items');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('cash_accounts');
    }
};
