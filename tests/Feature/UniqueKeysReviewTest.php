<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Console\Commands\ImportFromWhmcs;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDO;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of PR #77 (DATA-11/12).
 */
class UniqueKeysReviewTest extends TestCase
{
    use RefreshDatabase;

    /** R1: the generated settings.scope column is loaded with every row; replicate() copies it into the INSERT. */
    public function test_duplicating_a_product_with_settings_copies_them(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
        $product = $this->createProduct()->product;
        $product->settings()->create(['key' => 'package', 'value' => 'basic']);

        $this->livewireAs($admin)->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('duplicate');

        $copy = $product->newQuery()->where('name', 'Copy of ' . $product->name)->firstOrFail();
        $this->assertSame('basic', $copy->settings()->where('key', 'package')->value('value'));
    }

    /** R2: WHMCS keeps every cancellation request; a service with two of them aborts the import. */
    public function test_the_importer_takes_a_service_with_two_whmcs_cancellation_requests(): void
    {
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => ($p = $this->createProduct())->plan->id,
            'product_id' => $p->product->id, 'currency_code' => 'USD', 'price' => 10,
        ]);
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $whmcs->exec('CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, relid INTEGER, reason TEXT, type TEXT, date TEXT)');
        $whmcs->exec("INSERT INTO tblcancelrequests VALUES (1, {$service->id}, 'first', 'Immediate', '2024-01-01 00:00:00'), (2, {$service->id}, 'again', 'End of Billing Period', '2025-01-01 00:00:00')");

        $command = $this->app->make(ImportFromWhmcs::class);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $ref = new ReflectionClass($command);
        $ref->getProperty('pdo')->setValue($command, $whmcs);
        $method = $ref->getMethod('importCancellations');

        $method->invoke($command);

        $this->assertSame(1, DB::table('service_cancellations')->where('service_id', $service->id)->count());
    }
}
