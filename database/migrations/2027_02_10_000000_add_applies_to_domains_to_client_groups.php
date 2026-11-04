<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client group's discount on its accounts' domains too (owner 2026-11-05): the renewal at its TLD's price less the
 * group's percentage, so a deal follows the catalog (trouvas's .gr at 25%: 19.00 → 14.25, and up with it). Off by default:
 * the groups so far discount services only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            $table->boolean('applies_to_domains')->default(false)->after('discount_percent');
        });
    }
};
