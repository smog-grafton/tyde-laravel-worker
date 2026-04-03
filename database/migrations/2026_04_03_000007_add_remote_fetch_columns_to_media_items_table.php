<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->string('source_disk', 100)->nullable()->change();
            $table->string('source_path')->nullable()->change();
            $table->string('source_url')->nullable()->after('source_path');
            $table->string('source_host')->nullable()->after('source_url');
            $table->string('fetch_status', 40)->default('not_applicable')->after('source_size_bytes');
            $table->unsignedTinyInteger('fetch_progress')->default(0)->after('fetch_status');
            $table->unsignedBigInteger('bytes_downloaded')->nullable()->after('fetch_progress');
            $table->unsignedBigInteger('bytes_total')->nullable()->after('bytes_downloaded');
            $table->timestamp('fetch_started_at')->nullable()->after('bytes_total');
            $table->timestamp('fetch_completed_at')->nullable()->after('fetch_started_at');

            $table->index(['fetch_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropIndex(['fetch_status', 'created_at']);
            $table->dropColumn([
                'source_url',
                'source_host',
                'fetch_status',
                'fetch_progress',
                'bytes_downloaded',
                'bytes_total',
                'fetch_started_at',
                'fetch_completed_at',
            ]);
            $table->string('source_disk', 100)->nullable(false)->change();
            $table->string('source_path')->nullable(false)->change();
        });
    }
};
