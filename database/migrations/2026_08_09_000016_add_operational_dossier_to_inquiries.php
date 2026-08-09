<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->string('contact_name')->nullable();
            $table->string('contact_method')->nullable();
            $table->string('contact_value')->nullable();
            $table->unsignedInteger('party_size')->nullable();
            $table->text('meeting_point')->nullable();
            $table->text('service_location')->nullable();
            $table->string('sales_source')->nullable();
            $table->string('agent_reference')->nullable();
            $table->text('service_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->char('selling_currency', 3)->nullable();
            $table->unsignedBigInteger('selling_amount_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropColumn([
                'contact_name',
                'contact_method',
                'contact_value',
                'party_size',
                'meeting_point',
                'service_location',
                'sales_source',
                'agent_reference',
                'service_notes',
                'internal_notes',
                'selling_currency',
                'selling_amount_minor',
            ]);
        });
    }
};
