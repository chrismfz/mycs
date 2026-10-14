<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff apart from customers (docs/accounts.md, PR 1): their own table, guard and login; roles only there.
 *
 * Who had a role in `users` moves to `staff` with the same id, so what pointed at them (ticket replies, assigned
 * tickets, API keys, the actor of money events, audits) is moved by id. A former admin with customer data of their own
 * (services, invoices, credit…) keeps that customer account too: two identities, never one.
 */
return new class extends Migration
{
    private const CUSTOMER_TABLES = ['orders', 'services', 'invoices', 'tickets', 'credits', 'money_events', 'carts', 'billing_agreements'];

    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            // Disabled staff cannot log in; staff with history are disabled, not deleted (DATA-3).
            $table->boolean('active')->default(true);
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Not the customers' table: the same email may be both a customer and staff.
        Schema::create('staff_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $admins = DB::table('users')->whereNotNull('role_id')->pluck('id')->all();
        foreach (DB::table('users')->whereIn('id', $admins)->get() as $admin) {
            DB::table('staff')->insert([
                'id' => $admin->id,
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
                'email' => $admin->email,
                'password' => $admin->password,
                'role_id' => $admin->role_id,
                // The same TOTP secret, encrypted with the same key and cast.
                'app_authentication_secret' => $admin->tfa_secret,
                'created_at' => $admin->created_at,
                'updated_at' => $admin->updated_at,
            ]);
        }

        // Ticket replies: by staff = staff_id; by the customer = user_id.
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->after('user_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->change();
        });
        if ($admins !== []) {
            $staffReplies = DB::table('ticket_messages')->join('tickets', 'tickets.id', '=', 'ticket_messages.ticket_id')
                ->whereIn('ticket_messages.user_id', $admins)->whereColumn('ticket_messages.user_id', '!=', 'tickets.user_id')
                ->pluck('ticket_messages.id')->all();
            foreach (array_chunk($staffReplies, 500) as $ids) {
                DB::table('ticket_messages')->whereIn('id', $ids)->update(['staff_id' => DB::raw('user_id'), 'user_id' => null]);
            }
        }

        // Assigned to staff, not to a user (AUTH-14).
        DB::table('tickets')->whereNotNull('assigned_to')->whereNotIn('assigned_to', $admins)->update(['assigned_to' => null]);
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->foreign('assigned_to')->references('id')->on('staff')->nullOnDelete();
        });

        // An API key is the staff member's who made it, and goes with them.
        Schema::table('api_keys', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->after('token')->constrained('staff')->cascadeOnDelete();
        });
        DB::table('api_keys')->whereIn('user_id', $admins)->update(['staff_id' => DB::raw('user_id')]);
        // A key of a user without a role could not be used by them in the admin anyway: it goes.
        DB::table('api_keys')->whereNotNull('user_id')->whereNotIn('user_id', $admins)->delete();
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        if ($admins !== []) {
            // Who did it: the staff member now.
            DB::table('money_events')->where('actor_type', 'App\\Models\\User')->whereIn('actor_id', $admins)
                ->update(['actor_type' => 'App\\Models\\Staff']);
            DB::table('audits')->where('user_type', 'App\\Models\\User')->whereIn('user_id', $admins)
                ->update(['user_type' => 'App\\Models\\Staff']);

            // The admin's own user row goes, unless it is a customer account too.
            foreach ($admins as $id) {
                $isCustomer = collect(self::CUSTOMER_TABLES)->contains(fn (string $table) => Schema::hasTable($table)
                    && DB::table($table)->where('user_id', $id)->exists())
                    || DB::table('ticket_messages')->where('user_id', $id)->exists();
                if (!$isCustomer) {
                    DB::table('users')->where('id', $id)->delete();
                }
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id']);
            $table->dropColumn('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->index()->after('password');
        });
        foreach (DB::table('staff')->get() as $staff) {
            if (DB::table('users')->where('id', $staff->id)->exists()) {
                DB::table('users')->where('id', $staff->id)->update(['role_id' => $staff->role_id]);
            } elseif (!DB::table('users')->where('email', $staff->email)->exists()) {
                DB::table('users')->insert([
                    'id' => $staff->id, 'first_name' => $staff->first_name, 'last_name' => $staff->last_name,
                    'email' => $staff->email, 'password' => $staff->password, 'role_id' => $staff->role_id,
                    'tfa_secret' => $staff->app_authentication_secret, 'created_at' => $staff->created_at, 'updated_at' => $staff->updated_at,
                ]);
            }
        }
        DB::table('money_events')->where('actor_type', 'App\\Models\\Staff')->update(['actor_type' => 'App\\Models\\User']);
        DB::table('audits')->where('user_type', 'App\\Models\\Staff')->update(['user_type' => 'App\\Models\\User']);

        Schema::table('api_keys', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('token')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
        });
        DB::table('api_keys')->update(['user_id' => DB::raw('staff_id')]);
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->dropColumn('staff_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('ticket_messages')->whereNotNull('staff_id')->update(['user_id' => DB::raw('staff_id')]);
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->dropColumn('staff_id');
        });

        Schema::dropIfExists('staff_password_reset_tokens');
        Schema::dropIfExists('staff');
    }
};
