<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_template_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference');
            $table->string('status')->default('ACTIVE');
            $table->timestampTz('business_start');
            $table->timestampTz('business_end');
            $table->timestampTz('occupied_start');
            $table->timestampTz('occupied_end');
            $table->timestampTz('expires_at');
            $table->unsignedBigInteger('allocation_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'status', 'expires_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('operation');
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamps();
            $table->unique(
                ['organization_id', 'operation', 'idempotency_key'],
                'idempotency_scope_unique',
            );
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->unsignedBigInteger('inventory_revision');
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'id']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('object_type');
            $table->unsignedBigInteger('object_id');
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'object_type', 'object_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('holds');
    }
};
