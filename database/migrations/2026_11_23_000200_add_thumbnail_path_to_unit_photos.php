<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2.2: ogni foto si salva in due misure WebP (larga 1600 px e miniatura 400 px). path resta la misura
 * larga, thumbnail_path la miniatura. La copertina è la foto in posizione 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_photos', function (Blueprint $table): void {
            $table->text('thumbnail_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unit_photos', function (Blueprint $table): void {
            $table->dropColumn('thumbnail_path');
        });
    }
};
