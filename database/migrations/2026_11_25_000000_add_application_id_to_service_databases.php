<?php

use App\Models\ServiceDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
            $table->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['application_id', 'name']);
        });
    }

    public function down(): void
    {
        // A rollback must not silently discard database schedules created for applications.
        if (ServiceDatabase::withTrashed()->whereNotNull('application_id')->exists()) {
            throw new RuntimeException('Remove application-owned service databases before rolling back.');
        }

        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropUnique(['application_id', 'name']);
            $table->dropConstrainedForeignId('application_id');
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
