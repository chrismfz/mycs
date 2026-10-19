<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClientUser;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Tests\TestCase;

/** Code review of #217: the menu's categories everywhere, Contact only where it opens, the home page's queries. */
class GuestNavigationHomeReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
    }

    private function product(Category $category, string $name, int $amount): Product
    {
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => $name]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'y',
            'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $amount, 'currency_code' => 'USD']);

        return $product;
    }

    public function test_a_category_off_the_menu_is_not_listed_on_another_category_page(): void
    {
        $visible = Category::factory()->create(['name' => 'Visible Cat', 'slug' => 'visible', 'show_in_menu' => true]);
        $hidden = Category::factory()->create(['name' => 'Secret Cat', 'slug' => 'secret', 'show_in_menu' => false]);
        $this->product($visible, 'A', 10);
        $this->product($hidden, 'B', 20);

        $this->get('/products/visible')->assertOk()->assertDontSee('Secret Cat');
    }

    public function test_contact_is_not_offered_to_a_login_without_tickets(): void
    {
        $client = User::factory()->create()->client();
        $user = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'permissions' => ['invoices'], 'granted_at' => now()]);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));

        $this->get(route('tickets.create'))->assertForbidden();
        Once::flush();
        $this->get(route('invoices'))->assertOk()->assertDontSee(route('tickets.create'), false);
    }

    public function test_home_queries_do_not_grow_with_products(): void
    {
        $counts = [];
        foreach ([2, 8] as $n) {
            for ($c = 0; $c < $n; $c++) {
                $cat = Category::factory()->create(['show_in_menu' => true]);
                for ($p = 0; $p < 3; $p++) {
                    $this->product($cat, "P$c$p", 10 + $p);
                }
            }
            Once::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/')->assertOk();
            $counts[$n] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertLessThan($counts[2] + 5, $counts[8], json_encode($counts));
    }
}
