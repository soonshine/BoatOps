<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_memberships', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('INACTIVE');
            $t->boolean('can_calendar_read')->default(false);
            $t->boolean('can_booking_workflow')->default(false);
            $t->boolean('can_block')->default(false);
            $t->timestamps();
            $t->unique('user_id');
            $t->index(['organization_id', 'status']);
        });
        Schema::create('inquiries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('reference');
            $t->string('status')->default('INQUIRY');
            $t->foreignId('boat_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('trip_template_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('slot_offering_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('service_date')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'reference']);
            $t->index(['organization_id', 'status', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
        Schema::dropIfExists('operator_memberships');
    }
};
