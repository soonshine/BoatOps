<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->foreignId('hold_id')->nullable()->constrained('holds')->restrictOnDelete()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropForeign(['hold_id']);
            $table->dropColumn('hold_id');
        });

        Schema::dropIfExists('organization_settings');
    }
};
