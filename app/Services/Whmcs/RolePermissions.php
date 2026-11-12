<?php

namespace App\Services\Whmcs;

use App\Admin\Resources\RoleResource;

/**
 * A WHMCS admin role's permissions (`tbladminperms.permid`, named in WHMCS's `sysadmin/lang/english.php`
 * `$_ADMINLANG['permissions']`) as MyCS's (`config/permissions.php`), WG-5: the importer gave every role but the Full
 * Administrator none, so nixpal's Sales Operator and myip's Support Operator could not log in to anything. A WHMCS
 * permission becomes the closest MyCS ones, never more: one MyCS has no feature for (affiliates, downloads, the calendar,
 * banned IPs...) is in UNMAPPED, by name, for the importer to say. A delete gives the bulk delete too (WHMCS has no
 * separate one). «Configure General/Automation Settings» give `settings.update`, all of MyCS's settings (owner,
 * 2026-11-04: as in WHMCS, not refactored for it). Every role gets MyCS's defaults (RoleResource::DEFAULT_PERMISSIONS).
 */
final class RolePermissions
{
    /** @var array<int, list<string>> WHMCS permid => MyCS permissions, without the `admin.` */
    private const MAP = [
        1 => ['widgets.overview'], // Main Homepage
        2 => ['widgets.overview'], // Sidebar Statistics
        3 => [], // My Account: every staff has their own
        4 => ['clients.viewAny'], // List Clients
        5 => ['services.viewAny'], // List Services
        6 => ['services.viewAny'], // List Addons
        7 => ['domains.viewAny'], // List Domains
        8 => ['clients.create'], // Add New Client
        9 => ['clients.update'], // Edit Clients Details
        10 => ['billing_agreements.viewAny'], // Manage Pay Methods
        11 => ['services.viewAny'], // View Clients Products/Services
        12 => ['services.update', 'services.move', 'service_cancellations.create'], // Edit Clients Products/Services
        13 => ['services.delete', 'services.deleteAny'], // Delete Clients Products/Services
        15 => ['domains.viewAny'], // View Clients Domains
        16 => ['domains.update', 'domains.manage', 'domains.move'], // Edit Clients Domains
        18 => ['clients.viewAny'], // View Clients Notes
        20 => ['clients.delete'], // Delete Client
        21 => ['bulk_mails.viewAny', 'bulk_mails.create', 'bulk_mails.update', 'bulk_mails.delete', 'bulk_mails.send'], // Email Campaigns/Mass Mail
        22 => ['service_cancellations.viewAny', 'service_cancellations.update', 'service_cancellations.delete', 'service_cancellations.deleteAny'], // View Cancellation Requests
        24 => ['orders.viewAny'], // View Orders
        25 => ['orders.delete', 'orders.deleteAny'], // Delete Order
        26 => ['orders.viewAny', 'orders.update'], // View Order Details (accept, cancel, fraud there)
        27 => ['orders.create', 'services.create', 'domains.create'], // Add New Order
        28 => ['invoice_transactions.viewAny'], // List Transactions
        29 => ['invoice_transactions.create', 'gateway_transactions.update'], // Add Transaction (and mark a gateway difference known)
        31 => ['invoice_transactions.delete', 'invoice_transactions.deleteAny'], // Delete Transaction
        32 => ['gateway_events.viewAny', 'gateway_transactions.viewAny'], // View Gateway Log (and the gateway's own list against ours)
        33 => ['invoices.viewAny'], // List Invoices
        34 => ['invoices.create'], // Create Invoice
        35 => ['invoices.update'], // Manage Invoice
        36 => ['invoices.delete', 'invoices.deleteAny'], // Delete Invoice
        38 => ['widgets.support'], // Support Center Overview
        40 => ['knowledgebase.viewAny', 'knowledgebase.create', 'knowledgebase.update', 'knowledgebase.delete'], // Manage Knowledgebase
        42 => ['tickets.viewAny'], // List Support Tickets
        43 => ['tickets.create'], // Open New Ticket
        44 => ['canned_replies.viewAny'], // Manage Predefined Replies
        45 => ['reports.view'], // View Reports
        56 => ['system_cleanup.run'], // System Cleanup Operations
        58 => ['audit.viewAny'], // View Activity Log
        59 => ['audit.viewAny'], // View Admin Log
        60 => ['email_logs.viewAny', 'email_logs.view', 'email_logs.resend'], // View Email Message Log
        61 => ['ticket_quarantine.viewAny', 'ticket_quarantine.update'], // View Ticket Mail Import Log
        63 => ['settings.view', 'settings.update'], // Configure General Settings
        64 => ['staff.viewAny', 'staff.create', 'staff.update', 'staff.delete', 'staff.sessions'], // Configure Administrators
        65 => ['roles.viewAny', 'roles.create', 'roles.update', 'roles.delete', 'roles.deleteAny'], // Configure Admin Roles
        66 => ['servers.viewAny', 'servers.create', 'servers.update', 'servers.delete', 'servers.deleteAny'], // Configure Servers
        67 => ['settings.view', 'settings.update'], // Configure Automation Settings
        68 => ['gateways.viewAny', 'gateways.create', 'gateways.update', 'gateways.delete', 'gateways.deleteAny'], // Configure Payment Gateways
        69 => ['tax_rates.viewAny', 'tax_rates.create', 'tax_rates.update', 'tax_rates.delete', 'tax_rates.deleteAny'], // Tax Configuration
        70 => ['notification_templates.viewAny'], // View Email Templates
        71 => ['products.viewAny', 'categories.viewAny', 'config_options.viewAny'], // View Products/Services
        73 => ['coupons.viewAny'], // View Promotions
        74 => ['domain_tlds.viewAny', 'domain_tlds.create', 'domain_tlds.update', 'domain_tlds.delete'], // Configure Domain Pricing
        75 => ['ticket_departments.viewAny', 'ticket_departments.create', 'ticket_departments.update', 'ticket_departments.delete'], // Configure Support Departments
        76 => ['ticket_blocked_senders.viewAny', 'ticket_blocked_senders.create', 'ticket_blocked_senders.delete'], // Configure Spam Control
        77 => ['ticket_blocked_senders.viewAny', 'ticket_blocked_senders.create', 'ticket_blocked_senders.delete'], // Configure Banned Emails
        78 => ['domain_registrars.viewAny', 'domain_registrars.create', 'domain_registrars.update', 'domain_registrars.delete'], // Configure Domain Registrars
        80 => ['custom_properties.viewAny', 'custom_properties.create', 'custom_properties.update', 'custom_properties.delete', 'custom_properties.deleteAny'], // Configure Custom Client Fields
        81 => ['api_keys.viewAny', 'api_keys.create', 'api_keys.update', 'api_keys.delete', 'api_keys.deleteAny'], // API Access
        85 => ['offers.viewAny', 'offers.create', 'offers.update', 'offers.delete', 'offers.deleteAny'], // Manage Quotes
        86 => ['currencies.viewAny', 'currencies.create', 'currencies.update', 'currencies.delete', 'currencies.deleteAny'], // Configure Currencies
        91 => ['client_groups.viewAny', 'client_groups.create', 'client_groups.update', 'client_groups.delete', 'client_groups.deleteAny'], // Configure Client Groups
        92 => ['invoice_transactions.update'], // Refund Invoice Payments
        93 => ['tickets.delete', 'tickets.deleteAny', 'ticket_messages.delete'], // Delete Ticket
        94 => ['widgets.revenue', 'widgets.payments_tracking'], // View Income Totals
        98 => ['domains.manage', 'domains.epp', 'domains.renew', 'domains.owner_change'], // Perform Registrar Operations
        100 => ['extensions.viewAny', 'extensions.update', 'extensions.install', 'extensions.delete'], // Configure Addon Modules
        101 => ['bulk_mails.viewAny', 'bulk_mails.create', 'bulk_mails.update', 'bulk_mails.send'], // Email Marketer
        103 => ['debug_logs.view'], // View Module Debug Log
        104 => ['clients.viewAny'], // View Clients Summary
        105 => ['tickets.viewAny', 'tickets.update'], // View Support Ticket
        107 => ['billing_agreements.viewAny'], // Update/Delete Stored Credit Card
        108 => ['coupons.create', 'coupons.update'], // Create/Edit Promotions
        109 => ['coupons.delete', 'coupons.deleteAny'], // Delete Promotions
        113 => ['notification_templates.create', 'notification_templates.update'], // Create/Edit Email Templates
        114 => ['notification_templates.delete', 'notification_templates.deleteAny'], // Delete Email Templates
        116 => ['products.create', 'categories.create', 'config_options.create'], // Create New Products/Services
        117 => ['products.update', 'categories.update', 'config_options.update'], // Edit Products/Services
        118 => ['products.delete', 'products.deleteAny', 'categories.delete', 'categories.deleteAny', 'config_options.delete', 'config_options.deleteAny'], // Delete Products/Services
        119 => ['categories.viewAny', 'categories.create', 'categories.update', 'categories.delete', 'categories.deleteAny'], // Manage Product Groups
        120 => ['users.impersonate', 'services.sso'], // Allow Login as Owner
        125 => ['canned_replies.create', 'canned_replies.update'], // Create Predefined Replies
        126 => ['canned_replies.delete'], // Delete Predefined Replies
        128 => ['credits.viewAny'], // View Credit Log
        129 => ['credits.create', 'credits.update', 'credits.delete'], // Manage Credits
        132 => ['cron_stats.view', 'failed_jobs.viewAny'], // System Health Status
        137 => ['failed_jobs.viewAny', 'failed_jobs.retry'], // View Module Queue
        138 => ['cron_stats.view'], // Automation Status
        142 => ['api_keys.viewAny', 'api_keys.create', 'api_keys.update', 'api_keys.delete', 'api_keys.deleteAny'], // Manage API Credentials
        144 => ['chat_notifications.viewAny', 'chat_notifications.create', 'chat_notifications.update', 'chat_notifications.delete'], // Manage Notifications
        150 => ['users.viewAny'], // View Account Users
        151 => ['users.create', 'users.update'], // Manage Users
        152 => ['users.viewAny'], // List Users
        153 => ['users.delete', 'users.deleteAny'], // Delete Users
        154 => ['widgets.bank_totals'], // View Gateway Balances
        158 => ['invoices.viewAny'], // View Invoice
    ];

    /**
     * @var array<int, string> the WHMCS permissions MyCS has nothing for, by their WHMCS name, and those whose closest
     *                         MyCS permission would give more (review of #649): «Edit Transaction» (MyCS's
     *                         invoice_transactions.update is the refund), «Add/Delete Client Notes» (the whole client),
     *                         «Module Command Operations» and «Create Upgrade/Downgrade Orders» (the whole service),
     *                         «Create Add Funds Invoice» (credits.create is free credit), «Cancel Invoice» (the whole invoice)
     */
    public const UNMAPPED = [
        14 => 'Perform Module Command Operations', 19 => 'Add/Edit Client Notes', 30 => 'Edit Transaction',
        97 => 'Delete Client Notes', 99 => 'Create Upgrade/Downgrade Orders', 149 => 'Create Add Funds Invoice',
        159 => 'Cancel Invoice',
        17 => 'Delete Clients Domains', 23 => 'Manage Affiliates', 37 => 'Offline Credit Card Processing',
        39 => 'Manage Announcements', 41 => 'Manage Downloads', 46 => 'Addon Modules', 47 => 'Link Tracking',
        49 => 'Calendar', 50 => 'To-Do List', 51 => 'WHOIS Lookups', 52 => 'Domain Resolver Checker',
        53 => 'View Integration Code', 54 => 'WHM Import Script', 55 => 'Database Status', 57 => 'View PHP Info',
        62 => 'View WHOIS Lookup Log', 72 => 'Configure Product Addons', 79 => 'Configure Fraud Protection',
        82 => 'View Flagged Tickets', 83 => 'Configure Database Backups', 84 => 'Manage Network Issues',
        87 => 'Configure Security Questions', 88 => 'Mass Data Export', 89 => 'View Billable Items',
        90 => 'Manage Billable Items', 95 => 'Manage Clients Files', 96 => 'Configure Ticket Statuses',
        102 => 'Configure Product Bundles', 106 => 'Decrypt Full Credit Card Number', 110 => 'View Banned IPs',
        111 => 'Add Banned IP', 112 => 'Unban Banned IP', 115 => 'Manage Email Template Languages',
        121 => 'Access All Tickets Directly', 122 => 'Configure Order Statuses', 123 => 'Attempts CC Captures',
        124 => 'Generate Due Invoices', 127 => 'Configure Two-Factor Authentication', 130 => 'Use Any Promotion Code on Order',
        131 => 'WHMCS Connect', 133 => 'Configure Application Links', 134 => 'Configure OpenID Connect',
        135 => 'Update WHMCS', 136 => 'Modify Update Configuration', 139 => 'View What\'s New',
        140 => 'Configure Escalation Rules', 141 => 'Manage MarketConnect', 143 => 'Configure Sign-In Integration',
        145 => 'View MarketConnect Balance', 146 => 'Client Data Export', 147 => 'Manage Storage Settings',
        148 => 'Apps & Integrations', 155 => 'List Disputes', 156 => 'Manage Disputes', 157 => 'Close Disputes',
        160 => 'View Scheduled Ticket Actions', 161 => 'Create Scheduled Ticket Actions',
        162 => 'Edit Scheduled Ticket Actions', 163 => 'Cancel Scheduled Ticket Actions',
    ];

    /**
     * @param  iterable<int|string>  $permIds  a role's `tbladminperms.permid`s
     * @return array{permissions: list<string>, unmapped: list<string>} MyCS's permissions (its defaults among them),
     *                                                                  and the WHMCS ones left out, by name
     */
    public static function map(iterable $permIds): array
    {
        $permissions = RoleResource::DEFAULT_PERMISSIONS;
        $unmapped = [];
        foreach ($permIds as $permId) {
            $permId = (int) $permId;
            if (isset(self::MAP[$permId])) {
                array_push($permissions, ...array_map(fn (string $permission) => 'admin.' . $permission, self::MAP[$permId]));
            } else {
                $unmapped[$permId] = self::UNMAPPED[$permId] ?? 'WHMCS permission #' . $permId;
            }
        }
        $permissions = array_values(array_unique($permissions));
        sort($permissions);
        ksort($unmapped);

        return ['permissions' => $permissions, 'unmapped' => array_values($unmapped)];
    }
}
