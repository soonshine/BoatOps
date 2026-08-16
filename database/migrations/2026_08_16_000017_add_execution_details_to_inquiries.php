<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->unsignedInteger('adult_count')->nullable();
            $table->unsignedInteger('child_count')->nullable();
            $table->json('child_ages')->nullable();
            $table->string('hotel_name')->nullable();
            $table->string('room_number')->nullable();
            $table->boolean('pickup_required')->nullable();
            $table->time('pickup_time')->nullable();
            $table->text('route_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropColumn([
                'adult_count',
                'child_count',
                'child_ages',
                'hotel_name',
                'room_number',
                'pickup_required',
                'pickup_time',
                'route_summary',
            ]);
        });
    }
};
