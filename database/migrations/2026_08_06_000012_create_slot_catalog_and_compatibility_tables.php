<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_offerings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_slot_offering_id')
                ->nullable()
                ->constrained('slot_offerings')
                ->restrictOnDelete();
            $table->string('kind');
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('DRAFT');
            $table->date('service_date')->nullable();
            $table->time('service_start_time');
            $table->time('service_end_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('additional_buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('additional_buffer_after_minutes')->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('applies_to_all_boats')->default(true);
            $table->foreignId('created_by_api_client_id')
                ->nullable()
                ->constrained('api_clients')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(
                ['organization_id', 'kind', 'status', 'service_date'],
                'slot_offerings_catalog_lookup',
            );
        });

        Schema::create('slot_offering_boats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slot_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['slot_offering_id', 'boat_id']);
            $table->index(['organization_id', 'boat_id']);
        });

        Schema::create('slot_compatibility_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('first_slot_offering_id')->constrained('slot_offerings')->cascadeOnDelete();
            $table->foreignId('second_slot_offering_id')->constrained('slot_offerings')->cascadeOnDelete();
            $table->string('pair_key');
            $table->string('policy');
            $table->string('reason')->nullable();
            $table->foreignId('created_by_api_client_id')
                ->nullable()
                ->constrained('api_clients')
                ->nullOnDelete();
            $table->foreignId('updated_by_api_client_id')
                ->nullable()
                ->constrained('api_clients')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'pair_key'], 'slot_compatibility_pair_unique');
            $table->index(
                ['organization_id', 'first_slot_offering_id', 'second_slot_offering_id'],
                'slot_compatibility_lookup',
            );
        });

        Schema::table('allocations', function (Blueprint $table): void {
            $table->foreignId('slot_offering_id')
                ->nullable()
                ->after('boat_id')
                ->constrained('slot_offerings')
                ->restrictOnDelete();
            $table->foreignId('custom_slot_instance_id')
                ->nullable()
                ->after('slot_offering_id')
                ->constrained('slot_offerings')
                ->restrictOnDelete();
            $table->date('service_date')->nullable()->after('status');
            $table->timestampTz('service_start')->nullable()->after('service_date');
            $table->timestampTz('service_end')->nullable()->after('service_start');
            $table->string('slot_code_snapshot')->nullable()->after('occupied_end');
            $table->string('slot_name_snapshot')->nullable()->after('slot_code_snapshot');
            $table->unsignedSmallInteger('slot_duration_minutes_snapshot')
                ->nullable()
                ->after('slot_name_snapshot');
            $table->index(
                ['organization_id', 'boat_id', 'service_date', 'status'],
                'allocations_slot_day_lookup',
            );
        });

        Schema::table('holds', function (Blueprint $table): void {
            $table->foreignId('slot_offering_id')
                ->nullable()
                ->after('trip_template_id')
                ->constrained('slot_offerings')
                ->restrictOnDelete();
            $table->foreignId('custom_slot_instance_id')
                ->nullable()
                ->after('slot_offering_id')
                ->constrained('slot_offerings')
                ->restrictOnDelete();
            $table->date('service_date')->nullable()->after('status');
            $table->timestampTz('service_start')->nullable()->after('service_date');
            $table->timestampTz('service_end')->nullable()->after('service_start');
            $table->string('slot_code_snapshot')->nullable()->after('occupied_end');
            $table->string('slot_name_snapshot')->nullable()->after('slot_code_snapshot');
            $table->unsignedSmallInteger('slot_duration_minutes_snapshot')
                ->nullable()
                ->after('slot_name_snapshot');
            $table->index(
                ['organization_id', 'boat_id', 'service_date', 'status'],
                'holds_slot_day_lookup',
            );
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('slot_offering_id')
                ->nullable()
                ->after('trip_template_id')
                ->constrained('slot_offerings')
                ->restrictOnDelete();
            $table->foreignId('custom_slot_instance_id')
                ->nullable()
                ->after('slot_offering_id')
                ->constrained('slot_offerings')
                ->restrictOnDelete();
            $table->date('service_date')->nullable()->after('status');
            $table->timestampTz('service_start')->nullable()->after('service_date');
            $table->timestampTz('service_end')->nullable()->after('service_start');
            $table->timestampTz('occupied_start')->nullable()->after('business_end');
            $table->timestampTz('occupied_end')->nullable()->after('occupied_start');
            $table->string('slot_code_snapshot')->nullable()->after('occupied_end');
            $table->string('slot_name_snapshot')->nullable()->after('slot_code_snapshot');
            $table->unsignedSmallInteger('slot_duration_minutes_snapshot')
                ->nullable()
                ->after('slot_name_snapshot');
            $table->index(
                ['organization_id', 'boat_id', 'service_date', 'status'],
                'bookings_slot_day_lookup',
            );
        });

        DB::table('allocations')->update([
            'service_start' => DB::raw('business_start'),
            'service_end' => DB::raw('business_end'),
        ]);
        DB::table('holds')->update([
            'service_start' => DB::raw('business_start'),
            'service_end' => DB::raw('business_end'),
        ]);
        DB::statement(<<<'SQL'
            UPDATE bookings
            SET service_start = business_start,
                service_end = business_end,
                occupied_start = (
                    SELECT allocations.occupied_start
                    FROM allocations
                    WHERE allocations.id = bookings.allocation_id
                ),
                occupied_end = (
                    SELECT allocations.occupied_end
                    FROM allocations
                    WHERE allocations.id = bookings.allocation_id
                )
        SQL);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_slot_day_lookup');
            $table->dropConstrainedForeignId('custom_slot_instance_id');
            $table->dropConstrainedForeignId('slot_offering_id');
            $table->dropColumn([
                'service_date',
                'service_start',
                'service_end',
                'occupied_start',
                'occupied_end',
                'slot_code_snapshot',
                'slot_name_snapshot',
                'slot_duration_minutes_snapshot',
            ]);
        });

        Schema::table('holds', function (Blueprint $table): void {
            $table->dropIndex('holds_slot_day_lookup');
            $table->dropConstrainedForeignId('custom_slot_instance_id');
            $table->dropConstrainedForeignId('slot_offering_id');
            $table->dropColumn([
                'service_date',
                'service_start',
                'service_end',
                'slot_code_snapshot',
                'slot_name_snapshot',
                'slot_duration_minutes_snapshot',
            ]);
        });

        Schema::table('allocations', function (Blueprint $table): void {
            $table->dropIndex('allocations_slot_day_lookup');
            $table->dropConstrainedForeignId('custom_slot_instance_id');
            $table->dropConstrainedForeignId('slot_offering_id');
            $table->dropColumn([
                'service_date',
                'service_start',
                'service_end',
                'slot_code_snapshot',
                'slot_name_snapshot',
                'slot_duration_minutes_snapshot',
            ]);
        });

        Schema::dropIfExists('slot_compatibility_rules');
        Schema::dropIfExists('slot_offering_boats');
        Schema::dropIfExists('slot_offerings');
    }
};
