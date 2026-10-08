<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            /*
             * Legacy records were created before branch context became
             * mandatory. Assign those records to each business's
             * Head Office.
             *
             * Only records with a NULL branch_id are affected.
             * Existing branch assignments are never changed.
             */

            DB::statement('
                UPDATE sales
                SET branch_id = branches.id
                FROM branches
                WHERE sales.business_id = branches.business_id
                  AND branches.is_head_office = true
                  AND sales.branch_id IS NULL
            ');

            DB::statement('
                UPDATE expenses
                SET branch_id = branches.id
                FROM branches
                WHERE expenses.business_id = branches.business_id
                  AND branches.is_head_office = true
                  AND expenses.branch_id IS NULL
            ');
        });
    }

    public function down(): void
    {
        /*
         * We intentionally do not reverse this migration.
         *
         * Removing branch assignments from historical financial
         * records would destroy information and make rollback unsafe.
         */
    }
};