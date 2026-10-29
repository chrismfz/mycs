<?php

return [
    'role' => [
        '*' => 'All Permissions',

        'admin' => [
            'settings' => [
                'view' => 'View Settings',
                'update' => 'Update Settings',
            ],
            // The customer accounts (docs/accounts.md, PR 2a), WHMCS «Clients».
            'clients' => [
                'create' => 'Create Clients',
                'update' => 'Update Clients',
                'viewAny' => 'View Clients',
                'delete' => 'Delete Clients',
            ],
            // The logins of the client area, WHMCS «Users».
            'users' => [
                'create' => 'Create Users',
                'update' => 'Update Users',
                'viewAny' => 'View Users',
                'delete' => 'Delete Users',
                'impersonate' => 'Login as Client',
                'deleteAny' => 'Bulk Delete Users',
            ],
            'credits' => [
                'create' => 'Create Credits',
                'update' => 'Update Credits',
                'viewAny' => 'View Credits',
                'delete' => 'Delete Credits',
            ],
            'billing_agreements' => [
                'viewAny' => 'View Billing Agreements',
            ],
            'invoices' => [
                'create' => 'Create Invoices',
                'update' => 'Update Invoices',
                'viewAny' => 'View Invoices',
                'delete' => 'Delete Invoices',
                'deleteAny' => 'Bulk Delete Invoices',
            ],
            'invoice_transactions' => [
                'create' => 'Create Invoice Transactions',
                'update' => 'Update Invoice Transactions',
                'viewAny' => 'View Invoice Transactions',
                'delete' => 'Delete Invoice Transactions',
                'deleteAny' => 'Bulk Delete Invoice Transactions',
            ],
            'products' => [
                'create' => 'Create Products',
                'update' => 'Update Products',
                'viewAny' => 'View Products',
                'delete' => 'Delete Products',
                'deleteAny' => 'Bulk Delete Products',
            ],
            'categories' => [
                'create' => 'Create Categories',
                'update' => 'Update Categories',
                'viewAny' => 'View Categories',
                'delete' => 'Delete Categories',
                'deleteAny' => 'Bulk Delete Categories',
            ],
            'tickets' => [
                'create' => 'Create Tickets',
                'update' => 'Update Tickets',
                'viewAny' => 'View Tickets',
                'delete' => 'Delete Tickets',
                'deleteAny' => 'Bulk Delete Tickets',
            ],
            'ticket_messages' => [
                'delete' => 'Delete Ticket Messages',
            ],
            'orders' => [
                'create' => 'Create Orders',
                'update' => 'Update Orders',
                'viewAny' => 'View Orders',
                'delete' => 'Delete Orders',
                'deleteAny' => 'Bulk Delete Orders',
            ],
            'services' => [
                'create' => 'Create Services',
                'update' => 'Update Services',
                'viewAny' => 'View Services',
                'delete' => 'Delete Services',
                'deleteAny' => 'Bulk Delete Services',
                // To another client, with its invoices not paid yet (owner 2026-10-23).
                'move' => 'Move Services to Another Client',
                // Into the account's panel signed in, as its owner (owner 2026-10-23): audited.
                'sso' => 'Log in to a Service\'s Panel',
                // An SSL certificate's private key, seen or downloaded (owner 2026-10-24): audited each time.
                'ssl_keys' => 'View and Download SSL Private Keys',
                // Utilities → SSL Checker: any key, CSR and certificate pasted and checked, nothing kept (owner 2026-10-24).
                'ssl_checker' => 'Use the SSL Checker',
            ],
            // Domains (docs/domains.md): seen in D1; their actions come with D2–D5.
            'domains' => [
                'viewAny' => 'View Domains',
                'create' => 'Add Existing Domains',
                'update' => 'Update Domains (sync, auto renew, admin notes)',
                // D4b: changes at the registrar (nameservers, lock, privacy, contacts), and its EPP code apart: it hands
                // the domain over.
                'manage' => 'Manage Domains at the Registrar',
                'epp' => 'Get EPP Codes',
                // D5b-2: a renewal at the registrar before the payment (D-1, trusted customers): real money.
                'renew' => 'Renew Domains Now (before payment)',
                // To another client, with its invoices not paid yet and its contacts copied (owner 2026-10-23).
                'move' => 'Move Domains to Another Client',
                // A .gr change of holder (G8-5b): charged, never recalled at the registry.
                'owner_change' => 'Change a .gr Domain\'s Holder',
            ],
            // The TLDs sold and their prices (docs/domains.md D-6, D-7).
            'domain_tlds' => [
                'viewAny' => 'View Domain Pricing',
                'create' => 'Create TLDs',
                'update' => 'Update TLDs and their Prices',
                'delete' => 'Delete TLDs',
            ],
            // Accounts at registrars and their credentials (docs/domains.md D-21).
            'domain_registrars' => [
                'viewAny' => 'View Domain Registrars',
                'create' => 'Create Domain Registrars',
                'update' => 'Update Domain Registrars',
                'delete' => 'Delete Domain Registrars',
            ],
            'service_cancellations' => [
                'create' => 'Create Service Cancellations',
                'update' => 'Update Service Cancellations',
                'viewAny' => 'View Service Cancellations',
                'delete' => 'Delete Service Cancellations',
                'deleteAny' => 'Bulk Delete Service Cancellations',
            ],
            'custom_properties' => [
                'create' => 'Create Custom Properties',
                'update' => 'Update Custom Properties',
                'viewAny' => 'View Custom Properties',
                'delete' => 'Delete Custom Properties',
                'deleteAny' => 'Bulk Delete Custom Properties',
            ],
            'ticket_quarantine' => [
                'viewAny' => 'View the Mail Quarantine',
                'update' => 'Handle Quarantined Mail (attach, open, dismiss, block)',
            ],
            'ticket_blocked_senders' => [
                'viewAny' => 'View Blocked Senders',
                'create' => 'Block Senders',
                'delete' => 'Unblock Senders',
            ],
            'knowledgebase' => [
                'viewAny' => 'View Knowledgebase',
                'create' => 'Create Knowledgebase Articles and Categories',
                'update' => 'Update Knowledgebase Articles and Categories',
                'delete' => 'Delete Knowledgebase Articles and Categories',
            ],
            'canned_replies' => [
                'viewAny' => 'View Predefined Replies',
                'create' => 'Create Predefined Replies',
                'update' => 'Update Predefined Replies',
                'delete' => 'Delete Predefined Replies',
            ],
            'ticket_departments' => [
                'create' => 'Create Ticket Departments',
                'update' => 'Update Ticket Departments',
                'viewAny' => 'View Ticket Departments',
                'delete' => 'Delete Ticket Departments',
            ],
            'currencies' => [
                'create' => 'Create Currencies',
                'update' => 'Update Currencies',
                'viewAny' => 'View Currencies',
                'delete' => 'Delete Currencies',
                'deleteAny' => 'Bulk Delete Currencies',
            ],
            'cron_stats' => [
                'view' => 'View Cron Stats',
            ],
            'debug_logs' => [
                'view' => 'View Debug Logs',
                'delete' => 'Delete Debug Logs',
            ],
            'staff' => [
                'create' => 'Create Staff',
                'update' => 'Update Staff',
                'viewAny' => 'View Staff',
                'delete' => 'Delete Staff',
                // See and end other members' sessions (a lost laptop); everyone sees and ends their own.
                'sessions' => 'View and End Staff Sessions',
            ],
            'roles' => [
                'create' => 'Create Roles',
                'update' => 'Update Roles',
                'viewAny' => 'View Roles',
                'delete' => 'Delete Roles',
                'deleteAny' => 'Bulk Delete Roles',
            ],
            'coupons' => [
                'create' => 'Create Coupons',
                'update' => 'Update Coupons',
                'viewAny' => 'View Coupons',
                'delete' => 'Delete Coupons',
                'deleteAny' => 'Bulk Delete Coupons',
            ],
            'reports' => [
                'view' => 'View Reports',
            ],
            'bulk_mails' => [
                'create' => 'Create Mass Mails',
                'update' => 'Update Mass Mails',
                'viewAny' => 'View Mass Mails',
                'delete' => 'Delete Mass Mails',
                'send' => 'Send Mass Mails',
            ],
            'offers' => [
                'create' => 'Create Offers',
                'update' => 'Update Offers',
                'viewAny' => 'View Offers',
                'delete' => 'Delete Offers',
                'deleteAny' => 'Bulk Delete Offers',
            ],
            'client_groups' => [
                'create' => 'Create Client Groups',
                'update' => 'Update Client Groups',
                'viewAny' => 'View Client Groups',
                'delete' => 'Delete Client Groups',
                'deleteAny' => 'Bulk Delete Client Groups',
            ],
            'config_options' => [
                'create' => 'Create Config Options',
                'update' => 'Update Config Options',
                'viewAny' => 'View Config Options',
                'delete' => 'Delete Config Options',
                'deleteAny' => 'Bulk Delete Config Options',
            ],
            'tax_rates' => [
                'create' => 'Create Tax Rates',
                'update' => 'Update Tax Rates',
                'viewAny' => 'View Tax Rates',
                'delete' => 'Delete Tax Rates',
                'deleteAny' => 'Bulk Delete Tax Rates',
            ],
            'adjustment_notes' => [
                'create' => 'Create Adjustment Notes',
                'update' => 'Update Adjustment Notes',
                'viewAny' => 'View Adjustment Notes',
                'delete' => 'Delete Adjustment Notes',
                'deleteAny' => 'Bulk Delete Adjustment Notes',
            ],
            'gateways' => [
                'create' => 'Create Gateways',
                'update' => 'Update Gateways',
                'viewAny' => 'View Gateways',
                'delete' => 'Delete Gateways',
                'deleteAny' => 'Bulk Delete Gateways',
            ],
            // Staff notifications to Mattermost/Slack (sweep of myip, 2026-10-25).
            'chat_notifications' => [
                'viewAny' => 'View Chat Notifications',
                'create' => 'Create Chat Notifications',
                'update' => 'Update Chat Notifications',
                'delete' => 'Delete Chat Notifications',
            ],
            'servers' => [
                'create' => 'Create Servers',
                'update' => 'Update Servers',
                'viewAny' => 'View Servers',
                'delete' => 'Delete Servers',
                'deleteAny' => 'Bulk Delete Servers',
            ],
            'api_keys' => [
                'create' => 'Create API Keys',
                'update' => 'Update API Keys',
                'viewAny' => 'View API Keys',
                'delete' => 'Delete API Keys',
                'deleteAny' => 'Bulk Delete API Keys',
            ],
            'extensions' => [
                'update' => 'Update Extensions',
                'viewAny' => 'View Extensions',
                'install' => 'Install Extensions',
                'delete' => 'Delete Extensions',
            ],
            // Audit log (read-only: audits are never edited or deleted, the money trail's append-only rule).
            'audit' => [
                'viewAny' => 'View Audit Log',
            ],
            'notification_templates' => [
                'viewAny' => 'View Notification Templates',
                'create' => 'Create Notification Templates',
                'update' => 'Update Notification Templates',
                'delete' => 'Delete Notification Templates',
                'deleteAny' => 'Bulk Delete Notification Templates',
            ],
            'failed_jobs' => [
                'viewAny' => 'View Failed Jobs',
                'retry' => 'Retry Failed Jobs',
                'delete' => 'Delete Failed Jobs',
            ],
            'email_logs' => [
                'viewAny' => 'View Email Logs',
                'view' => 'View Email Log',
                'resend' => 'Resend Email',
            ],
            'gateway_events' => [
                'viewAny' => 'View Gateway Log',
            ],
            // The MCP server's ops tools (docs/mcp.md): each also needs the permission of what it shows.
            'mcp' => [
                'use' => 'Use the MCP Server',
            ],
            // System → System Cleanup: delete logs by hand (App\Support\Retention).
            'system_cleanup' => [
                'run' => 'Run System Cleanup',
            ],
            // Who of the staff is in, in the sidebar and on the dashboard (docs/livechat.md §7β); every role by default.
            'staff_online' => [
                'view' => 'View Staff Online',
            ],
            // The staff's own chat on the Live chat page (docs/livechat.md §7γ); every role by default.
            'staff_chat' => [
                'view' => 'View the Staff Chat',
                'post' => 'Write in the Staff Chat',
            ],
            // Live chat with customers (docs/livechat.md, step 3α): in the departments that list the member (F3).
            'chats' => [
                'view' => 'View customers\' live chats',
                'reply' => 'Answer customers\' live chats',
            ],
            'widgets' => [
                'revenue' => 'View Revenue Widget',
                'overview' => 'View Overview Widget',
                'support' => 'View Tickets Widget',
                'active_users' => 'View Active Users Widget',
                // Renewals due soon and records to check (owner 2026-10-17).
                'renewals' => 'View Renewals and Records to Check Widgets',
                // What is left with each registrar (G8-1, owner 2026-10-19).
                'registrar_credit' => 'View Registrar Credit Widget',
                // The bank alerts' credits (owner 2026-10-24: staff see them; the balances are a separate permission).
                'payments_tracking' => 'View Payments Tracking Widget',
                'bank_totals' => 'View Bank Totals Widget (bank, Stripe and PayPal balances)',
            ],
            'bank_payments' => [
                'update' => 'Acknowledge bank payments (Payments tracking)',
            ],
        ],
    ],
    'api' => [
        'admin' => [
            'mcp' => [
                'use' => 'Use the MCP Server',
            ],
            'clients' => [
                'create' => 'Create Clients',
                'update' => 'Update Clients',
                'view' => 'View Clients',
                'delete' => 'Delete Clients',
            ],
            'users' => [
                'create' => 'Create Users',
                'update' => 'Update Users',
                'view' => 'View Users',
                'delete' => 'Delete Users',
                'impersonate' => 'Impersonate Users',
            ],
            'invoices' => [
                'create' => 'Create Invoices',
                'update' => 'Update Invoices',
                'view' => 'View Invoices',
                'delete' => 'Delete Invoices',
            ],
            'invoice_items' => [
                'create' => 'Create Invoice Items',
                'update' => 'Update Invoice Items',
                'view' => 'View Invoice Items',
                'delete' => 'Delete Invoice Items',
            ],
            'tickets' => [
                'create' => 'Create Tickets',
                'update' => 'Update Tickets',
                'view' => 'View Tickets',
                'delete' => 'Delete Tickets',
            ],
            'ticket_messages' => [
                'create' => 'Create Ticket Messages',
                'view' => 'View Ticket Messages',
                'delete' => 'Delete Ticket Messages',
            ],
            'orders' => [
                'create' => 'Create Orders',
                'update' => 'Update Orders',
                'view' => 'View Orders',
                'delete' => 'Delete Orders',
            ],
            'services' => [
                'create' => 'Create Services',
                'update' => 'Update Services',
                'view' => 'View Services',
                'delete' => 'Delete Services',
            ],
            'coupons' => [
                'create' => 'Create Coupons',
                'update' => 'Update Coupons',
                'view' => 'View Coupons',
                'delete' => 'Delete Coupons',
            ],
            'credits' => [
                'create' => 'Create Credits',
                'update' => 'Update Credits',
                'view' => 'View Credits',
                'delete' => 'Delete Credits',
            ],
            'products' => [
                'view' => 'View Products',
            ],
            'categories' => [
                'view' => 'View Categories',
            ],
            'properties' => [
                'view' => 'View Custom Properties',
            ],
            'staff' => [
                'view' => 'View Staff',
            ],
            'roles' => [
                'view' => 'View Roles',
                'update' => 'Update Roles',
            ],
        ],
    ],
];
