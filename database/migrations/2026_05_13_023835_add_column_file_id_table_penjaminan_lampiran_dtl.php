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
        Schema::table('penjaminan_lampiran_dtl', function (Blueprint $table) {
            $table->char('file_id', 36)
                  ->nullable()
                  ->after('trx_no');
            $table->foreign('file_id')
                  ->references('id')
                  ->on('files')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('penjaminan_lampiran_dtl', function (Blueprint $table) {
            $table->dropForeign(['file_id']);
            $table->dropColumn('file_id');
        });
    }
};
