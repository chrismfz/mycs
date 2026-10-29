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
use App\Rules\TicketReferenceMask;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Mcp\McpOauth;
use App\Services\Tax\EuVat;
use App\Support\CurrentClient;
use App\Support\Retention;
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
                    // Every link the app makes starts with it (password resets, signed links): full admins only (SA-4).
                    'permission' => '*',
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
                    // A link on the register page and the cart: a web page, never `javascript:` (review of #330).
                    'validation' => 'nullable|url:http,https',
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
                        // Ours, nothing sent to anyone (docs/captcha.md): no keys to fill in.
                        'altcha' => 'ALTCHA (self-hosted)',
                    ],
                    'default' => 'disabled',
                    'live' => true,
                ],
                [
                    'name' => 'altcha_difficulty',
                    'label' => 'ALTCHA difficulty',
                    'type' => 'select',
                    'options' => [
                        'low' => 'Low (old phones)',
                        'normal' => 'Normal',
                        'high' => 'High (under attack)',
                    ],
                    'default' => 'normal',
                    'description' => 'The work the visitor\'s browser does before a form is sent: about 1–2 s on a computer at «Normal», a few on a phone; «High» about three times that.',
                    'hidden' => fn (Get $get) => $get('captcha') !== 'altcha',
                ],
                [
                    'name' => 'altcha_minutes',
                    'label' => 'ALTCHA challenge lifetime (minutes)',
                    'type' => 'number',
                    'default' => 10,
                    'validation' => 'nullable|integer|min:1|max:60',
                    'description' => 'How long a solved challenge can still be sent.',
                    'hidden' => fn (Get $get) => $get('captcha') !== 'altcha',
                ],
                [
                    'name' => 'captcha_site_key',
                    'label' => 'Captcha Site Key',
                    'type' => 'text',
                    'required' => fn (Get $get) => $get('captcha') && !in_array($get('captcha'), ['disabled', 'altcha'], true),
                    'hidden' => fn (Get $get) => $get('captcha') === 'altcha',
                ],
                [
                    'name' => 'captcha_secret',
                    'label' => 'Captcha Secret',
                    'type' => 'text',
                    'encrypted' => true, // a secret: stored encrypted, never sent to the page (sweep §6 review R4)
                    'required' => fn (Get $get) => $get('captcha') && !in_array($get('captcha'), ['disabled', 'altcha'], true),
                    'hidden' => fn (Get $get) => $get('captcha') === 'altcha',
                ],

                [
                    'name' => 'trusted_proxies',
                    // Who may set the client IP: allowlists, the zlic IP binding and the throttles trust it (AUD3-2).
                    'permission' => '*',
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
                    'name' => 'mail_staff_new_order',
                    'label' => 'Email staff on new orders',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'As WHMCS\'s «New Order Notification»: when a customer places an order, an email to the System Email Address with its items, invoice, notes and links to it. Not for orders staff create.',
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
                    // The number customers see on a ticket, in WHMCS's mask syntax (docs/tickets.md §5): %n a digit, %A
                    // and %a a letter, %y %m %d the date. Only for display, never a key to a ticket.
                    'name' => 'ticket_reference_mask',
                    'label' => 'Ticket number format',
                    'type' => 'text',
                    'default' => '%n%n%n%n%n%n',
                    'required' => true,
                    'validation' => ['max:40', new TicketReferenceMask],
                    'description' => 'As WHMCS «Ticket Mask»: %n a digit, %A an upper case letter, %a a lower case one, %y %m %d the date (e.g. %y-%m-%d-%n%n%n%n%n%n). At least five %n, or four %A or %a. Departments are under Configuration → Ticket Departments.',
                ],
                [
                    'name' => 'ticket_client_closing_disabled',
                    'label' => 'Disallow clients from closing tickets',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                ],
                // Mail to tickets: a mailbox per department, under Configuration → Ticket Departments (docs/tickets.md, A2).
                [
                    // Live chat with customers (docs/livechat.md, step 3α): off until the owner turns it on; who answers is
                    // each department's «Live chat» list.
                    'name' => 'livechat_enabled',
                    'label' => 'Live chat for customers',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'A «Chat» bubble in the client area, for logins with the «chat» permission. Who answers is set per department (Configuration → Ticket Departments → Live chat); a department with no one listed takes no chats.',
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
                    'name' => 'cronjob_dunning_days',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Suspend and terminate on',
                    'type' => 'select',
                    'multiple' => true,
                    'database_type' => 'array',
                    'options' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
                    'default' => [1, 2, 3, 4, 5, 6, 7],
                    'description' => 'Not in WHMCS (it needed cron tricks). Automatic suspensions and terminations only on these days, so someone is there to undo one the same day; what falls on another day waits for the next of these. None chosen is every day. The rest of the daily run (invoices, reminders) runs every day.',
                ],
                [
                    'name' => 'cronjob_dunning_from',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Suspend and terminate from',
                    'type' => 'time',
                    'default' => '00:00',
                    'required' => true,
                    'description' => 'Not before this hour on those days (e.g. 10:00; minutes count as the full hour): they are checked every hour, so they also happen when the daily run is at night. Cards are charged and end-of-period cancellations ended first, as in the daily run.',
                ],
                [
                    'name' => 'cronjob_paid_not_applied_days',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Paid but not applied: protected days',
                    'type' => 'number',
                    'default' => 30,
                    'required' => true,
                    'min_value' => 1,
                    'max_value' => 365,
                    'validation' => 'integer',
                    'description' => 'Not in WHMCS. A service on a paid invoice whose payment could not be applied yet (the cron tries again every run) is neither billed again, suspended nor terminated for this many days; staff are told every day. Lowered, it applies at once to the invoices already waiting.',
                ],
                [
                    'name' => 'cronjob_suspension_digest',
                    'section' => 'Automatic Module Functions',
                    'label' => 'Email staff what was suspended',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'After the daily run, one email to the System Email Address listing each service suspended or terminated for non-payment, with its client, unpaid invoices and a link to it (to unsuspend it or set «Do Not Suspend Until»). Only on a day something was suspended or terminated, as WHMCS\'s «Cron Job Activity».',
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
                    'default' => 0,
                    'required' => true,
                    'min_value' => 0,
                    'description' => 'Not in WHMCS, where only staff cancel an order (Pending Orders). 0 (default): never, as WHMCS. Or days after which a new order that was never paid is cancelled, with its invoice.',
                ],
                [
                    'name' => 'cronjob_add_funds_cancel',
                    'section' => 'Billing Settings',
                    'label' => 'Cancel unpaid «Add Funds» after',
                    'type' => 'number',
                    'default' => 0,
                    'required' => true,
                    'min_value' => 0,
                    'validation' => 'integer',
                    'description' => 'Not in WHMCS (a hook at myip: 30). 0 (default): never. Or days after which an invoice that only buys account credit and was never paid is cancelled.',
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
                // The other logs' retention (App\Support\Retention), each 0 for ever.
                ...Retention::settings(),
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
                    // A way back to the company's own site, which is its shop window (docs/admin-ux.md, «Μενού επισκέπτη»).
                    'name' => 'theme_main_site_url',
                    'label' => 'Company site URL',
                    'type' => 'text',
                    'default' => '',
                    'validation' => 'nullable|url:https',
                    'description' => 'Shown in the menu as a link back to the company\'s site. Empty: no link.',
                    'section' => 'Layout',
                ],
                [
                    'name' => 'theme_main_site_label',
                    'label' => 'Company site link text',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Empty: the site\'s address, e.g. myip.gr.',
                    'section' => 'Layout',
                ],
                [
                    'name' => 'theme_home_page_text',
                    // Markdown that may hold raw HTML, shown on this site's origin (the admin's too): custom code.
                    'permission' => '*', // code on this site's origin, the admin's too: a full admin's alone (AUD3-2)
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
                    'permission' => '*', // code on this site's origin, the admin's too: a full admin's alone (AUD3-2)
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
                    'permission' => '*', // code on this site's origin, the admin's too: a full admin's alone (AUD3-2)
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
                    'permission' => '*', // code on this site's origin, the admin's too: a full admin's alone (AUD3-2)
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
                    'permission' => '*', // code on this site's origin, the admin's too: a full admin's alone (AUD3-2)
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
                    'permission' => '*', // code on this site's origin, the admin's too: a full admin's alone (AUD3-2)
                    'label' => 'Files',
                    'type' => 'file',
                    'multiple' => true,
                    'database_type' => 'array',
                    'directory' => 'brand',
                    'visibility' => 'public',
                    'accept' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml', 'font/woff', 'font/woff2'],
                    'default' => [],
                    'description' => 'Images and fonts for the custom CSS and HTML, served as /storage/brand/<file name>. A name still in this list gets a number (hero-2.png): to replace a file under its own name, remove it from the list and save, then upload the new one.',
                    'section' => 'Files',
                ],
            ],
            // Domains (owner 2026-10-17, as WHMCS's Domains tab): what the shop sells, how they renew, what is kept.
            'domains' => [
                // The shop (D8b; WHMCS AllowRegister, AllowTransfer).
                [
                    'name' => 'domain_allow_register',
                    'section' => 'Ordering',
                    'label' => 'Allow clients to register domains',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Off: the search shows prices and availability, without «Add».',
                ],
                [
                    'name' => 'domain_allow_transfer',
                    'section' => 'Ordering',
                    'label' => 'Allow clients to transfer domains to us',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Off: no «Transfer it to us» in the search.',
                ],
                // A .gr domain's change of holder (G8-5b, owner 2026-10-23): one price for a name change and a transfer.
                [
                    'name' => 'domain_gr_owner_change_price',
                    'section' => 'Ordering',
                    'label' => '.gr change of holder: price',
                    'type' => 'number',
                    'default' => 0,
                    'min_value' => 0,
                    'description' => 'Charged as entered (as the TLD prices are, with or without tax by the tax setting), in the default currency, for a .gr name change or transfer to another holder. 0: staff send it without an invoice only.',
                ],
                // WHMCS «Default Auto Renewal Setting».
                [
                    'name' => 'domain_default_auto_renew',
                    'section' => 'Ordering',
                    'label' => 'Auto renew new domains',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'A domain ordered starts with auto renew on (its renewal invoices are made); it can be changed per domain.',
                ],
                // D8b-2 (owner 2026-10-17): a registration's nameservers; a transfer keeps the registry's.
                [
                    'name' => 'domain_nameserver_1',
                    'section' => 'Ordering',
                    'label' => 'Default Nameserver 1',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'validation' => ['nullable', 'regex:/^\s*([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.?\s*$/'],
                    'description' => 'The nameservers of a domain registered from an order (at least two; none: new registrations wait for staff). A transfer keeps the nameservers it has.',
                ],
                [
                    'name' => 'domain_nameserver_2',
                    'section' => 'Ordering',
                    'label' => 'Default Nameserver 2',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'validation' => ['nullable', 'regex:/^\s*([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.?\s*$/'],
                ],
                [
                    'name' => 'domain_nameserver_3',
                    'section' => 'Ordering',
                    'label' => 'Default Nameserver 3',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'validation' => ['nullable', 'regex:/^\s*([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.?\s*$/'],
                ],
                [
                    'name' => 'domain_nameserver_4',
                    'section' => 'Ordering',
                    'label' => 'Default Nameserver 4',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'validation' => ['nullable', 'regex:/^\s*([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.?\s*$/'],
                ],
                [
                    'name' => 'domain_nameserver_5',
                    'section' => 'Ordering',
                    'label' => 'Default Nameserver 5',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'validation' => ['nullable', 'regex:/^\s*([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.?\s*$/'],
                ],
                // The «Popular» button of TLD Import & Pricing Sync (owner 2026-10-24): which TLDs it fetches.
                [
                    'name' => 'domain_popular_tlds',
                    'section' => 'Ordering',
                    'label' => 'Popular TLDs',
                    'type' => 'text',
                    'default' => TldImport::POPULAR_TLDS,
                    'required' => false,
                    'validation' => ['nullable', 'max:2000'],
                    'description' => 'The TLDs the «Popular» button of TLD Import & Pricing Sync fetches, separated by commas or spaces, without the dot.',
                ],
                // Domains billed at all (D5b): on; off only while another system still bills them (a migration, D-12) or in
                // an emergency at a registrar.
                [
                    'name' => 'domain_billing_enabled',
                    'section' => 'Renewals',
                    'label' => 'Bill domain renewals',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'Make the renewal invoices of domains with auto renew; on payment they are renewed at their registrar (a manual one: a Pending Module Action for staff). Turn off only while another system still bills the domains (a migration) or in an emergency.',
                ],
                // Domains (docs/domains.md D-14; WHMCS CreateDomainInvoiceDaysBefore): their own, empty = as the services.
                [
                    'name' => 'domain_invoice_days',
                    'section' => 'Renewals',
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
                    'section' => 'Renewals',
                    'label' => 'Domain Expiry Notices',
                    'type' => 'text',
                    'default' => '30,7,1,-3',
                    'required' => false,
                    // At most a year either side (DomainReminders::MAX_DAYS, review R9).
                    // An array: a string rule is split on «|», and the pattern has alternatives.
                    'validation' => ['nullable', 'regex:/^\s*-?(36[0-5]|3[0-5]\d|[12]?\d{1,2})(\s*,\s*-?(36[0-5]|3[0-5]\d|[12]?\d{1,2}))*\s*$/'],
                    'description' => 'Days before a domain expires to send its expiry notice, comma separated; negative days are after it (while it can still be renewed). Empty to disable. Domains set not to renew get none; one whose next year is paid gets none.',
                ],
                // Renewal years (owner 2026-10-17): those ordered, changed by the customer or staff among those priced.
                [
                    'name' => 'domain_client_years',
                    'section' => 'Renewals',
                    'label' => 'Clients may change the renewal period',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'A domain renews for the years it was ordered for. On: its owner may choose another period among those its TLD has a renew price for, from its next renewal (its open, unpaid renewal invoice changes with it). Staff always may.',
                ],
                // Kept and watched (D8b-2; owner 2026-10-17: the EPP code 30 days).
                [
                    'name' => 'domain_epp_code_days',
                    'section' => 'Housekeeping',
                    'label' => 'Keep transfer EPP codes (days)',
                    'type' => 'number',
                    'default' => 30,
                    'required' => true,
                    'min_value' => 1,
                    'description' => 'The EPP code a client gave for a transfer is kept (encrypted, shown to staff) until the transfer is no longer pending and this many days have passed since the order.',
                ],
                // The sync in batches (owner 2026-10-18): one cron, every few hours, so many domains per registrar connection,
                // the longest unsynced first; a registry is never asked for all at once (1,400 .gr at myip).
                [
                    'name' => 'domain_sync_hours',
                    'section' => 'Sync',
                    'label' => 'Sync a batch every (hours)',
                    'type' => 'number',
                    'default' => 2,
                    'required' => true,
                    'min_value' => 0,
                    'max_value' => 24,
                    'description' => 'How often a batch of domains is read from their registrars (default 2, at most 24). 0: the sync is paused (an emergency, a registry asking us to slow down): no batch, not even by hand without --force; «Sync now» on a domain still works, and the dates still move the domains every 2 hours.',
                ],
                [
                    'name' => 'domain_sync_batch',
                    'section' => 'Sync',
                    'label' => 'Domains per registrar per batch',
                    'type' => 'number',
                    'default' => 200,
                    'required' => true,
                    'min_value' => 1,
                    'max_value' => 1000,
                    'description' => 'How many domains of each registrar connection a batch reads (default 200, at most 1000): up to half of it the most needed (pending orders, names about to be invoiced or reminded, ones a registrar said are not ours), the rest the longest unsynced.',
                ],
                [
                    'name' => 'domain_stale_transfer_days',
                    'section' => 'Housekeeping',
                    'label' => 'Pending transfer alert (days)',
                    'type' => 'number',
                    'default' => 14,
                    'required' => true,
                    'min_value' => 1,
                    'description' => 'A transfer or registration still pending after this many days is listed under «Records to check» and in Health.',
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
                    'default' => 0,
                    'required' => false,
                    'description' => 'The last invoice number given: the next one is this plus one. Moved automatically.',
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
                    'description' => 'Proforma invoices will not be assigned an official invoice number until payment is received and will be marked as "Proforma". Not used when the invoice number is its ID.',
                ],
                [
                    'name' => 'invoice_number_is_id',
                    'label' => 'Invoice number = invoice ID',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'The number of an invoice is its ID (as WHMCS without sequential numbering). The format, padding and counter above are not used, nor is proforma numbering.',
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
                    'default' => 0,
                    'required' => false,
                    'description' => 'The last credit/debit note number given: the next one is this plus one. Moved automatically.',
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
            // The bridge to Ekdosi, the fiscal documents of a Greek instance (docs/ekdosi-bridge.md). Off: nothing answers.
            'ekdosi' => [
                [
                    'name' => 'ekdosi_enabled',
                    'permission' => '*',
                    'label' => 'Ekdosi bridge',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Ekdosi reads the paid invoices into its Inbox and writes back the fiscal documents it issued from them (MARK, number, PDF). Off: the bridge answers nothing. Only one billing system feeds an Ekdosi company: turn it on at the cut-over, when WHMCS stops.',
                ],
                [
                    'name' => 'ekdosi_url',
                    'permission' => '*',
                    'label' => 'Ekdosi address',
                    'type' => 'text',
                    'default' => '',
                    'validation' => 'nullable|url:https',
                    'description' => 'Ekdosi\'s address, e.g. https://invoicer.example.com/ekdosi. The links to its documents\' PDFs are kept only when they are on it.',
                ],
                [
                    'name' => 'ekdosi_secret',
                    'permission' => '*',
                    'label' => 'Ekdosi shared secret',
                    'type' => 'text',
                    'encrypted' => true, // a secret: stored encrypted, never sent to the page (sweep §6 review R4)
                    'validation' => 'nullable|min:32|max:255',
                    'description' => 'The same secret as in Ekdosi\'s «MyCS Bridge». Every request is signed with it both ways (X-Webhook-Signature: sha256=…). At least 32 characters.',
                ],
            ],
            'other' => [
                [
                    'name' => 'gateway_reference',
                    // How this site knows its own payments: full admins only (review of #330).
                    'permission' => '*',
                    'label' => 'Gateway reference',
                    'type' => 'text',
                    'default' => '',
                    'validation' => 'nullable|max:40|regex:/^[A-Za-z0-9-]*[A-Za-z0-9][A-Za-z0-9-]*$/',
                    'description' => 'This site\'s mark on the payments it starts at Stripe, PayPal and Mollie (letters, digits, dashes). Empty: the site\'s address (e.g. my-nixpal-com). Set it once, before the first payment: a change makes payments still open look like another system\'s (staff are told, nothing is recorded).',
                ],
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
                [
                    'name' => 'mcp_oauth_redirect_hosts',
                    // Where the MCP server may send a staff member's authorization: full admins only (API-5).
                    'permission' => '*',
                    'label' => 'MCP OAuth redirect hosts',
                    'type' => 'text',
                    'default' => McpOauth::DEFAULT_REDIRECT_HOSTS,
                    'validation' => 'nullable|max:500',
                    'description' => 'The hosts (and their subdomains) an MCP client may be sent back to with a staff member\'s authorization, separated by commas or spaces. Anyone can register an MCP client under any name: only these hosts can receive a grant. Empty: Claude\'s (claude.ai, claude.com) and this computer.',
                ],
                [
                    'name' => 'mcp_grant_days',
                    // How long an MCP connector's approval lasts, however often it is used: full admins only.
                    'permission' => '*',
                    'label' => 'MCP grant lifetime (days)',
                    'type' => 'number',
                    'default' => McpOauth::GRANT_DAYS,
                    'required' => true,
                    'min_value' => 0,
                    'max_value' => McpOauth::MAX_GRANT_DAYS,
                    'validation' => 'integer',
                    'description' => 'Days an approved MCP connector keeps working, however often it is used; then staff approve it again. 0: until revoked (Other → MCP OAuth Grants, a new password or 2FA, ending the session that approved it). Automation Status warns while it is 0.',
                ],
                [
                    'name' => 'staff_2fa_required',
                    // Whether every member of staff must set 2FA up before using the admin: full admins only.
                    'permission' => '*',
                    'label' => 'Require 2FA for staff',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => true,
                    'description' => 'On: every member of staff sets up an authenticator app at their first login, before anything else in the admin. Off: 2FA is optional in each member\'s profile; Automation Status warns while it is off. Approving an MCP connector always needs 2FA.',
                ],
                [
                    'name' => 'logs_full',
                    // What staff read in the logs and the system mails: full admins only.
                    'permission' => '*',
                    'label' => 'Full logs',
                    'type' => 'checkbox',
                    'database_type' => 'boolean',
                    'default' => false,
                    'description' => 'Keep the logs, module action errors and staff mails exactly as received, secrets included (server passwords, tokens, login links). Off: they are masked (********). Turn it on only to see what a server or gateway really answered.',
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
