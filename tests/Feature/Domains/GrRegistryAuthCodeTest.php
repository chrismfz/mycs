<?php

namespace Tests\Feature\Domains;

use App\Admin\Pages\GrRegistry;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The auth code reset mail (DACoR) from the .gr Registry page, for any .gr name: a customer moving one to us from another
 * registrar is not in our database yet (owner 2026-11-14). Free; «manage domains»; logged without a domain row.
 */
class GrRegistryAuthCodeTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'Μητρώο .gr', 'is_active' => true, 'mode' => 'production']);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function registrar(): MockInterface
    {
        $registrar = Mockery::mock(GrEppRegistrar::class);
        $factory = Mockery::mock(DomainRegistrarFactory::class);
        $factory->shouldReceive('for')->andReturn($registrar);
        $factory->shouldReceive('credentialsFor')->andReturn(new DomainRegistrarCredentials([]));
        $this->app->instance(DomainRegistrarFactory::class, $factory);

        return $registrar;
    }

    public function test_a_name_not_in_our_database_gets_the_mail_and_it_is_logged(): void
    {
        $staff = $this->staff();
        $this->registrar()->shouldReceive('emailAuthCode')->once()
            ->withArgs(fn (Domain $domain) => $domain->fqdn === 'elsewhere.gr' && !$domain->exists)
            ->andReturn('Email sent to a***@b***.gr');

        Livewire::test(GrRegistry::class)->set('authName', ' https://www.ElseWhere.gr/ ')->call('emailAuthCode')
            ->assertSet('authError', null)->assertSet('authResult', 'elsewhere.gr: Email sent to a***@b***.gr')->assertSet('authName', '')
            ->assertSee(__('domains.gr_registry.auth_code'));

        $this->assertSame(0, Domain::query()->count(), 'no domain row made');
        $log = DomainRegistrarLog::query()->sole();
        $this->assertNull($log->domain_id);
        $this->assertSame(['auth_code_email', 'ok', $staff->id, $this->gr->id, ['fqdn' => 'elsewhere.gr']],
            [$log->action, $log->status, $log->staff_id, $log->registrar_connection_id, $log->request]);
    }

    public function test_the_registry_refusing_it_is_shown_and_logged(): void
    {
        $this->staff();
        $this->registrar()->shouldReceive('emailAuthCode')->once()->andThrow(new RegistrarRefused('2303 Object does not exist'));

        Livewire::test(GrRegistry::class)->set('authName', 'nothere.gr')->call('emailAuthCode')
            ->assertSet('authResult', null)->assertSet('authError', '2303 Object does not exist');

        $this->assertSame(['failed', '2303 Object does not exist'], [DomainRegistrarLog::query()->sole()->status, DomainRegistrarLog::query()->sole()->error]);
    }

    public function test_one_mail_per_name_per_ten_minutes(): void
    {
        $this->staff();
        $this->registrar()->shouldReceive('emailAuthCode')->twice()->andReturn('sent');

        $page = Livewire::test(GrRegistry::class);
        $page->set('authName', 'twice.gr')->call('emailAuthCode')->assertSet('authResult', 'twice.gr: sent');
        $page->set('authName', 'twice.gr')->call('emailAuthCode')
            ->assertSet('authResult', null)->assertSet('authError', __('domains.gr_registry.auth_code_again', ['domain' => 'twice.gr']));
        $page->set('authName', 'other.gr')->call('emailAuthCode')->assertSet('authResult', 'other.gr: sent');

        $this->travel(11)->minutes();
        $this->registrar()->shouldReceive('emailAuthCode')->once()->andReturn('sent');
        Livewire::test(GrRegistry::class)->set('authName', 'twice.gr')->call('emailAuthCode')->assertSet('authResult', 'twice.gr: sent');
    }

    /** Review of #856: its punycode or another Unicode form is the same name, one mail, logged in one spelling. */
    public function test_one_name_in_any_spelling_is_one_mail(): void
    {
        $this->staff();
        $this->registrar()->shouldReceive('emailAuthCode')->once()->withArgs(fn (Domain $domain) => $domain->fqdn === 'δοκιμή.ελ')->andReturn('sent');

        $page = Livewire::test(GrRegistry::class);
        $page->set('authName', 'xn--jxalpdlp.xn--qxam')->call('emailAuthCode')->assertSet('authResult', 'δοκιμή.ελ: sent');
        foreach (['δοκιμή.ελ', \Normalizer::normalize('δοκιμή.ελ', \Normalizer::FORM_D), 'ΔΟΚΙΜΉ.ΕΛ.'] as $same) {
            $page->set('authName', $same)->call('emailAuthCode')->assertSet('authResult', null)
                ->assertSet('authError', __('domains.gr_registry.auth_code_again', ['domain' => 'δοκιμή.ελ']));
        }
        $this->assertSame(['fqdn' => 'δοκιμή.ελ'], DomainRegistrarLog::query()->sole()->request);
    }

    public function test_only_one_dot_gr_name_is_sent(): void
    {
        $this->staff();
        $this->registrar()->shouldNotReceive('emailAuthCode');

        foreach (['', 'example.com', 'one.gr two.gr', 'one.gr,two.gr'] as $typed) {
            Livewire::test(GrRegistry::class)->set('authName', $typed)->call('emailAuthCode')
                ->assertSet('authError', __('domains.gr_registry.auth_code_one'));
        }
        $this->assertSame(0, DomainRegistrarLog::query()->count());
    }

    public function test_it_needs_manage_and_a_usable_connection(): void
    {
        $this->staff(['admin.domains.viewAny']);
        $this->registrar()->shouldNotReceive('emailAuthCode');
        Livewire::test(GrRegistry::class)->assertDontSee(__('domains.gr_registry.auth_code_send'))
            ->set('authName', 'elsewhere.gr')->call('emailAuthCode')->assertForbidden();

        $this->staff();
        $this->gr->update(['is_active' => false]);
        Livewire::test(GrRegistry::class)->set('authName', 'elsewhere.gr')->call('emailAuthCode')
            ->assertSet('authError', __('domains.gr_registry.no_connection'));
        $this->assertSame(0, DomainRegistrarLog::query()->count());
    }
}
