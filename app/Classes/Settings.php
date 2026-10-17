<?php

namespace App\Classes;

use App\Admin\Actions\ResetColorsAction;
use App\Admin\Actions\SendTestEmailAction;
use App\Helpers\TemplateRenderer;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Rules\Cidr;
use App\Services\Tax\EuVat;
use App\Support\CurrentClient;
use DateTimeZone;
use Exception;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Minishlink\WebPush\VAPID;
use MyCS\Vies\VatCountry;

class Settings
{
    public static function settings()
    {
        try {
            // Only code is needed
            $currencies = once(function () {
                return Currency::pluck('code')->toArray();
            });
        } catch (Exception $e) {
            $currencies = [];
        }
        $settings = [
            // Split settings into groups (only used in the settings page for organization)
            'general' => [
                [
                    'name' => 'company_name',
                    'label' => 'Company Name',
                    'type' => 'text',
                    'override' => 'app.name',
                    'default' => 'MyCS',
                ],
                [
                    'name' => 'timezone',
                    'label' => 'Timezone',
                    'type' => 'select',
                    // Read timezones from PHP
                    'options' => DateTimeZone::listIdentifiers(DateTimeZone::ALL),
                    'default' => 'UTC',
                    'required' => true,
                    'override' => 'app.timezone',
                ],
                [
                    'name' => 'app_language',
                    'label' => 'Default Language',
                    'default' => 'en',
                    'type' => 'select',
                    // Read languages from resources/lang directory
                    // The ternary operator is only present for now. Since there are no lang files, it returns [], which breaks the frontend, so we return ['en']
                    'options' => self::getAvailableLanguages(),
                    'required' => true,
                    'validation' => 'in:' . implode(',', self::getAvailableLanguages()),
                    'override' => 'app.locale',
                ],
                [
                    // MyCS: the admin panel has its own language, so a Greek client area
                    // does not turn the admin into a Filament-Greek / app-English mix.
                    'name' => 'admin_language',
                    'label' => 'Admin Language',
                    'default' => 'en',
                    'type' => 'select',
                    'options' => self::getAvailableLanguages(),
                    'required' => true,
                    'validation' => 'in:' . implode(',', self::getAvailableLanguages()),
                ],
                [
                    'name' => 'allowed_languages',
                    'label' => 'Allowed Languages',
                    'type' => 'select',
                    'options' => array_combine(self::getAvailableLanguages(), array_map('strtoupper', self::getAvailableLanguages())),
                    'database_type' => 'array',
                    'multiple' => true,
                    'default' => self::getAvailableLanguages(),
                    'required' => true,
                ],
                [
                    'name' => 'app_url',
                    'label' => 'App URL',
                    'default' => 'http://localhost',
                    'type' => 'text',
                    'required' => true,
                    'validation' => 'url',
                    'override' => 'app.url',
                ],
                [
                    'name' => 'logo',
                    'label' => 'Logo (Light Mode)',
                    'type' => 'file',
                    'required' => false,
                    'accept' => ['image/*'],
                    'file_name' => 'logo-light.webp',
                    'description' => 'Upload a logo to be displayed on light backgrounds.',
                ],
                [
                    'name' => 'logo_dark',
                    'label' => 'Logo (Dark Mode)',
                    'type' => 'file',
                    'required' => false,
                    'accept' => ['image/*'],
                    'file_name' => 'logo-dark.webp',
                    'description' => 'Upload a logo to be displayed on dark backgrounds.',
                ],
                [
                    'name' => 'favicon',
                    'label' => 'Favicon',
                    'type' => 'file',
                    'required' => false,
                    'accept' => ['image/x-icon', 'image/png', 'image/svg+xml'],
                    'file_name' => 'favicon.ico',
                    'description' => 'Upload a .ico, .png, or .svg file to be used as the browser icon.',
                ],
                [
                    'name' => 'system_email_address',
                    'label' => 'System Email Address',
                    'type' => 'email',
                    'required' => true,
                    'description' => 'The email address used for system emails, such as CronJob failures, updates, etc.',
                ],
                [
                    'name' => 'tos',
                    'label' => 'Terms of Service',
                    'description' => 'URL to your terms of service. Leave blank to disable.',
                    'type' => 'text',
                    'required' => false,
                ],
            ],

            // Security (captcha, rate limiting, etc.)
            'security' => [
                [
                    'name' => 'captcha',
                    'label' => 'Captcha',
                    'type' => 'select',
                    'options' => [
                        'disabled' => 'Disabled',
                        'recaptcha-v2' => 'Google reCAPTCHA v2',
                        'recaptcha-v3' => 'Google reCAPTCHA v3',
                        'turnstile' => 'Cloudflare Turnstile',
                        'hcaptcha' => 'hCaptcha',
                    ],
                    'default' => 'disabled',
                    'live' => true,
                ],
                [
                    'name' => 'captcha_site_key',
                    'label' => 'Captcha Site Key',
                    'type' => 'text',
                    'required' => fn (Get $get) => $get('captcha') && $get('captcha') !== 'disabled',
                ],
                [
                    'name' => 'captcha_secret',
                    'label' => 'Captcha Secret',
                    'type' => 'text',
                    'encrypted' => true, // a secret: stored encrypted, never sent to the page (sweep §6 review R4)
                    'required' => fn (Get $get) => $get('captcha') && $get('captcha') !== 'disabled',
                ],

                [
                    'name' => 'trusted_proxies',
                    'label' => 'Trusted Proxies',
                    'type' => 'tags',
                    'database_type' => 'array',
                    'placeholder' => 'IP Addresses or CIDR (e.g. 1.1.1.1/32 or 2606:4700:4700::1111)',
                    'nested_validation' => [
                        new Cidr(allowWildCard: true),
                    ],
                ],
                [
                    'name' => 'session_validation',
                    'label' => 'Session Validation',
                    'type' => 'select',
                    'options' => [
                        'none' => 'None',
                        'ip_admin' => 'Lock session to IP address (Admin)',
                        'ip_client' => 'Lock session to IP address (Client)',
                        'ip_both' => 'Lock session to IP address (Admin & Client)',
                        'user_agent_admin' => 'Lock session to User Agent (Admin)',
                        'user_agent_client' => 'Lock session to User Agent (Client)',
                        'user_agent' => 'Lock session to User Agent (Admin & Client)',
                        'ip_user_agent_admin' => 'Lock session to IP address and User Agent (Admin)',
                        'ip_user_agent_client' => 'Lock session to IP address and User Agent (Client)',
                        'ip_user_agent_both' => 'Lock session to IP address and User Agent (Admin & Client)',
                    ],
                    'default' => 'none',
                ],
            ],

            'social-login' => [
                [
                    'name' => 'oauth_google',
                    'label' => 'Google Enabled',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'required' => false,
                ],
                [
                    'name' => 'oauth_google_client_id',
                    'label' => 'Google Client ID',
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_google_client_secret',
                    'label' => 'Google Client Secret',
                    'type' => 'text',
                    'encrypted' => true, // a secret: stored encrypted, never sent to the page (sweep §6 review R4)
                    'required' => false,
                ],
                [
                    'name' => 'oauth_github',
                    'label' => 'GitHub Enabled',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'required' => false,
                ],
                [
                    'name' => 'oauth_github_client_id',
                    'label' => 'Github Client ID',
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_github_client_secret',
                    'label' => 'Github Client Secret',
                    'type' => 'text',
                    'encrypted' => true, // a secret: stored encrypted, never sent to the page (sweep §6 review R4)
                    'required' => false,
                ],
                [
                    'name' => 'oauth_discord',
                    'label' => 'Discord Enabled',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'required' => false,
                ],
                [
                    'name' => 'oauth_discord_client_id',
                    'label' => 'Discord Client ID',
                    'type' => 'text',
                    'required' => false,
                ],
                [
                    'name' => 'oauth_discord_client_secret',
                    'label' => 'Discord Client Secret',
                    'type' => 'text',
                    'encrypted' => true, // a secret: stored encrypted, never sent to the page (sweep §6 review R4)
                    'required' => false,
                ],
            ],
            'tax' => [
                [
                    'name' => 'tax_enabled',
                    'label' => 'Tax Enabled',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                [
                    'name' => 'tax_type',
                    'label' => 'Tax Type',
                    'type' => 'select',
                    'options' => [
                        'inclusive' => 'Inclusive (Price includes tax)',
                        'exclusive' => 'Exclusive (Price does not include tax)',
                    ],
                    // Both companies' WHMCS are Exclusive (docs/decisions.md Δ1).
                    'default' => 'exclusive',
                ],
                // EU VAT (PR G, docs/eu-vat.md): nixpal on, home EE (decision 9); myip off.
                [
                    'name' => 'eu_vat_enabled',
                    'label' => 'EU VAT',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Customers outside the EU pay no VAT. EU businesses of another member state with a VAT number VIES finds valid pay no VAT (reverse charge). Until VIES answers, VAT is charged.',
                ],
                [
                    'name' => 'eu_vat_home_country',
                    'label' => 'EU VAT home country',
                    'type' => 'select',
                    'options' => array_intersect_key((array) config('app.countries'), array_flip(VatCountry::EU)),
                    'required' => false,
                    'description' => 'The member state the company is established in. Businesses there are never reverse charged; without it nobody is.',
                ],
                [
                    'name' => 'eu_vat_requester',
                    'label' => 'Own EU VAT number',
                    'type' => 'text',
                    'required' => false,
                    'description' => 'Sent to VIES with each check, so it answers with a consultation number: the proof the customer\'s number was valid.',
                ],
            ],
            'mail' => [
                // SMTP etc
                [
                    'name' => 'mail_disable',
                    'label' => 'Disable Mail',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'live' => true,
                    'default' => true,
                ],
                [
                    'name' => 'mail_must_verify',
                    'label' => 'Users must verify email before buying',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                [
                    'name' => 'mail_host',
                    'label' => 'Mail Host',
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.mailers.smtp.host',
                    'action' => SendTestEmailAction::class,
                ],
                [
                    'name' => 'mail_port',
                    'label' => 'Mail Port',
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.mailers.smtp.port',
                ],
                [
                    'name' => 'mail_username',
                    'label' => 'Mail Username',
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.mailers.smtp.username',
                ],
                [
                    'name' => 'mail_password',
                    'label' => 'Mail Password',
                    'type' => 'password',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'encrypted' => true,
                    'override' => 'mail.mailers.smtp.password',
                ],
                [
                    'name' => 'mail_encryption',
                    'label' => 'Mail Encryption',
                    'type' => 'select',
                    'options' => [
                        'tls' => 'TLS',
                        'ssl' => 'SSL',
                        null => 'None',
                    ],
                    'default' => 'tls',
                    'required' => false,
                    'override' => 'mail.mailers.smtp.encryption',
                ],
                [
                    'name' => 'mail_from_address',
                    'label' => 'Mail From Address',
                    'type' => 'email',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.from.address',
                ],
                [
                    'name' => 'mail_from_name',
                    'label' => 'Mail From Name',
                    'type' => 'text',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'override' => 'mail.from.name',
                ],

                // Theming
                [
                    'name' => 'mail_header',
                    'label' => 'Header',
                    'type' => 'markdown',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'default' => '',
                    'disable_toolbar' => true,
                    'description' => 'Template: {{ app.name }}, {{ app.url }}, {{ app.logo_url }}, {{ mail_css|raw }}, {% if app.logo_url %}…{% endif %}. No PHP or Blade.',
                    'validation' => [TemplateRenderer::rule()],
                ],
                [
                    'name' => 'mail_footer',
                    'label' => 'Footer',
                    'type' => 'markdown',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'default' => '',
                    'disable_toolbar' => true,
                    'description' => 'Template: {{ app.name }}, {{ app.year }}, {{ app.url }}; {% if locale == "el" %}…{% else %}…{% endif %} for the mail\'s language. No PHP or Blade.',
                    'validation' => [TemplateRenderer::rule()],
                ],
                [
                    'name' => 'mail_css',
                    'label' => 'Mail CSS',
                    'type' => 'markdown',
                    'required' => fn (Get $get) => !$get('mail_disable'),
                    'default' => '',
                    'disable_toolbar' => true,
                ],
            ],
            'tickets' => [
                [
                    'name' => 'tickets_disabled',
                    'label' => 'Disable Tickets',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Disable the ticket system. This will disable all client side ticket functionality, including the ability to create new tickets and view existing tickets.',
                ],
                [
                    'name' => 'ticket_departments',
                    'label' => 'Ticket Departments',
                    'type' => 'tags',
                    'default' => ['Support', 'Sales'],
                    'required' => true,
                    'database_type' => 'array',
                ],
                [
                    'name' => 'ticket_client_closing_disabled',
                    'label' => 'Disallow clients from closing tickets',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                // Email piping
                [
                    'name' => 'ticket_mail_piping',
                    'label' => 'Email Piping',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'live' => true,
                ],
                [
                    'name' => 'ticket_mail_host',
                    'label' => 'Email Host',
                    'type' => 'text',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                ],
                [
                    'name' => 'ticket_mail_port',
                    'label' => 'Email Port',
                    'type' => 'number',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                    'default' => 993,
                ],
                [
                    'name' => 'ticket_mail_email',
                    'label' => 'Email Address',
                    'type' => 'email',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                ],
                [
                    'name' => 'ticket_mail_password',
                    'label' => 'Email Password',
                    'type' => 'password',
                    'required' => fn (Get $get) => $get('ticket_mail_piping'),
                    'encrypted' => true,
                ],
            ],

            // The Automation tab: WHMCS's Automation Settings, its sections and wording (docs/settings-map.md). The
            // defaults are the pilot's (nixpal) WHMCS values.
            'cronjob' => [
                [
                    'name' => 'cronjob_time',
                    'section' => 'Scheduling',
                    'label' => 'Time of Day',
                    'type' => 'time',
                    'default' => '09:00',
                    'required' => true,
                    'description' => 'The time of day the daily automated actions run. Not between 03:00 and 04:00: on the days the clock changes that hour is skipped or repeated.',
                    // Europe/Athens changes the clock at 03:00/04:00 (audit sweep BIL-16).
                    'validation' => ['not_regex:/^03:/'],
                ],
                [
                    'name' => 'cronjob_suspend_enabled',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Enable Suspension',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Suspend services automatically when overdue.',
                ],
                [
                    'name' => 'cronjob_order_suspend',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Suspend Days',
                    'type' => 'number',
                    'default' => 8,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'The number of days after the service\'s next due date to wait before suspending it.',
                ],
                [
                    'name' => 'cronjob_suspend_requires_invoice',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Suspend only with an overdue invoice',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Not in WHMCS. Off: the due date alone suspends, as WHMCS. On: only when an open invoice of the service is overdue, and issued Suspend Days ago (a renewal billed late gets the same days); a service past due with no open invoice is reported to staff instead.',
                ],
                [
                    'name' => 'cronjob_terminate_enabled',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Enable Termination',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Terminate services suspended for non-payment automatically.',
                ],
                [
                    'name' => 'cronjob_order_terminate',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Termination Days',
                    'type' => 'number',
                    'default' => 60,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'The number of days after the due date to wait before terminating a service suspended for non-payment; its open invoices are cancelled (but see «Keep overdue invoices of ended services»).',
                ],
                [
                    'name' => 'cronjob_terminate_min_suspended_days',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Minimum days suspended before termination',
                    'type' => 'number',
                    'default' => 1,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'Not in WHMCS. Never terminated sooner after its suspension, whatever the due date (a hold that ended late, a date moved back, the cron down): the customer gets the suspension email first. 0 = as WHMCS.',
                ],
                [
                    'name' => 'cronjob_end_keeps_overdue',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Keep overdue invoices of ended services',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Off (as WHMCS today): terminating a service, or cancelling it at its customer\'s request, cancels its open invoices. On: an unpaid invoice overdue more than the days below stays unpaid, as a debt (its reminders go on; paid later, it does not revive the service); the rest are cancelled.',
                ],
                [
                    'name' => 'cronjob_end_keeps_overdue_days',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Kept when overdue more than (days)',
                    'type' => 'number',
                    'default' => 0,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'With «Keep overdue invoices of ended services» on: 0 keeps every overdue invoice.',
                ],
                [
                    'name' => 'cronjob_invoice',
                    'section' => 'Billing Settings',
                    'label' => 'Invoice Generation',
                    'type' => 'number',
                    'default' => 7,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'The number of days before the due date to generate the renewal invoice.',
                ],
                // Domains billed at all (D5b): on; off only while another system still bills them (a migration, D-12) or in
                // an emergency at a registrar.
                [
                    'name' => 'domain_billing_enabled',
                    'section' => 'Billing Settings',
                    'label' => 'Bill domain renewals',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Make the renewal invoices of domains with auto renew; on payment they are renewed at their registrar (a manual one: a Pending Module Action for staff). Turn off only while another system still bills the domains (a migration) or in an emergency.',
                ],
                // Domains (docs/domains.md D-14; WHMCS CreateDomainInvoiceDaysBefore): their own, empty = as the services.
                [
                    'name' => 'domain_invoice_days',
                    'section' => 'Billing Settings',
                    'label' => 'Domain Invoice Generation',
                    'type' => 'number',
                    'default' => 15,
                    'required' => false,
                    'min_value' => 0,
                    'description' => 'The number of days before a domain expires to generate its renewal invoice (default 15). Empty: as Invoice Generation.',
                ],
                // Expiry notices (D7, docs/domains.md §2b; WHMCS DomainRenewalNotices; ICANN ERRP ~1 month, ~1 week, after).
                [
                    'name' => 'domain_reminder_days',
                    'section' => 'Billing Settings',
                    'label' => 'Domain Expiry Notices',
                    'type' => 'text',
                    'default' => '30,7,1,-3',
                    'required' => false,
                    // At most a year either side (DomainReminders::MAX_DAYS, review R9).
                    // An array: a string rule is split on «|», and the pattern has alternatives.
                    'validation' => ['nullable', 'regex:/^\s*-?(36[0-5]|3[0-5]\d|[12]?\d{1,2})(\s*,\s*-?(36[0-5]|3[0-5]\d|[12]?\d{1,2}))*\s*$/'],
                    'description' => 'Days before a domain expires to send its expiry notice, comma separated; negative days are after it (while it can still be renewed). Empty to disable. Domains set not to renew get none; one whose next year is paid gets none.',
                ],
                // Saved payment methods (PR H, decision 5; WHMCS CCProcessDaysBefore, CCAttemptOnlyOnce, CCRetryEveryWeekFor).
                [
                    'name' => 'autocharge_days_before',
                    'section' => 'Billing Settings',
                    'label' => 'Charge saved payment methods days before due',
                    'type' => 'number',
                    'default' => 0,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'An open invoice of a customer with «Auto-charge» on is charged to its saved method this many days before its due date (0: on the due date, as WHMCS here).',
                ],
                [
                    'name' => 'autocharge_retry_days',
                    'section' => 'Billing Settings',
                    'label' => 'Retry a declined charge on',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'description' => 'Empty: every day after the due date until it is paid or its service is suspended. Or days after the due date, e.g. 1,3,5,7.',
                ],
                [
                    'name' => 'autocharge_max_failures',
                    'section' => 'Billing Settings',
                    'label' => 'Stop auto-charging after failures in a row',
                    'type' => 'number',
                    'default' => 0,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'Declined automatic charges in a row after which a saved method is no longer charged on its own, and staff are told (0: no limit). Kept, not removed: a success, or the customer paying with it, starts again.',
                ],
                [
                    'name' => 'cc_expiry_notice_day',
                    'section' => 'Billing Settings',
                    'label' => 'Expiry notice of saved payment methods on day',
                    'type' => 'number',
                    'default' => 25,
                    'required' => true,
                    'min_value' => 0,
                    'max_value' => 28,
                    'description' => 'From this day of the month, customers whose saved payment method expires at the end of the month are told so, once (0: never; WHMCS here: 25).',
                ],
                [
                    'name' => 'paypal_legacy_receivers',
                    'section' => 'Billing Settings',
                    'label' => 'PayPal accounts of the WHMCS era',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'description' => 'The email(s) or merchant id(s) of our PayPal account(s), comma-separated. A PayPal notice still sent to WHMCS\'s old address is told to staff only when it is for one of them (PR H2b).',
                ],
                [
                    'name' => 'cronjob_order_cancel',
                    'section' => 'Billing Settings',
                    'label' => 'Cancel unpaid orders after',
                    'type' => 'number',
                    'default' => 7,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'Days after which a new order that was never paid is cancelled, with its invoice (0 to disable).',
                ],
                [
                    'name' => 'cronjob_payment_reminder',
                    'section' => 'Billing Settings',
                    'label' => 'Invoice Unpaid Reminder',
                    'type' => 'number',
                    'default' => 6,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'Days before the due date to remind the customer of an unpaid invoice (0 to disable). An invoice paid automatically (saved payment method, subscription) gets a «payment due» notice instead.',
                ],
                [
                    'name' => 'cronjob_overdue_reminders',
                    'section' => 'Billing Settings',
                    'label' => 'Overdue Reminders',
                    'type' => 'text',
                    'default' => '1,2',
                    'required' => false,
                    'validation' => 'nullable|regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/',
                    'description' => 'Days after the due date to send the overdue notice, comma separated (e.g. 1,3,5); empty to disable. One notice, sent on each day; the last one says when the service is suspended (WHMCS: First/Second/Third Overdue Reminder).',
                ],
                [
                    'name' => 'cronjob_close_ticket',
                    'section' => 'Support Ticket Settings',
                    'label' => 'Close Inactive Tickets',
                    'type' => 'number',
                    'default' => 5,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'Days of inactivity after which a ticket awaiting the customer is closed (0 to disable; WHMCS counts hours).',
                ],
                [
                    'name' => 'cronjob_delete_email_logs',
                    'section' => 'Data Retention Settings',
                    'label' => 'Delete email logs older than',
                    'type' => 'number',
                    'default' => 90,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'Days the sent email log is kept (0 keeps it forever).',
                ],
            ],
            'credits' => [
                [
                    'name' => 'credits_enabled',
                    'label' => 'Credits Enabled',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Off: customers can neither add nor spend credit, and it is never used automatically. On: customers add funds and pay with credit; with "Automatically use credits" also on, due invoices are paid from the balance.',
                ],
                [
                    'name' => 'credits_minimum_deposit',
                    'label' => 'Minimum Deposit',
                    'type' => 'number',
                    'default' => 5,
                    'required' => true,
                ],
                [
                    'name' => 'credits_maximum_deposit',
                    'label' => 'Maximum Deposit',
                    'type' => 'number',
                    'default' => 100,
                    'required' => true,
                ],
                [
                    'name' => 'credits_maximum_credit',
                    'label' => 'Maximum Credit',
                    'type' => 'number',
                    'default' => 300,
                    'required' => true,
                ],
                [
                    'name' => 'credits_auto_use',
                    'label' => 'Automatically use credits',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Credit pays open invoices on its own, also in part, oldest due first: when a renewal invoice is made, when credit comes in from an overpayment, and once a day before suspensions. Not credit purchases, invoices with a payment in progress, or services with a gateway subscription; customers can opt out.',
                ],
                [
                    // Enable credits give back if and service is upgraded or downgraded
                    'name' => 'credits_on_downgrade',
                    'label' => 'Enable credits on service downgrade',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Enable giving back credits to users when they downgrade their service. The credits given back will be the prorated difference between the old and new service based on the remaining time in the billing cycle.',
                ],
            ],
            // The client area's look (audit: the theme system went, one set of views in resources/views). Stored as
            // theme_<name>, read with theme('<name>'). Custom CSS/HTML and the uploaded files are for a company's own
            // header, footer and styles, so nothing company-specific is written into the views (git pulls stay clean).
            // They run as the client area's own code (same origin as /admin): their own permission, not settings.update.
            'theme' => [
                [
                    'name' => 'theme_logo_display',
                    'label' => 'Logo display',
                    'type' => 'select',
                    'options' => [
                        'logo-only' => 'Logo only',
                        'logo-and-name' => 'Logo and Name',
                    ],
                    'default' => 'logo-and-name',
                    'section' => 'Layout',
                ],
                [
                    'name' => 'theme_home_page_text',
                    'label' => 'Home Page Text',
                    'type' => 'markdown',
                    'default' => 'Welcome!',
                    'section' => 'Layout',
                ],
                [
                    'name' => 'theme_direct_checkout',
                    'label' => 'Direct Checkout',
                    'type' => 'checkbox',
                    'default' => false,
                    'database_type' => 'boolean',
                    'description' => 'Don\'t show the product overview page, go directly to the checkout page',
                    'section' => 'Layout',
                ],
                [
                    'name' => 'theme_small_images',
                    'label' => 'Small Images',
                    'type' => 'checkbox',
                    'default' => false,
                    'database_type' => 'boolean',
                    'description' => 'Show small images in the product overview page',
                    'section' => 'Layout',
                ],
                [
                    'name' => 'theme_show_category_description',
                    'label' => 'Show Category Description',
                    'type' => 'checkbox',
                    'default' => true,
                    'database_type' => 'boolean',
                    'description' => 'Show the category description in the product overview page/homepage',
                    'section' => 'Layout',
                ],
                [
                    'name' => 'theme_primary',
                    'label' => 'Primary - Brand Color',
                    'type' => 'color',
                    'default' => 'hsl(229, 100%, 64%)',
                    'section' => 'Colors (light mode)',
                    'action' => ResetColorsAction::class,
                ],
                [
                    'name' => 'theme_secondary',
                    'label' => 'Secondary - Brand Color',
                    'type' => 'color',
                    'default' => 'hsl(237, 33%, 60%)',
                    'section' => 'Colors (light mode)',
                ],
                [
                    'name' => 'theme_neutral',
                    'label' => 'Borders, Accents...',
                    'type' => 'color',
                    'default' => 'hsl(220, 25%, 85%)',
                    'section' => 'Colors (light mode)',
                ],
                [
                    'name' => 'theme_base',
                    'label' => 'Base - Text Color',
                    'type' => 'color',
                    'default' => 'hsl(0, 0%, 0%)',
                    'section' => 'Colors (light mode)',
                ],
                [
                    'name' => 'theme_muted',
                    'label' => 'Muted - Text Color',
                    'type' => 'color',
                    'default' => 'hsl(220, 0%, 53%)',
                    'section' => 'Colors (light mode)',
                ],
                [
                    'name' => 'theme_inverted',
                    'label' => 'Inverted - Text Color',
                    'type' => 'color',
                    'default' => 'hsl(100, 100%, 100%)',
                    'section' => 'Colors (light mode)',
                ],
                [
                    'name' => 'theme_background',
                    'label' => 'Background - Color',
                    'type' => 'color',
                    'default' => 'hsl(100, 100%, 100%)',
                    'section' => 'Colors (light mode)',
                ],
                [
                    'name' => 'theme_background-secondary',
                    'label' => 'Background - Secondary Color',
                    'type' => 'color',
                    'default' => 'hsl(0, 0%, 97%)',
                    'section' => 'Colors (light mode)',
                ],
                [
                    'name' => 'theme_dark-primary',
                    'label' => 'Primary - Brand Color',
                    'type' => 'color',
                    'default' => 'hsl(229, 100%, 64%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_dark-secondary',
                    'label' => 'Secondary - Brand Color',
                    'type' => 'color',
                    'default' => 'hsl(237, 33%, 60%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_dark-neutral',
                    'label' => 'Borders, Accents...',
                    'type' => 'color',
                    'default' => 'hsl(0, 0%, 17%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_dark-base',
                    'label' => 'Base - Text Color',
                    'type' => 'color',
                    'default' => 'hsl(100, 100%, 100%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_dark-muted',
                    'label' => 'Muted - Text Color',
                    'type' => 'color',
                    'default' => 'hsl(0, 0%, 40%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_dark-inverted',
                    'label' => 'Inverted - Text Color',
                    'type' => 'color',
                    'default' => 'hsl(220, 14%, 60%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_dark-background',
                    'label' => 'Background - Color',
                    'type' => 'color',
                    'default' => 'hsl(240, 18%, 9%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_dark-background-secondary',
                    'label' => 'Background - Secondary Color',
                    'type' => 'color',
                    'default' => 'hsl(240, 13%, 11%)',
                    'section' => 'Colors (dark mode)',
                ],
                [
                    'name' => 'theme_custom_css',
                    'permission' => 'admin.settings.custom_code',
                    'validation' => 'max:65000',
                    'label' => 'Custom CSS',
                    'type' => 'textarea',
                    'rows' => 10,
                    'default' => '',
                    'description' => 'Added after the client area\'s own styles, on every page of the client area.',
                    'section' => 'Custom code',
                ],
                [
                    'name' => 'theme_custom_head',
                    'permission' => 'admin.settings.custom_code',
                    'validation' => 'max:65000',
                    'label' => 'Custom <head> HTML',
                    'type' => 'textarea',
                    'rows' => 6,
                    'default' => '',
                    'description' => 'Raw HTML at the end of <head>: fonts, meta tags, analytics scripts.',
                    'section' => 'Custom code',
                ],
                [
                    'name' => 'theme_custom_header',
                    'permission' => 'admin.settings.custom_code',
                    'validation' => 'max:65000',
                    'label' => 'Custom header HTML',
                    'type' => 'textarea',
                    'rows' => 10,
                    'default' => '',
                    'description' => 'Raw HTML at the top of every page, before the client area\'s menu (your own site\'s menu). The client area\'s menu is fixed at the top: style both with the custom CSS.',
                    'section' => 'Custom code',
                ],
                [
                    'name' => 'theme_custom_footer',
                    'permission' => 'admin.settings.custom_code',
                    'validation' => 'max:65000',
                    'label' => 'Custom footer HTML',
                    'type' => 'textarea',
                    'rows' => 10,
                    'default' => '',
                    'description' => 'Raw HTML shown instead of the client area\'s footer. Empty: the built-in footer.',
                    'section' => 'Custom code',
                ],
                [
                    'name' => 'theme_assets',
                    'permission' => 'admin.settings.custom_code',
                    'label' => 'Files',
                    'type' => 'file',
                    'multiple' => true,
                    'database_type' => 'array',
                    'directory' => 'brand',
                    'visibility' => 'public',
                    'accept' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml', 'font/woff', 'font/woff2'],
                    'default' => [],
                    'description' => 'Images and fonts for the custom CSS and HTML, served as /storage/brand/<file name>.',
                    'section' => 'Files',
                ],
            ],
            'invoices' => [
                [
                    'name' => 'bill_to_text',
                    'label' => 'Bill To Text',
                    'type' => 'textarea',
                    'default' => '',
                ],
                [
                    'name' => 'invoice_number',
                    'label' => 'Invoice Number',
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => 'The next invoice number to use. This will be incremented automatically.',
                ],
                [
                    'name' => 'invoice_number_padding',
                    'label' => 'Invoice Number Padding',
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => 'Number of digits to use for invoice numbers. Example: 0001, 0002, etc.',
                ],
                [
                    'name' => 'invoice_number_format',
                    'label' => 'Invoice number format',
                    'type' => 'text',
                    'default' => 'INV-{number}',
                    'required' => false,
                    'description' => 'Format to use for invoice numbers. Use {number} to insert the zero padded number and use {year}, {month} and {day} placeholders to insert the current date. Example: INV-{year}-{month}-{day}-{number} or INV-{year}{number}. It must at least contain {number}.',
                    'validation' => 'regex:/{number}/',
                ],
                [
                    'name' => 'invoice_proforma',
                    'label' => 'Proforma Invoices',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Proforma invoices will not be assigned an official invoice number until payment is received and will be marked as "Proforma".',
                ],
                [
                    'name' => 'mass_pay_enabled',
                    'label' => 'Mass Payment',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Customers choose several unpaid invoices and pay them at once through a gateway, as in WHMCS. No combined invoice is made: the payment is recorded on the invoice due first and pays the others in order.',
                ],
                [
                    'name' => 'immutable_invoices_enabled',
                    'label' => 'Immutable Invoices',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'When enabled, invoices can only be edited while in draft status. Once published, they become read-only. Disable this to allow editing invoices at any status.',
                ],
                [
                    'name' => 'notes_client_visible',
                    'label' => 'Show Adjustment Notes to Clients',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Show the adjustments section (credit/debit notes) to clients on the invoice page. When disabled, only admin-only notes are visible in the admin panel.',
                ],
                [
                    'name' => 'invoice_snapshot',
                    'label' => 'Invoice Snapshot',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Save a snapshot of important data (name, address, etc.) on the invoice when it is paid. This ensures that if someone changes their details later, old invoices will still have the correct information.',
                ],
                [
                    'name' => 'credit_note_number',
                    'label' => 'Credit/Debit Note Number',
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => 'The next credit/debit note number to use. This will be incremented automatically.',
                ],
                [
                    'name' => 'credit_note_number_padding',
                    'label' => 'Credit/Debit Note Number Padding',
                    'type' => 'number',
                    'default' => 1,
                    'required' => false,
                    'description' => 'Number of digits to use for credit/debit note numbers. Example: 0001, 0002, etc.',
                ],
                [
                    'name' => 'credit_note_number_format',
                    'label' => 'Credit/Debit Note Number Format',
                    'type' => 'text',
                    'default' => 'CN-{number}',
                    'required' => false,
                    'description' => 'Format to use for credit/debit note numbers. Use {number} to insert the zero padded number and use {year}, {month} and {day} placeholders to insert the current date. Example: CN-{year}-{month}-{day}-{number} or CN-{year}{number}. It must at least contain {number}.',
                    'validation' => 'regex:/{number}/',
                ],
            ],
            'other' => [
                [
                    'name' => 'default_currency',
                    'label' => 'Default Currency',
                    'type' => 'select',
                    'options' => $currencies,
                    'default' => 'USD',
                    'required' => true,
                ],
                [
                    'name' => 'registration_disabled',
                    'label' => 'Disable User Registration',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Only allow existing users to log in. This will hide the registration page and prevent new users from signing up.',
                ],
                [
                    'name' => 'pagination',
                    'label' => 'Pagination',
                    'type' => 'number',
                    'default' => 10,
                    'required' => true,
                    'description' => 'Number of items to show per page',
                ],
                [
                    'name' => 'debug',
                    'label' => 'Debug Mode',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Enable debug mode to log HTTP requests and errors',
                ],
            ],
        ];

        return $settings;
    }

    private static function getAvailableLanguages(): array
    {
        return once(
            fn () => glob(base_path('lang/*'), GLOB_ONLYDIR)
                ? array_map('basename', glob(base_path('lang/*'), GLOB_ONLYDIR))
                : ['en']
        );
    }

    public static function tax(?Client $client = null)
    {
        // The account's country (docs/accounts.md, PR 2a): the given one, or in the client area the current one.
        $client ??= Auth::guard('web')->check() ? CurrentClient::model() : null;
        // The account's country is its primary contact's (PR 3a), an ISO code.
        $country = $client?->country;

        // Change country to a two-letter country code if it's not already
        if ($country) {
            $country = array_search($country, config('app.countries')) ?: $country;
        }
        // EU VAT (PR G): EL is Greece, and Monaco pays France's VAT (adversarial ADV-3/4).
        if (EuVat::enabled() && ($iso = EuVat::iso($country)) !== null) {
            $country = EuVat::territory($iso);
        }

        // Once per country and request/job (product pages build many prices); reset between requests and queued
        // jobs. Per country, not per user: a customer whose country changes in the request gets the new rate.
        $rates = app('settings.tax-rates');
        $key = $country ?? '';
        if (!$rates->offsetExists($key)) {
            $rates[$key] = TaxRate::whereIn('country', [$country, 'all'])
                ->orderByRaw('country = ? desc', [$country])
                ->first() ?: 0;
        }

        return $rates[$key];
    }

    public static function settingsObject()
    {
        return (object) json_decode(json_encode(static::settings()));
    }

    public static function getSetting($key)
    {
        $setting = (object) collect(static::settings())->flatten(1)->firstWhere('name', $key);
        $setting->value = Setting::where('settingable_type', null)->where('key', $key)->value('value') ?? $setting->default ?? null;

        return $setting;
    }

    public static function validateOrCreateVapidKeys(): bool
    {
        $publicKey = config('settings.vapid_public_key');
        $privateKey = config('settings.vapid_private_key');
        if ($publicKey && $privateKey && strlen($publicKey) > 80 && strlen($privateKey) > 40) {
            return true;
        }
        try {
            $vapid = VAPID::createVapidKeys();
            Setting::updateOrCreate(
                ['key' => 'vapid_public_key', 'encrypted' => true],
                ['value' => $vapid['publicKey']]
            );
            Setting::updateOrCreate(
                ['key' => 'vapid_private_key', 'encrypted' => true],
                ['value' => $vapid['privateKey']]
            );

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
