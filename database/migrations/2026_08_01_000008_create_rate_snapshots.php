<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('source_reference');
            $table->char('currency', 3);
            $table->unsignedBigInteger('selling_amount_minor');
            $table->unsignedBigInteger('tax_amount_minor')->default(0);
            $table->unsignedBigInteger('commission_amount_minor')->default(0);
            $table->decimal('fx_rate', 20, 8)->nullable();
            $table->char('fx_base_currency', 3)->nullable();
            $table->char('fx_quote_currency', 3)->nullable();
            $table->timestampTz('quoted_at');
            $table->timestampTz('valid_until')->nullable();
            $table->char('canonical_hash', 64);
            $table->timestamps();
            $table->index(['organization_id', 'source_reference']);
            $table->index(['organization_id', 'canonical_hash']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('rate_snapshot_id')->nullable()->after('allocation_id')->constrained('rate_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rate_snapshot_id');
        });
        Schema::dropIfExists('rate_snapshots');
    }
};
