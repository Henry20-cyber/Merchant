<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE roles
            ALTER COLUMN team_id TYPE uuid
            USING team_id::text::uuid
        ");

        DB::statement("
            ALTER TABLE model_has_roles
            ALTER COLUMN team_id TYPE uuid
            USING team_id::text::uuid
        ");

        DB::statement("
            ALTER TABLE model_has_permissions
            ALTER COLUMN team_id TYPE uuid
            USING team_id::text::uuid
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE roles
            ALTER COLUMN team_id TYPE bigint
            USING team_id::bigint
        ");

        DB::statement("
            ALTER TABLE model_has_roles
            ALTER COLUMN team_id TYPE bigint
            USING team_id::bigint
        ");

        DB::statement("
            ALTER TABLE model_has_permissions
            ALTER COLUMN team_id TYPE bigint
            USING team_id::bigint
        ");
    }
};