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
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->string('uuid', 36)->unique()->change(); // Ensure the column
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->string('uuid', 36)->unique()->change(); // Ensure the column
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->uuid('uuid')->change(); // Revert to UUID type
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->uuid('uuid')->change(); // Revert to UUID type
        });
    }
};
