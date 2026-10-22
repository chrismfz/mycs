<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Admin\Resources\CategoryResource\Pages\CreateCategory;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Classes\FilamentInput;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A file field keeps what was uploaded, under a name the web server never runs, and nothing a browser names. */
class UploadHandlingTest extends TestCase
{
    use RefreshDatabase;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD',
            'settings.system_email_address' => 'billing@example.test']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    /** @return array{Ticket, User} */
    private function ticket(): array
    {
        $user = User::factory()->create();

        return [Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'S', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']), $user];
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    public function test_a_reply_cannot_attach_a_file_from_elsewhere_on_the_disk(): void
    {
        [$ticket] = $this->ticket();
        $this->actingAs($this->owner, 'staff');

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->set('data.message', 'see attached')
            ->set('data.attachments', ['x' => '../../.env'])
            ->set('data.attachment_names', ['../../.env' => 'env.txt'])
            ->call('note')
            ->assertHasErrors();

        $this->assertSame(0, TicketMessageAttachment::query()->count());
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_a_stored_path_outside_the_tickets_folder_is_never_served(): void
    {
        [$ticket, $user] = $this->ticket();
        $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'p']);
        $file = $message->attachments()->create(['path' => '../.env.example', 'filename' => 'e.txt', 'mime_type' => 'text/plain', 'filesize' => 1]);

        $this->assertNull($file->localPath);
        $this->withSession($this->loginUser($user))->get(route('tickets.attachments.show', $file->uuid))->assertNotFound();
    }

    public function test_a_real_attachment_is_still_served(): void
    {
        [$ticket, $user] = $this->ticket();
        $path = 'tickets/uploads/probe-' . uniqid() . '.txt';
        @mkdir(storage_path('app/tickets/uploads'), 0777, true);
        file_put_contents(storage_path('app/' . $path), 'hello');
        $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'p']);
        $file = $message->attachments()->create(['path' => $path, 'filename' => 'h.txt', 'mime_type' => 'text/plain', 'filesize' => 5]);

        $this->withSession($this->loginUser($user))->get(route('tickets.attachments.show', $file->uuid))->assertOk();
        @unlink(storage_path('app/' . $path));
    }

    /**
     * What a browser may claim a file is called, with what the file is (its content's type): the stored name is a slug
     * with that type's extension. A real upload's type comes from its content; a test's fake one from its name, so the
     * name is checked here directly.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function claimedNames(): array
    {
        return [
            'an .htaccess' => ['.htaccess', 'image/png', 'file.png'],
            'a second extension' => ['shell.php.png', 'image/png', 'shellphp.png'],
            'a handler Apache may run' => ['shell.pht', 'image/gif', 'shell.gif'],
            'a page' => ['page.html', 'image/svg+xml', 'page.svg'],
            'a path' => ['../../public/x.png', 'image/png', 'x.png'],
            'no name' => ['.png', 'image/png', 'file.png'],
            'its own jpeg spelling' => ['photo.JPEG', 'image/jpeg', 'photo.jpeg'],
            'a type we do not serve' => ['run.php', 'text/x-php', 'run.bin'],
        ];
    }

    #[DataProvider('claimedNames')]
    public function test_a_theme_file_is_named_by_what_it_is(string $claimed, string $type, string $stored): void
    {
        $this->assertSame($stored, FilamentInput::storedFileName($claimed, $type));
    }

    public function test_a_disguised_theme_file_never_lands_under_its_own_name(): void
    {
        Storage::fake('public');

        foreach (['.htaccess', 'shell.php.png', 'x.phtml', 'page.html'] as $name) {
            $this->livewireAs($this->owner)->test(Settings::class)
                ->set('data.theme_assets', [UploadedFile::fake()->createWithContent($name, $this->png())])
                ->call('save');
        }

        foreach (Storage::disk('public')->allFiles() as $file) {
            $this->assertMatchesRegularExpression('~\Abrand/[a-z0-9][a-z0-9-]*\.(png|gif|jpe?g|webp|svg|woff2?|ico|bin)\z~', $file);
        }
    }

    public function test_a_theme_files_readable_name_is_kept(): void
    {
        Storage::fake('public');

        $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.theme_assets', [UploadedFile::fake()->createWithContent('Hero Image.PNG', $this->png())])
            ->call('save')
            ->assertHasNoErrors();

        Storage::disk('public')->assertExists('brand/hero-image.png');
    }

    public function test_a_setting_cannot_be_pointed_at_another_file(): void
    {
        Storage::fake('public');

        foreach (['../../.env', 'other/secret.png', 'brand/../../.env'] as $forged) {
            $this->livewireAs($this->owner)->test(Settings::class)
                ->set('data.theme_assets', ['x' => $forged])
                ->call('save')
                ->assertHasErrors();
        }

        $stored = (string) json_encode(Setting::query()->whereNull('settingable_type')->where('key', 'theme_assets')->value('value'));
        $this->assertStringNotContainsString('.env', $stored);
        $this->assertStringNotContainsString('secret', $stored);
    }

    public function test_a_product_image_is_never_an_svg(): void
    {
        Storage::fake('public');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->livewireAs($this->owner)->test(CreateCategory::class)
            ->fillForm(['name' => 'C', 'slug' => 'c', 'image' => $svg])
            ->call('create')
            ->assertHasFormErrors(['image']);
    }
}
