<?php

use App\Enum\PoopType;
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
        Schema::table('poop_records', function (Blueprint $table) {
            $table->string('poop_type')->default(PoopType::GoodPoop->value);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poop_records', function (Blueprint $table) {
            $table->dropColumn('poop_type');
        });
    }
};
