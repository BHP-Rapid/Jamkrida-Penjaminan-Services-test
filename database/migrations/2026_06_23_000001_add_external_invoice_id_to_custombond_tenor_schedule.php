<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custombond_tenor_schedule', function (Blueprint $table) {
            $table->uuid('external_invoice_id')
                ->nullable()
                ->after('cstb_schedule_id');

            $table->unique(
                'external_invoice_id',
                'uq_cstb_schedule_external_invoice'
            );

            $table->index(
                ['id_bond', 'invoice_number'],
                'idx_cstb_schedule_bond_invoice'
            );
        });
    }

    public function down(): void
    {
        Schema::table('custombond_tenor_schedule', function (Blueprint $table) {
            $table->dropUnique('uq_cstb_schedule_external_invoice');
            $table->dropIndex('idx_cstb_schedule_bond_invoice');
            $table->dropColumn('external_invoice_id');
        });
    }
};
