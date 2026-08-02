<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->timestampTz('actual_departed_at')->nullable()->after('planned_end');
            $table->timestampTz('actual_returned_at')->nullable()->after('actual_departed_at');
            $table->timestampTz('completed_at')->nullable()->after('actual_returned_at');
        });

        Schema::create('crew_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference');
            $table->string('display_name');
            $table->string('role');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crew_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crew_member_id')->constrained()->cascadeOnDelete();
            $table->string('duty');
            $table->timestamps();
            $table->unique(['trip_id', 'crew_member_id']);
        });

        Schema::create('trip_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->boolean('required')->default(true);
            $table->boolean('completed')->default(false);
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_checklists');
        Schema::dropIfExists('crew_assignments');
        Schema::dropIfExists('crew_members');

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn(['actual_departed_at', 'actual_returned_at', 'completed_at']);
        });
    }
};
