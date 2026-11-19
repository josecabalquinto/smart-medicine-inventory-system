<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_counts', function (Blueprint $t) {
            $t->string('count_method')->default('scale');
        });
        Schema::create('device_tares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('scale_id')->constrained()->restrictOnDelete();
            $t->uuid('tare_revision');
            $t->uuid('boot_id');
            $t->uuid('calibration_session_id');
            $t->double('tare_raw');
            $t->timestamps();
            $t->unique(['scale_id', 'tare_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tares');
        Schema::table('inventory_counts', fn (Blueprint $t) => $t->dropColumn('count_method'));
    }
};
