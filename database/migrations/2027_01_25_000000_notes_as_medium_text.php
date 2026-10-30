<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff's notes whole (#546, code review 4): an account's note and a service's notes as MEDIUMTEXT on MariaDB, so a WHMCS
 * note near TEXT's 64 KB, with what the import adds (its source, an addon's, the custom fields'), never fails the import
 * with «Data too long». SQLite's TEXT has no such limit (and changing a column there rebuilds the table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement('ALTER TABLE client_notes MODIFY body MEDIUMTEXT NOT NULL');
        DB::statement('ALTER TABLE services MODIFY notes MEDIUMTEXT NULL');
    }

    public function down(): void
    {
        if (!in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement('ALTER TABLE client_notes MODIFY body TEXT NOT NULL');
        DB::statement('ALTER TABLE services MODIFY notes TEXT NULL');
    }
};
