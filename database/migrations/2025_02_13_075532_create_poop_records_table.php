<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('poop_records', function (Blueprint $table) {
            $table->id();
            $table->string('group_id')->nullable();
            $table->string('user_id');
            $table->string('user_name');
            $table->dateTime('record_date');
            $table->timestamps();

            $table->index(['user_id', 'record_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poop_records');
    }
};
