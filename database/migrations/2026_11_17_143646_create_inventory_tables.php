<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('staff');
            $t->boolean('active')->default(true);
        });
        Schema::create('medicines', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('generic_name');
            $t->string('brand_name')->nullable();
            $t->string('strength');
            $t->string('dosage_form');
            $t->string('manufacturer');
            $t->text('notes')->nullable();
            $t->unsignedInteger('low_stock_threshold')->default(10);
            $t->boolean('active')->default(true)->index();
            $t->boolean('simulation')->default(false)->index();
            $t->unsignedInteger('quantity')->nullable();
            $t->unsignedInteger('stock_revision')->default(0);
            $t->timestamps();
        });
        Schema::create('scales', function (Blueprint $t) {
            $t->id();
            $t->string('device_identifier')->unique();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->boolean('simulation')->default(false)->unique();
            $t->string('credential_hash')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->string('firmware_version')->nullable();
            $t->string('boot_id')->nullable();
            $t->bigInteger('last_sequence')->default(-1);
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('device_boots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('scale_id')->constrained()->restrictOnDelete();
            $t->string('boot_id');
            $t->timestamps();
            $t->unique(['scale_id', 'boot_id']);
        });
        Schema::create('calibration_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $t->foreignId('scale_id')->constrained()->restrictOnDelete();
            $t->string('calibration_session_id');
            $t->double('original_tare_raw');
            $t->double('one_piece_net');
            $t->double('ten_piece_net');
            $t->unsignedInteger('sample_quantity')->default(10);
            $t->double('raw_units_per_piece');
            $t->unsignedInteger('version');
            $t->boolean('active')->default(true);
            $t->foreignId('calibrated_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('calibrated_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['medicine_id', 'scale_id', 'version']);
        });
        DB::statement('CREATE UNIQUE INDEX profiles_one_active ON calibration_profiles (medicine_id, scale_id) WHERE active = 1');
        Schema::create('counting_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('scale_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('calibration_profile_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('calibration_session_id')->nullable();
            $t->string('boot_id')->nullable();
            $t->string('tare_revision')->nullable();
            $t->boolean('acknowledged')->default(false);
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('device_commands', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('scale_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('counting_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('calibration_profile_id')->constrained()->restrictOnDelete();
            $t->string('boot_id');
            $t->string('status')->default('pending');
            $t->text('error')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('previous_quantity')->nullable();
            $t->unsignedInteger('new_quantity');
            $t->integer('difference')->nullable();
            $t->string('source');
            $t->string('reason');
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('scale_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('calibration_profile_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('profile_version')->nullable();
            $t->string('boot_id')->nullable();
            $t->string('counting_session_id')->nullable();
            $t->string('calibration_session_id')->nullable();
            $t->string('tare_revision')->nullable();
            $t->bigInteger('reading_sequence')->nullable();
            $t->uuid('idempotency_key')->unique();
            $t->string('payload_hash');
            $t->timestamps();
        });
        Schema::create('application_settings', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('Dispensary');
            $t->unsignedInteger('stale_seconds')->default(10);
            $t->unsignedInteger('offline_seconds')->default(30);
        });
        DB::table('application_settings')->insert(['id' => 1]);
    }

    public function down(): void
    {
        foreach (['application_settings', 'inventory_counts', 'device_commands', 'counting_sessions', 'calibration_profiles', 'device_boots', 'scales', 'medicines'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active']));
    }
};
