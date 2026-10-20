<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets, PR A1 (docs/tickets.md §5): departments as a table (they get their own mailbox in A2), guest tickets (no
 * account: a name and an email), the number customers see (`reference`), five statuses, and what an email message
 * will carry. Nothing creates a guest ticket yet: the contact form (PR B) and the mail (A2) will.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('hidden')->default(false);
            // WHMCS «Clients only»: no guest tickets in it.
            $table->boolean('clients_only')->default(false);
            $table->string('email')->nullable();
            $table->unsignedInteger('whmcs_id')->nullable()->unique();
            $table->timestamps();
        });

        // The departments of the old setting (a list of names) and any other name a ticket carries, in that order.
        $names = [];
        $setting = DB::table('settings')->where('key', 'ticket_departments')->whereNull('settingable_type')->value('value');
        foreach ((array) json_decode((string) $setting, true) as $name) {
            $names[] = trim((string) $name);
        }
        foreach (DB::table('tickets')->whereNotNull('department')->distinct()->pluck('department') as $name) {
            $names[] = trim((string) $name);
        }
        // One department per name, whatever its case («support» and «Support»): the first spelling met.
        $unique = [];
        foreach ($names as $name) {
            if ($name !== '') {
                $unique[mb_strtolower($name)] ??= $name;
            }
        }
        $names = array_values($unique);
        // A new install had them as the setting's default, never stored: the same two.
        if ($names === []) {
            $names = ['Support', 'Sales'];
        }
        foreach ($names as $sort => $name) {
            DB::table('ticket_departments')->insert(['name' => $name, 'sort' => $sort, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('priority')->constrained('ticket_departments')->nullOnDelete();
            // A guest's ticket has no account (§3 Δ): who wrote, as they wrote it.
            $table->string('guest_name')->nullable()->after('client_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            // The number the customer sees (§5): WHMCS's `tid` for imported ones. Never a thread key (§3 Α).
            $table->string('reference', 64)->nullable()->unique()->after('id');
            $table->string('opened_via', 16)->nullable();
        });
        // Each name as tickets carry it (spaces, case) to the department made from it.
        $ids = DB::table('ticket_departments')->pluck('id', 'name')->mapWithKeys(fn (int $id, string $name) => [mb_strtolower($name) => $id]);
        foreach (DB::table('tickets')->whereNotNull('department')->distinct()->pluck('department') as $carried) {
            if ($id = $ids[mb_strtolower(trim((string) $carried))] ?? null) {
                DB::table('tickets')->where('department', $carried)->whereNull('department_id')->update(['department_id' => $id]);
            }
        }
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('department');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });

        // The tickets so far keep the number they had (#id).
        DB::table('tickets')->whereNull('reference')->update(['reference' => DB::raw('id')]);
        // Five statuses (§5): «replied» is «answered»; the priority the forms never offered is «medium».
        DB::table('tickets')->where('status', 'replied')->update(['status' => 'answered']);
        DB::table('tickets')->where('priority', 'normal')->update(['priority' => 'medium']);

        Schema::table('ticket_messages', function (Blueprint $table) {
            // The Message-ID of the email it came in or went out as (A2/A3): a thread key only when it is ours (§3 Α).
            $table->string('email_message_id')->nullable()->unique();
            $table->string('via', 16)->nullable();
            // A staff note: never shown to the customer, never sent.
            $table->boolean('is_internal')->default(false);
            // The email as it came, before the reply parser.
            $table->longText('body_original')->nullable();
        });

        DB::table('settings')->where('key', 'ticket_departments')->whereNull('settingable_type')->delete();

        // The mails name the ticket by its number, as the client area does (it was the id, which a new number is not).
        $this->replaceInTemplates('ticketMessage.ticket_id', 'ticketMessage.ticket.reference');
    }

    private function replaceInTemplates(string $from, string $to): void
    {
        foreach (DB::table('notification_templates')->get() as $template) {
            $changes = [];
            foreach (['subject', 'body', 'in_app_title', 'in_app_body', 'translations'] as $column) {
                $value = $template->{$column} ?? null;
                if (is_string($value) && str_contains($value, $from)) {
                    $changes[$column] = str_replace($from, $to, $value);
                }
            }
            if ($changes !== []) {
                DB::table('notification_templates')->where('id', $template->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropUnique(['email_message_id']);
            $table->dropColumn(['email_message_id', 'via', 'is_internal', 'body_original']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('department')->nullable();
        });
        foreach (DB::table('ticket_departments')->get(['id', 'name']) as $department) {
            DB::table('tickets')->where('department_id', $department->id)->update(['department' => $department->name]);
        }
        DB::table('settings')->updateOrInsert(['key' => 'ticket_departments', 'settingable_type' => null],
            ['value' => json_encode(DB::table('ticket_departments')->orderBy('sort')->pluck('name')->all()), 'type' => 'array']);
        DB::table('tickets')->where('status', 'answered')->update(['status' => 'replied']);
        DB::table('tickets')->whereIn('status', ['customer_reply', 'on_hold'])->update(['status' => 'open']);
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropUnique(['reference']);
            $table->dropColumn(['guest_name', 'guest_email', 'reference', 'opened_via']);
        });
        Schema::dropIfExists('ticket_departments');
        $this->replaceInTemplates('ticketMessage.ticket.reference', 'ticketMessage.ticket_id');
        // client_id stays nullable: a guest ticket could not go back to an account.
    }
};
