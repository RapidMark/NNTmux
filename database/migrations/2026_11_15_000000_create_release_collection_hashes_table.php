<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers which post (collection hash) each release was built from, so
     * headers that arrive after the release can be merged into its NZB.
     */
    public function up(): void
    {
        if (Schema::hasTable('release_collection_hashes')) {
            return;
        }

        Schema::create('release_collection_hashes', function (Blueprint $table): void {
            $table->binary('collectionhash', length: 20, fixed: true)->primary();
            $table->unsignedInteger('releases_id')->index();
            $table->timestamp('created_at')->nullable();
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_collection_hashes');
    }
};
