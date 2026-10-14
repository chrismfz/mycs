<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The extensions' namespace is MyCS\Extensions (2026-10-14): class names stored before the rename (audits, properties,
 * custom fields) follow, so the extensions' records still find their class; the rest is left as it is.
 */
class ExtensionsNamespaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stored_extension_class_names_follow_the_new_namespace(): void
    {
        $old = 'Paymenter' . '\\Extensions\\Others\\Affiliates\\Models\\Affiliate';
        $id = DB::table('audits')->insertGetId(['event' => 'created', 'auditable_type' => $old, 'auditable_id' => 1,
            'user_type' => 'App\\Models\\Staff', 'user_id' => 1, 'old_values' => '[]', 'new_values' => '[]']);
        DB::table('custom_properties')->insert(['key' => 'k', 'name' => 'K', 'model' => $old, 'type' => 'string',
            'non_editable' => false, 'required' => false, 'show_on_invoice' => false]);
        $migration = require database_path('migrations/2026_10_31_000000_extensions_namespace_mycs.php');

        $migration->up();

        $this->assertSame('MyCS\\Extensions\\Others\\Affiliates\\Models\\Affiliate', DB::table('audits')->where('id', $id)->value('auditable_type'));
        $this->assertSame('App\\Models\\Staff', DB::table('audits')->where('id', $id)->value('user_type'), 'other class names are left as they are');
        $this->assertSame('MyCS\\Extensions\\Others\\Affiliates\\Models\\Affiliate', DB::table('custom_properties')->where('key', 'k')->value('model'));
        $this->assertTrue(class_exists(DB::table('audits')->where('id', $id)->value('auditable_type')));

        $migration->down();
        $this->assertSame($old, DB::table('audits')->where('id', $id)->value('auditable_type'));
    }
}
