<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmailTemplateSeeder extends Seeder
{
    public const mapping = [
        'new_login_detected' => [
            'subject' => 'New login to your account',
            'body' => <<<'HTML'
                # New login to your account

                Dear {{ user.first_name }},

                A new login to your account was detected.

                - IP address: {{ ip }}
                - Device: {{ device }}
                - Time: {{ time }}

                **Was it you?** No action is needed.

                **Wasn't it you?** Change your password right away.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.password_reset }}">
                		Change password
                	</a>
                </div>
                HTML,
            'in_app_title' => 'New login to your account',
            'in_app_body' => 'New login from IP {{ ip }} ({{ device }}) at {{ time }}.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_off',
            'edit_preference_message' => 'Alert me about new login attempts',
            'in_app_url' => '{{ urls.security }}',
        ],
        'new_invoice_created' => [
            'subject' => 'New invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ total }}',
            'body' => <<<'HTML'
                # New invoice #{{ invoice_number }}

                Dear {{ user.first_name }},

                A new invoice was issued for your account.

                - Invoice: **#{{ invoice_number }}**
                {% if tax %}
                - Subtotal: **{{ subtotal }}**
                - Tax: **{{ tax }}**
                {% endif %}
                - Total: **{{ total }}**
                {% if partly_paid %}
                - Left to pay: **{{ remaining }}**
                {% endif %}
                - Due date: **{{ invoice.due_at|date("d/m/Y") }}**

                <div class="table">

                | Description | Qty | Price |
                | :--- | :---: | ---: |
                {% for item in items %}
                | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>

                {% if has_subscription %}
                It will be paid automatically with your saved payment method: there is nothing you need to do.
                {% else %}
                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View & pay invoice
                	</a>
                </div>
                {% endif %}

                If you have already paid, please ignore this message.
                HTML,
            'in_app_title' => 'New invoice #{{ invoice_number }}',
            'in_app_body' => 'Invoice #{{ invoice_number }} of {{ total }}, due {{ invoice.due_at|date("d/m/Y") }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about new invoices',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'invoice_paid' => [
            'subject' => 'Payment received: invoice #{{ invoice_number }} | {{ total }}',
            'body' => <<<'HTML'
                # Payment received

                Dear {{ user.first_name }},

                We received your payment for invoice **#{{ invoice_number }}**. Thank you! This email confirms your payment.

                - Invoice: **#{{ invoice_number }}**
                {% if tax %}
                - Subtotal: **{{ subtotal }}**
                - Tax: **{{ tax }}**
                {% endif %}
                - Total: **{{ total }}**
                {% if partly_paid %}
                - Left to pay: **{{ remaining }}**
                {% endif %}

                <div class="table">

                | Description | Qty | Price |
                | :--- | :---: | ---: |
                {% for item in items %}
                | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View invoice
                	</a>
                </div>

                You can see all your payments in your client area at any time.
                HTML,
            'in_app_title' => 'Payment received: invoice #{{ invoice_number }}',
            'in_app_body' => 'Your payment for invoice #{{ invoice_number }} ({{ total }}) was received. Thank you!',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about successful payments',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'credit_applied' => [
            'subject' => 'Account credit applied to invoice #{{ invoice_number }}',
            'body' => <<<'HTML'
                # Account credit applied

                Dear {{ user.first_name }},

                Your account credit paid **{{ credit.applied }}** of invoice #{{ invoice_number }}.

                Left to pay: **{{ credit.remaining }}**

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View invoice
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Account credit applied',
            'in_app_body' => 'Your account credit paid {{ credit.applied }} of invoice #{{ invoice_number }}. Left to pay: {{ credit.remaining }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me when my account credit pays part of an invoice',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'invoice_refunded' => [
            'subject' => 'Refund for invoice #{{ invoice_number }}',
            'body' => <<<'HTML'
                # Refund for invoice #{{ invoice_number }}

                Dear {{ user.first_name }},

                {% if refund.to_credit %}
                **{{ refund.amount }}** of your payment for invoice #{{ invoice_number }} was added to your account credit.
                {% else %}
                **{{ refund.amount }}** of your payment for invoice #{{ invoice_number }} was refunded to you.
                {% endif %}

                {% if refund.by_gateway %}
                It goes back to the card or account you paid with; your bank may take a few days to show it.
                {% endif %}

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View invoice
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Refund for invoice #{{ invoice_number }}',
            'in_app_body' => '{% if refund.to_credit %}{{ refund.amount }} of your payment was added to your account credit.{% else %}{{ refund.amount }} of your payment was refunded to you.{% endif %}',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me when a payment of mine is refunded',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'invoice_payment_failed' => [
            'subject' => 'Payment failed: invoice #{{ invoice_number }} | {{ total }}',
            'body' => <<<'HTML'
                # Payment failed

                Dear {{ user.first_name }},

                The automatic charge for invoice **#{{ invoice_number }}** was not successful, and you have **not** been charged.

                - Invoice: **#{{ invoice_number }}**
                {% if tax %}
                - Subtotal: **{{ subtotal }}**
                - Tax: **{{ tax }}**
                {% endif %}
                - Total: **{{ total }}**
                {% if partly_paid %}
                - Left to pay: **{{ remaining }}**
                {% endif %}
                - Due date: **{{ invoice.due_at|date("d/m/Y") }}**

                **What you need to do**
                - Pay the invoice from your client area.
                - While paying you can update your card details if needed.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		Pay invoice
                	</a>
                </div>

                If you need help with the payment, please contact us.
                HTML,
            'in_app_title' => 'Payment failed: invoice #{{ invoice_number }}',
            'in_app_body' => 'The payment for invoice #{{ invoice_number }} failed. Please pay it to avoid any interruption of your services.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Alert me about payment failures',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'new_order_created' => [
            'subject' => 'Order confirmation #{{ order.id }}',
            'body' => <<<'HTML'
                # Thank you for your order

                Dear {{ user.first_name }},

                We received your order **#{{ order.id }}**. We will process it as soon as its payment is complete.

                <div class="table">

                | Product | Qty | Price |
                | :--- | :---: | ---: |
                {% for item in items %}
                | {{ item.product.name }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>

                Total: **{{ total }}**

                <div class="action">
                	<a class="button button-blue" href="{{ urls.services }}">
                		My services
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Order confirmation #{{ order.id }}',
            'in_app_body' => 'We received your order #{{ order.id }} ({{ total }}).',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Send me order confirmations',
            'in_app_url' => '{{ urls.services }}',
        ],
        'new_server_created' => [
            'subject' => 'Your service is active: {{ service.product.name }}',
            'body' => <<<'HTML'
                # Your service is active

                Dear {{ user.first_name }},

                Thank you for choosing {{ app.name }}. Your service is ready. Please keep this email: it has what you need to get started.

                - Service: **{{ service.product.name }}**
                {% if service.expires_at %}
                - Renewal date: **{{ service.expires_at|date("d/m/Y") }}**
                {% endif %}

                {% if service.username %}
                **Login details**
                - Username: {{ service.username }}
                {% if service_password %}
                - Password: {{ service_password }}
                {% endif %}
                {% endif %}

                {% if product_info %}
                {{ product_info }}
                {% endif %}

                <div class="action">
                	<a class="button button-blue" href="{{ urls.service }}">
                		Manage service
                	</a>
                </div>

                If you have any trouble or questions, we are here to help.
                HTML,
            'in_app_title' => 'Your service is active',
            'in_app_body' => 'Your service {{ service.product.name }} is active.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about new service activations',
            'in_app_url' => '{{ urls.service }}',
        ],
        'server_suspended' => [
            'subject' => 'Service suspended: {{ service.product.name }}',
            'body' => <<<'HTML'
                # Service suspended

                Dear {{ user.first_name }},

                {% if service.dunning_suspended_at %}
                Your service **{{ service.product.name }}** was suspended because its renewal is overdue.

                Once its invoice is paid, the service is reactivated automatically, usually within minutes.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoices }}">
                		View my invoices
                	</a>
                </div>
                {% else %}
                Your service **{{ service.product.name }}** was suspended.
                {% if service.suspend_reason %}

                Reason: {{ service.suspend_reason }}
                {% endif %}{% endif %}

                If you think this is a mistake, please contact us.
                HTML,
            'in_app_title' => 'Service suspended',
            'in_app_body' => 'Your service {{ service.product.name }} was suspended{% if service.dunning_suspended_at %} because its renewal is overdue. Pay its invoice to reactivate it{% endif %}.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Alert me about service suspensions',
            'in_app_url' => '{{ urls.service }}',
        ],
        'server_unsuspended' => [
            'subject' => 'Service reactivated: {{ service.product.name }}',
            'body' => <<<'HTML'
                # Service reactivated

                Dear {{ user.first_name }},

                Your service **{{ service.product.name }}** is active again. Thank you!

                <div class="action">
                	<a class="button button-blue" href="{{ urls.service }}">
                		Manage service
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Service reactivated',
            'in_app_body' => 'Your service {{ service.product.name }} is active again.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me when a suspended service is reactivated',
            'in_app_url' => '{{ urls.service }}',
        ],
        'server_terminated' => [
            'subject' => 'Service terminated: {{ service.product.name }}',
            'body' => <<<'HTML'
                # Service terminated

                Dear {{ user.first_name }},

                Your service **{{ service.product.name }}** has been terminated.

                If you think this is a mistake, please contact us as soon as possible.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.new_ticket }}">
                		Contact us
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Service terminated',
            'in_app_body' => 'Your service {{ service.product.name }} has been terminated.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Alert me about service terminations',
            'in_app_url' => '{{ urls.service }}',
        ],
        'new_ticket_message' => [
            'subject' => '[Ticket #{{ ticketMessage.ticket_id }}] {{ ticketMessage.ticket.subject }}',
            'body' => <<<'HTML'
                # New reply to your ticket

                {{ ticketMessage.user.name }} replied to your ticket **#{{ ticketMessage.ticket_id }}**:

                {{ message_html }}

                <div class="action">
                	<a class="button button-blue" href="{{ urls.ticket }}">
                		View ticket
                	</a>
                </div>
                HTML,
            'in_app_title' => 'New reply to ticket #{{ ticketMessage.ticket_id }}',
            'in_app_body' => 'You have a new reply on your ticket #{{ ticketMessage.ticket_id }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about ticket replies',
            'in_app_url' => '{{ urls.ticket }}',
        ],
        'email_verification' => [
            'subject' => 'Welcome to {{ app.name }}: please verify your email',
            'body' => <<<'HTML'
                # Welcome to {{ app.name }}

                Dear {{ user.first_name }},

                Your account was created. Please verify your email address to confirm it is yours.

                <div class="action">
                	<a class="button button-blue" href="{{ url }}">
                		Verify email address
                	</a>
                </div>

                The link is valid for 60 minutes. If it has expired, log in to your client area to get a new one.

                **In your client area you can**
                - order and renew services
                - view and pay invoices
                - open support tickets

                If you did not create an account, please ignore this email.
                HTML,
            'mail_enabled' => 'force',
            'in_app_enabled' => 'never',
        ],
        'password_reset' => [
            'subject' => 'Password reset',
            'body' => <<<'HTML'
                # Password reset

                Dear {{ user.first_name }},

                We received a request to reset the password of your account.

                <div class="action">
                	<a class="button button-blue" href="{{ url }}">
                		Reset password
                	</a>
                </div>

                The link is valid for 60 minutes. If you did not ask for a password reset, no action is needed.
                HTML,
            'mail_enabled' => 'force',
            'in_app_enabled' => 'never',
        ],
        'card_expiring' => [
            'subject' => 'Your saved payment method expires soon',
            'body' => <<<'HTML'
                # Your saved payment method expires soon

                Dear {{ user.first_name }},

                Your saved payment method **{{ method_name }}** expires at the end of **{{ expiry }}**.

                After that, your invoices can no longer be charged to it automatically. Please add a new payment method before then, so that your services renew without interruption.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.payment_methods }}">
                		Update payment method
                	</a>
                </div>
                HTML,
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'in_app_title' => 'Saved payment method expires soon',
            'in_app_body' => 'Your saved payment method {{ method_name }} expires at the end of {{ expiry }}.',
            'edit_preference_message' => 'Notify me when a saved payment method of mine is about to expire',
            'in_app_url' => '{{ urls.payment_methods }}',
        ],
        'service_cancelled' => [
            'subject' => 'Service cancelled: {{ service.product.name }}',
            'body' => <<<'HTML'
                # Service cancelled

                Dear {{ user.first_name }},

                As you asked, your service **{{ service.product.name }}** has ended at the end of its billing period.

                Thank you for using our services; we hope to see you again.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.new_ticket }}">
                		Contact us
                	</a>
                </div>
                HTML,
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'in_app_title' => 'Service cancelled',
            'in_app_body' => 'Your service {{ service.product.name }} has ended, as you asked.',
            'edit_preference_message' => 'Notify me when a service I cancelled ends',
            'in_app_url' => '{{ urls.service }}',
        ],
        'service_cancellation_received' => [
            'subject' => 'Cancellation request confirmed: {{ service.product.name }}',
            'body' => <<<'HTML'
                # Cancellation request confirmed

                Dear {{ user.first_name }},

                We received your cancellation request.

                - Service: **{{ service.product.name }}**
                {% if cancellation.reason %}
                - Reason: {{ cancellation.reason }}
                {% endif %}
                - Requested: {{ cancellation.created_at|date("d/m/Y H:i") }}

                {% if cancellation.type == "end_of_period" %}
                {% if service.expires_at %}
                Your service stays active until **{{ service.expires_at|date("d/m/Y") }}**, the end of its current billing period, and ends then.
                {% else %}
                Your service ends at the end of its current billing period.
                {% endif %}
                {% else %}
                Your service ends right away. Please make sure you have a backup of your data.
                {% endif %}

                Thank you for using our services; we hope to see you again. If you would like to withdraw this request, contact us as soon as possible.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.new_ticket }}">
                		Contact us
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Cancellation request confirmed',
            'in_app_body' => 'We received your cancellation request for {{ service.product.name }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about service cancellations',
            'in_app_url' => '{{ urls.service }}',
        ],
        'invoice_payment_reminder' => [
            'subject' => 'Reminder: invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ total }}',
            'body' => <<<'HTML'
                # Payment reminder

                Dear {{ user.first_name }},

                This is a reminder that invoice **#{{ invoice_number }}** is due on **{{ invoice.due_at|date("d/m/Y") }}**. If you have already paid it, please ignore this message.

                - Invoice: **#{{ invoice_number }}**
                {% if tax %}
                - Subtotal: **{{ subtotal }}**
                - Tax: **{{ tax }}**
                {% endif %}
                - Total: **{{ total }}**
                {% if partly_paid %}
                - Left to pay: **{{ remaining }}**
                {% endif %}
                - Due date: **{{ invoice.due_at|date("d/m/Y") }}**

                <div class="table">

                | Description | Qty | Price |
                | :--- | :---: | ---: |
                {% for item in items %}
                | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View & pay invoice
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Payment reminder: invoice #{{ invoice_number }}',
            'in_app_body' => 'Invoice #{{ invoice_number }} of {{ total }} is due on {{ invoice.due_at|date("d/m/Y") }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Remind me of invoices about to fall due',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'card_payment_due' => [
            'subject' => 'Upcoming charge: invoice #{{ invoice_number }} | {{ total }}',
            'body' => <<<'HTML'
                # Upcoming automatic payment

                Dear {{ user.first_name }},

                Invoice **#{{ invoice_number }}** of **{{ total }}** will be charged automatically on **{{ invoice.due_at|date("d/m/Y") }}** with your saved payment method. There is nothing you need to do.

                If your card has changed or expired, please update it, or pay the invoice with another method, before that date.

                <div class="table">

                | Description | Qty | Price |
                | :--- | :---: | ---: |
                {% for item in items %}
                | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View invoice
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Upcoming charge: invoice #{{ invoice_number }}',
            'in_app_body' => 'Invoice #{{ invoice_number }} ({{ total }}) will be charged automatically on {{ invoice.due_at|date("d/m/Y") }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Tell me before an automatic payment',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'invoice_overdue' => [
            'subject' => 'Overdue: invoice #{{ invoice_number }} | {{ total }}',
            'body' => <<<'HTML'
                # Overdue invoice

                Dear {{ user.first_name }},

                Invoice **#{{ invoice_number }}** was due on **{{ invoice.due_at|date("d/m/Y") }}** and is now {{ overdue.days }} day(s) overdue. If you have already paid it, please ignore this message: the payment may still be on its way.

                - Invoice: **#{{ invoice_number }}**
                {% if tax %}
                - Subtotal: **{{ subtotal }}**
                - Tax: **{{ tax }}**
                {% endif %}
                - Total: **{{ total }}**
                {% if partly_paid %}
                - Left to pay: **{{ remaining }}**
                {% endif %}
                - Was due: **{{ invoice.due_at|date("d/m/Y") }}**

                <div class="table">

                | Description | Qty | Price |
                | :--- | :---: | ---: |
                {% for item in items %}
                | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>

                {% if overdue.last %}
                **This is our last notice.**{% if overdue.suspend_on %} If it is not paid, the service is suspended on **{{ overdue.suspend_on }}**.{% endif %}
                {% endif %}

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		Pay invoice
                	</a>
                </div>

                If you have any questions or trouble paying, please contact us.
                HTML,
            'in_app_title' => 'Overdue: invoice #{{ invoice_number }}',
            'in_app_body' => 'Invoice #{{ invoice_number }} ({{ total }}) is {{ overdue.days }} day(s) overdue.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Alert me about overdue invoices',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'invoice_modified' => [
            'subject' => 'Invoice #{{ invoice_number }} updated | {{ total }}',
            'body' => <<<'HTML'
                # Invoice updated

                Dear {{ user.first_name }},

                Invoice **#{{ invoice_number }}** was updated. Here it is as it stands now.

                - Invoice: **#{{ invoice_number }}**
                {% if tax %}
                - Subtotal: **{{ subtotal }}**
                - Tax: **{{ tax }}**
                {% endif %}
                - Total: **{{ total }}**
                {% if partly_paid %}
                - Left to pay: **{{ remaining }}**
                {% endif %}
                - Due date: **{{ invoice.due_at|date("d/m/Y") }}**

                <div class="table">

                | Description | Qty | Price |
                | :--- | :---: | ---: |
                {% for item in items %}
                | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View & pay invoice
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Invoice #{{ invoice_number }} updated',
            'in_app_body' => 'Invoice #{{ invoice_number }} was updated: total {{ total }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me when an invoice of mine is updated',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'client_invite' => [
            'subject' => 'Invitation to the account {{ account.name }} at {{ app.name }}',
            'body' => <<<'HTML'
                # You are invited

                Hello,

                {{ invite.by }} invited you to manage the account **{{ account.name }}** at {{ app.name }}.

                <div class="action">
                	<a class="button button-blue" href="{{ invite.url }}">
                		Accept the invitation
                	</a>
                </div>

                Log in with your existing login, or create one, to accept. The invitation is valid until {{ invite.expires_at|date("d/m/Y H:i") }} and can be used once.

                If you did not expect this invitation, please ignore this email.
                HTML,
            'mail_enabled' => 'force',
            'in_app_enabled' => 'never',
        ],
        // Domains (D7, DomainReminders): before the expiry and after it, on the days of domain_reminder_days.
        'domain_renewal_notice' => [
            'subject' => 'Your domain {{ fqdn }} expires on {{ expires }}',
            'body' => <<<'HTML'
                # Domain renewal

                Dear {{ user.first_name }},

                Your domain **{{ fqdn }}** expires on **{{ expires }}**{% if days_left %}, in {{ days_left }} day(s){% endif %}.

                {% if invoice %}
                Its renewal is on invoice **#{{ invoice_number }}** ({{ invoice.formattedTotal }}).{% if auto_charged %} It will be charged automatically with your saved payment method.{% else %} Please pay it before the expiry date so that your domain keeps working.{% endif %}
                {% else %}
                It renews for {{ years }} year(s){% if price %} at **{{ price }}**{% endif %}. We will send you its invoice before the expiry date.
                {% endif %}

                {% if late_fee %}
                **Please renew it by {{ expires }}:** after that date its renewal costs **{{ late_fee }}** more (late renewal fee charged by the registry).
                {% endif %}

                If you do not want to keep it, let us know and we will not renew it.

                {% if invoice %}
                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		{% if auto_charged %}View invoice{% else %}View & pay invoice{% endif %}
                	</a>
                </div>
                {% endif %}
                HTML,
            'in_app_title' => '{{ fqdn }} expires on {{ expires }}',
            'in_app_body' => 'Your domain {{ fqdn }} expires on {{ expires }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Remind me before my domains expire',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'domain_free_renewal_notice' => [
            'subject' => 'Your domain {{ fqdn }} renews on {{ expires }}',
            'body' => <<<'HTML'
                # Domain renewal

                Dear {{ user.first_name }},

                Your domain **{{ fqdn }}** expires on **{{ expires }}**{% if days_left %}, in {{ days_left }} day(s){% endif %}. Its renewal is free with your hosting: there is nothing to pay, it renews on its own.

                If you do not want to keep it, let us know and we will not renew it.
                HTML,
            'in_app_title' => '{{ fqdn }} renews on {{ expires }}',
            'in_app_body' => 'Your domain {{ fqdn }} renews for free on {{ expires }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Remind me before my domains expire',
            'in_app_url' => '',
        ],
        'domain_expired_notice' => [
            'subject' => 'Your domain {{ fqdn }} has expired',
            'body' => <<<'HTML'
                # Expired domain

                Dear {{ user.first_name }},

                Your domain **{{ fqdn }}** expired on **{{ expires }}** and has stopped working: your website and email on it are down.

                It can still be renewed until **{{ last_day }}**{% if late_fee %}, now with a late renewal fee of **{{ late_fee }}** charged by the registry{% endif %}. After that it may be lost, and anyone can register it.

                {% if invoice %}
                Its renewal is on invoice **#{{ invoice_number }}** ({{ invoice.formattedTotal }}). Once paid, it is renewed right away.

                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View & pay invoice
                	</a>
                </div>
                {% else %}
                Contact us to renew it.
                {% endif %}
                HTML,
            'in_app_title' => '{{ fqdn }} has expired',
            'in_app_body' => 'Your domain {{ fqdn }} expired on {{ expires }}; it can be renewed until {{ last_day }}.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Tell me when a domain of mine has expired',
            'in_app_url' => '{{ urls.invoice }}',
        ],
    ];

    /**
     * The templates' texts in other languages (NotificationTemplate::TRANSLATION_LOCALES), kept apart from mapping:
     * older migrations insert mapping rows before the translations column exists.
     */
    public const translations = [
        'new_login_detected' => [
            'el' => [
                'subject' => 'Νέα σύνδεση στον λογαριασμό σας',
                'body' => <<<'HTML'
                    # Νέα σύνδεση στον λογαριασμό σας

                    Αγαπητέ/ή {{ user.first_name }},

                    Εντοπίστηκε νέα σύνδεση στον λογαριασμό σας.

                    - Διεύθυνση IP: {{ ip }}
                    - Συσκευή: {{ device }}
                    - Ώρα: {{ time }}

                    **Ήσασταν εσείς;** Δεν χρειάζεται να κάνετε κάτι.

                    **Δεν ήσασταν εσείς;** Αλλάξτε αμέσως τον κωδικό πρόσβασής σας.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.password_reset }}">
                    		Αλλαγή κωδικού
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Νέα σύνδεση στον λογαριασμό σας',
                'in_app_body' => 'Νέα σύνδεση από IP {{ ip }} ({{ device }}) στις {{ time }}.',
            ],
        ],
        'new_invoice_created' => [
            'el' => [
                'subject' => 'Νέο προτιμολόγιο #{{ invoice_number }} | Λήξη {{ invoice.due_at|date("d/m/Y") }} | {{ total }}',
                'body' => <<<'HTML'
                    # Νέο προτιμολόγιο #{{ invoice_number }}

                    Αγαπητέ/ή {{ user.first_name }},

                    Εκδόθηκε νέο προτιμολόγιο για τον λογαριασμό σας.

                    - Προτιμολόγιο: **#{{ invoice_number }}**
                    - Σύνολο: **{{ total }}**
                    {% if partly_paid %}
                    - Υπόλοιπο προς πληρωμή: **{{ remaining }}**
                    {% endif %}
                    - Ημερομηνία λήξης: **{{ invoice.due_at|date("d/m/Y") }}**

                    <div class="table">

                    | Περιγραφή | Ποσότητα | Τιμή |
                    | :--- | :---: | ---: |
                    {% for item in items %}
                    | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                    {% endfor %}
                    </div>

                    {% if has_subscription %}
                    Θα εξοφληθεί αυτόματα με τον αποθηκευμένο τρόπο πληρωμής σας: δεν χρειάζεται να κάνετε κάτι.
                    {% else %}
                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Προβολή & πληρωμή
                    	</a>
                    </div>
                    {% endif %}

                    Αν έχετε ήδη πληρώσει, παραβλέψτε αυτό το μήνυμα.
                    HTML,
                'in_app_title' => 'Νέο προτιμολόγιο #{{ invoice_number }}',
                'in_app_body' => 'Προτιμολόγιο #{{ invoice_number }} ποσού {{ total }}, λήξη {{ invoice.due_at|date("d/m/Y") }}.',
            ],
        ],
        'invoice_paid' => [
            'el' => [
                'subject' => 'Επιβεβαίωση πληρωμής: προτιμολόγιο #{{ invoice_number }} | {{ total }}',
                'body' => <<<'HTML'
                    # Η πληρωμή σας ελήφθη

                    Αγαπητέ/ή {{ user.first_name }},

                    Η πληρωμή σας για το προτιμολόγιο **#{{ invoice_number }}** ελήφθη με επιτυχία. Σας ευχαριστούμε! Αυτό το email επιβεβαιώνει την πληρωμή σας.

                    - Προτιμολόγιο: **#{{ invoice_number }}**
                    - Σύνολο: **{{ total }}**
                    {% if partly_paid %}
                    - Υπόλοιπο προς πληρωμή: **{{ remaining }}**
                    {% endif %}

                    <div class="table">

                    | Περιγραφή | Ποσότητα | Τιμή |
                    | :--- | :---: | ---: |
                    {% for item in items %}
                    | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                    {% endfor %}
                    </div>

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Προβολή προτιμολογίου
                    	</a>
                    </div>

                    Μπορείτε να δείτε το ιστορικό των πληρωμών σας ανά πάσα στιγμή από την περιοχή πελατών.
                    HTML,
                'in_app_title' => 'Επιβεβαίωση πληρωμής: #{{ invoice_number }}',
                'in_app_body' => 'Η πληρωμή σας για το προτιμολόγιο #{{ invoice_number }} ({{ total }}) ελήφθη. Σας ευχαριστούμε!',
            ],
        ],
        'credit_applied' => [
            'el' => [
                'subject' => 'Χρήση πίστωσης στο προτιμολόγιο #{{ invoice_number }}',
                'body' => <<<'HTML'
                    # Χρήση πίστωσης λογαριασμού

                    Αγαπητέ/ή {{ user.first_name }},

                    Η πίστωση του λογαριασμού σας κάλυψε **{{ credit.applied }}** του προτιμολογίου #{{ invoice_number }}.

                    Υπόλοιπο προς πληρωμή: **{{ credit.remaining }}**

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Προβολή προτιμολογίου
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Χρήση πίστωσης λογαριασμού',
                'in_app_body' => 'Η πίστωσή σας κάλυψε {{ credit.applied }} του προτιμολογίου #{{ invoice_number }}. Υπόλοιπο: {{ credit.remaining }}.',
            ],
        ],
        'invoice_refunded' => [
            'el' => [
                'subject' => 'Επιστροφή χρημάτων για το προτιμολόγιο #{{ invoice_number }}',
                'body' => <<<'HTML'
                    # Επιστροφή χρημάτων για το προτιμολόγιο #{{ invoice_number }}

                    Αγαπητέ/ή {{ user.first_name }},

                    {% if refund.to_credit %}
                    Ποσό **{{ refund.amount }}** από την πληρωμή σας για το προτιμολόγιο #{{ invoice_number }} προστέθηκε στην πίστωση του λογαριασμού σας.
                    {% else %}
                    Σας επιστράφηκε ποσό **{{ refund.amount }}** από την πληρωμή σας για το προτιμολόγιο #{{ invoice_number }}.
                    {% endif %}

                    {% if refund.by_gateway %}
                    Επιστρέφεται στην κάρτα ή στον λογαριασμό με τον οποίο πληρώσατε· η τράπεζά σας μπορεί να χρειαστεί λίγες μέρες για να το εμφανίσει.
                    {% endif %}

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Προβολή προτιμολογίου
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Επιστροφή χρημάτων: #{{ invoice_number }}',
                'in_app_body' => '{% if refund.to_credit %}Ποσό {{ refund.amount }} από την πληρωμή σας προστέθηκε στην πίστωσή σας.{% else %}Σας επιστράφηκε ποσό {{ refund.amount }} από την πληρωμή σας.{% endif %}',
            ],
        ],
        'invoice_payment_failed' => [
            'el' => [
                'subject' => 'Αποτυχία πληρωμής: προτιμολόγιο #{{ invoice_number }} | {{ total }}',
                'body' => <<<'HTML'
                    # Αποτυχία πληρωμής

                    Αγαπητέ/ή {{ user.first_name }},

                    Η αυτόματη χρέωση για το προτιμολόγιο **#{{ invoice_number }}** δεν ήταν επιτυχής και **δεν** χρεωθήκατε.

                    - Προτιμολόγιο: **#{{ invoice_number }}**
                    - Σύνολο: **{{ total }}**
                    {% if partly_paid %}
                    - Υπόλοιπο προς πληρωμή: **{{ remaining }}**
                    {% endif %}
                    - Ημερομηνία λήξης: **{{ invoice.due_at|date("d/m/Y") }}**

                    **Τι πρέπει να κάνετε**
                    - Εξοφλήστε το προτιμολόγιο από την περιοχή πελατών.
                    - Κατά την πληρωμή μπορείτε να ενημερώσετε τα στοιχεία της κάρτας σας αν χρειαστεί.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Εξόφληση προτιμολογίου
                    	</a>
                    </div>

                    Αν χρειάζεστε βοήθεια με την πληρωμή, επικοινωνήστε μαζί μας.
                    HTML,
                'in_app_title' => 'Αποτυχία πληρωμής: #{{ invoice_number }}',
                'in_app_body' => 'Η πληρωμή του προτιμολογίου #{{ invoice_number }} απέτυχε. Εξοφλήστε το για να μη διακοπούν οι υπηρεσίες σας.',
            ],
        ],
        'new_order_created' => [
            'el' => [
                'subject' => 'Επιβεβαίωση παραγγελίας #{{ order.id }}',
                'body' => <<<'HTML'
                    # Σας ευχαριστούμε για την παραγγελία σας

                    Αγαπητέ/ή {{ user.first_name }},

                    Λάβαμε την παραγγελία σας **#{{ order.id }}**. Θα την ολοκληρώσουμε μόλις εξοφληθεί.

                    <div class="table">

                    | Προϊόν | Ποσότητα | Τιμή |
                    | :--- | :---: | ---: |
                    {% for item in items %}
                    | {{ item.product.name }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                    {% endfor %}
                    </div>

                    Σύνολο: **{{ total }}**

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.services }}">
                    		Οι υπηρεσίες μου
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Επιβεβαίωση παραγγελίας #{{ order.id }}',
                'in_app_body' => 'Λάβαμε την παραγγελία σας #{{ order.id }} ({{ total }}).',
            ],
        ],
        'new_server_created' => [
            'el' => [
                'subject' => 'Η υπηρεσία σας είναι ενεργή: {{ service.product.name }}',
                'body' => <<<'HTML'
                    # Η υπηρεσία σας είναι ενεργή

                    Αγαπητέ/ή {{ user.first_name }},

                    Σας ευχαριστούμε που επιλέξατε την {{ app.name }}. Η υπηρεσία σας είναι έτοιμη. Παρακαλούμε κρατήστε αυτό το email: περιέχει όσα χρειάζεστε για να ξεκινήσετε.

                    - Υπηρεσία: **{{ service.product.name }}**
                    {% if service.expires_at %}
                    - Ημερομηνία ανανέωσης: **{{ service.expires_at|date("d/m/Y") }}**
                    {% endif %}

                    {% if service.username %}
                    **Στοιχεία σύνδεσης**
                    - Όνομα χρήστη: {{ service.username }}
                    {% if service_password %}
                    - Κωδικός: {{ service_password }}
                    {% endif %}
                    {% endif %}

                    {% if product_info %}
                    {{ product_info }}
                    {% endif %}

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.service }}">
                    		Διαχείριση υπηρεσίας
                    	</a>
                    </div>

                    Αν αντιμετωπίσετε οποιοδήποτε πρόβλημα ή έχετε απορία, είμαστε στη διάθεσή σας.
                    HTML,
                'in_app_title' => 'Η υπηρεσία σας είναι ενεργή',
                'in_app_body' => 'Η υπηρεσία σας {{ service.product.name }} είναι ενεργή.',
            ],
        ],
        'server_suspended' => [
            'el' => [
                'subject' => 'Αναστολή υπηρεσίας: {{ service.product.name }}',
                'body' => <<<'HTML'
                    # Αναστολή υπηρεσίας

                    Αγαπητέ/ή {{ user.first_name }},

                    {% if service.dunning_suspended_at %}
                    Η υπηρεσία σας **{{ service.product.name }}** τέθηκε σε αναστολή επειδή η ανανέωσή της είναι ληξιπρόθεσμη.

                    Μόλις εξοφληθεί το προτιμολόγιό της, η υπηρεσία ενεργοποιείται ξανά αυτόματα, συνήθως μέσα σε λίγα λεπτά.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoices }}">
                    		Τα προτιμολόγιά μου
                    	</a>
                    </div>
                    {% else %}
                    Η υπηρεσία σας **{{ service.product.name }}** τέθηκε σε αναστολή.
                    {% if service.suspend_reason %}

                    Λόγος: {{ service.suspend_reason }}
                    {% endif %}{% endif %}

                    Αν πιστεύετε ότι πρόκειται για λάθος, επικοινωνήστε μαζί μας.
                    HTML,
                'in_app_title' => 'Αναστολή υπηρεσίας',
                'in_app_body' => 'Η υπηρεσία σας {{ service.product.name }} τέθηκε σε αναστολή{% if service.dunning_suspended_at %} επειδή η ανανέωσή της είναι ληξιπρόθεσμη. Εξοφλήστε το προτιμολόγιό της για να ενεργοποιηθεί ξανά{% endif %}.',
            ],
        ],
        'server_unsuspended' => [
            'el' => [
                'subject' => 'Επανενεργοποίηση υπηρεσίας: {{ service.product.name }}',
                'body' => <<<'HTML'
                    # Η υπηρεσία σας ενεργοποιήθηκε ξανά

                    Αγαπητέ/ή {{ user.first_name }},

                    Η υπηρεσία σας **{{ service.product.name }}** είναι και πάλι ενεργή. Σας ευχαριστούμε!

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.service }}">
                    		Διαχείριση υπηρεσίας
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Η υπηρεσία σας ενεργοποιήθηκε ξανά',
                'in_app_body' => 'Η υπηρεσία σας {{ service.product.name }} είναι και πάλι ενεργή.',
            ],
        ],
        'server_terminated' => [
            'el' => [
                'subject' => 'Τερματισμός υπηρεσίας: {{ service.product.name }}',
                'body' => <<<'HTML'
                    # Τερματισμός υπηρεσίας

                    Αγαπητέ/ή {{ user.first_name }},

                    Η υπηρεσία σας **{{ service.product.name }}** τερματίστηκε.

                    Αν πιστεύετε ότι πρόκειται για λάθος, επικοινωνήστε μαζί μας το συντομότερο.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.new_ticket }}">
                    		Επικοινωνία
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Τερματισμός υπηρεσίας',
                'in_app_body' => 'Η υπηρεσία σας {{ service.product.name }} τερματίστηκε.',
            ],
        ],
        'new_ticket_message' => [
            'el' => [
                'subject' => '[Ticket #{{ ticketMessage.ticket_id }}] {{ ticketMessage.ticket.subject }}',
                'body' => <<<'HTML'
                    # Νέα απάντηση στο αίτημά σας

                    {{ ticketMessage.user.name }} απάντησε στο αίτημά σας **#{{ ticketMessage.ticket_id }}**:

                    {{ message_html }}

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.ticket }}">
                    		Προβολή αιτήματος
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Νέα απάντηση στο αίτημα #{{ ticketMessage.ticket_id }}',
                'in_app_body' => 'Έχετε νέα απάντηση στο αίτημά σας #{{ ticketMessage.ticket_id }}.',
            ],
        ],
        'email_verification' => [
            'el' => [
                'subject' => 'Καλώς ήρθατε στην {{ app.name }}: επιβεβαιώστε το email σας',
                'body' => <<<'HTML'
                    # Καλώς ήρθατε στην {{ app.name }}

                    Αγαπητέ/ή {{ user.first_name }},

                    Ο λογαριασμός σας δημιουργήθηκε. Παρακαλούμε επιβεβαιώστε τη διεύθυνση email σας.

                    <div class="action">
                    	<a class="button button-blue" href="{{ url }}">
                    		Επιβεβαίωση email
                    	</a>
                    </div>

                    Ο σύνδεσμος ισχύει για 60 λεπτά. Αν έχει λήξει, συνδεθείτε στην περιοχή πελατών για να λάβετε νέο.

                    **Στην περιοχή πελατών μπορείτε να**
                    - παραγγείλετε και να ανανεώσετε υπηρεσίες
                    - δείτε και να εξοφλήσετε προτιμολόγια
                    - ανοίξετε αιτήματα υποστήριξης

                    Αν δεν δημιουργήσατε εσείς λογαριασμό, παραβλέψτε αυτό το email.
                    HTML,
            ],
        ],
        'password_reset' => [
            'el' => [
                'subject' => 'Επαναφορά κωδικού πρόσβασης',
                'body' => <<<'HTML'
                    # Επαναφορά κωδικού πρόσβασης

                    Αγαπητέ/ή {{ user.first_name }},

                    Λάβαμε αίτημα επαναφοράς του κωδικού πρόσβασης του λογαριασμού σας.

                    <div class="action">
                    	<a class="button button-blue" href="{{ url }}">
                    		Επαναφορά κωδικού
                    	</a>
                    </div>

                    Ο σύνδεσμος ισχύει για 60 λεπτά. Αν δεν ζητήσατε επαναφορά κωδικού, δεν χρειάζεται να κάνετε κάτι.
                    HTML,
            ],
        ],
        'card_expiring' => [
            'el' => [
                'subject' => 'Η αποθηκευμένη μέθοδος πληρωμής σας λήγει σύντομα',
                'body' => <<<'HTML'
                    # Η αποθηκευμένη μέθοδος πληρωμής σας λήγει σύντομα

                    Αγαπητέ/ή {{ user.first_name }},

                    Η αποθηκευμένη μέθοδος πληρωμής σας **{{ method_name }}** λήγει στο τέλος του **{{ expiry }}**.

                    Μετά από αυτό, τα προτιμολόγιά σας δεν μπορούν να χρεωθούν αυτόματα σε αυτήν. Προσθέστε μια νέα μέθοδο πληρωμής πριν από τότε, ώστε οι υπηρεσίες σας να ανανεωθούν χωρίς διακοπή.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.payment_methods }}">
                    		Ενημέρωση μεθόδου πληρωμής
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Η αποθηκευμένη μέθοδος πληρωμής λήγει σύντομα',
                'in_app_body' => 'Η αποθηκευμένη μέθοδος πληρωμής σας {{ method_name }} λήγει στο τέλος του {{ expiry }}.',
            ],
        ],
        'service_cancelled' => [
            'el' => [
                'subject' => 'Διακοπή υπηρεσίας: {{ service.product.name }}',
                'body' => <<<'HTML'
                    # Διακοπή υπηρεσίας

                    Αγαπητέ/ή {{ user.first_name }},

                    Όπως ζητήσατε, η υπηρεσία σας **{{ service.product.name }}** διακόπηκε στο τέλος της περιόδου χρέωσης.

                    Σας ευχαριστούμε που χρησιμοποιήσατε τις υπηρεσίες μας· ελπίζουμε να σας δούμε ξανά.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.new_ticket }}">
                    		Επικοινωνία
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Διακοπή υπηρεσίας',
                'in_app_body' => 'Η υπηρεσία σας {{ service.product.name }} διακόπηκε, όπως ζητήσατε.',
            ],
        ],
        'service_cancellation_received' => [
            'el' => [
                'subject' => 'Επιβεβαίωση αιτήματος διακοπής: {{ service.product.name }}',
                'body' => <<<'HTML'
                    # Επιβεβαίωση αιτήματος διακοπής

                    Αγαπητέ/ή {{ user.first_name }},

                    Το αίτημά σας για διακοπή υπηρεσίας ελήφθη.

                    - Υπηρεσία: **{{ service.product.name }}**
                    {% if cancellation.reason %}
                    - Αιτία: {{ cancellation.reason }}
                    {% endif %}
                    - Ημερομηνία αιτήματος: {{ cancellation.created_at|date("d/m/Y H:i") }}

                    {% if cancellation.type == "end_of_period" %}
                    {% if service.expires_at %}
                    Η υπηρεσία σας θα παραμείνει ενεργή έως **{{ service.expires_at|date("d/m/Y") }}**, στο τέλος της τρέχουσας περιόδου χρέωσης, και θα διακοπεί τότε.
                    {% else %}
                    Η υπηρεσία σας θα διακοπεί στο τέλος της τρέχουσας περιόδου χρέωσης.
                    {% endif %}
                    {% else %}
                    Η υπηρεσία σας διακόπτεται άμεσα. Βεβαιωθείτε ότι έχετε αντίγραφο ασφαλείας των δεδομένων σας.
                    {% endif %}

                    Σας ευχαριστούμε που χρησιμοποιήσατε τις υπηρεσίες μας· ελπίζουμε να σας δούμε ξανά. Αν θέλετε να ακυρώσετε το αίτημα, επικοινωνήστε μαζί μας το συντομότερο.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.new_ticket }}">
                    		Επικοινωνία
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Επιβεβαίωση αιτήματος διακοπής',
                'in_app_body' => 'Λάβαμε το αίτημα διακοπής για την υπηρεσία {{ service.product.name }}.',
            ],
        ],
        'invoice_payment_reminder' => [
            'el' => [
                'subject' => 'Υπενθύμιση: προτιμολόγιο #{{ invoice_number }} | Λήξη {{ invoice.due_at|date("d/m/Y") }} | {{ total }}',
                'body' => <<<'HTML'
                    # Υπενθύμιση πληρωμής

                    Αγαπητέ/ή {{ user.first_name }},

                    Σας υπενθυμίζουμε ότι το προτιμολόγιο **#{{ invoice_number }}** λήγει στις **{{ invoice.due_at|date("d/m/Y") }}**. Αν το έχετε ήδη εξοφλήσει, παραβλέψτε αυτό το μήνυμα.

                    - Προτιμολόγιο: **#{{ invoice_number }}**
                    - Σύνολο: **{{ total }}**
                    {% if partly_paid %}
                    - Υπόλοιπο προς πληρωμή: **{{ remaining }}**
                    {% endif %}
                    - Ημερομηνία λήξης: **{{ invoice.due_at|date("d/m/Y") }}**

                    <div class="table">

                    | Περιγραφή | Ποσότητα | Τιμή |
                    | :--- | :---: | ---: |
                    {% for item in items %}
                    | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                    {% endfor %}
                    </div>

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Προβολή & πληρωμή
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Υπενθύμιση πληρωμής: #{{ invoice_number }}',
                'in_app_body' => 'Το προτιμολόγιο #{{ invoice_number }} ποσού {{ total }} λήγει στις {{ invoice.due_at|date("d/m/Y") }}.',
            ],
        ],
        'card_payment_due' => [
            'el' => [
                'subject' => 'Επερχόμενη χρέωση: προτιμολόγιο #{{ invoice_number }} | {{ total }}',
                'body' => <<<'HTML'
                    # Επερχόμενη αυτόματη πληρωμή

                    Αγαπητέ/ή {{ user.first_name }},

                    Το προτιμολόγιο **#{{ invoice_number }}** ποσού **{{ total }}** θα χρεωθεί αυτόματα στις **{{ invoice.due_at|date("d/m/Y") }}** με τον αποθηκευμένο τρόπο πληρωμής σας. Δεν χρειάζεται να κάνετε κάτι.

                    Αν η κάρτα σας έχει αλλάξει ή λήξει, ενημερώστε την ή εξοφλήστε το προτιμολόγιο με άλλο τρόπο πριν από αυτή την ημερομηνία.

                    <div class="table">

                    | Περιγραφή | Ποσότητα | Τιμή |
                    | :--- | :---: | ---: |
                    {% for item in items %}
                    | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                    {% endfor %}
                    </div>

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Προβολή προτιμολογίου
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Επερχόμενη χρέωση: #{{ invoice_number }}',
                'in_app_body' => 'Το προτιμολόγιο #{{ invoice_number }} ({{ total }}) θα χρεωθεί αυτόματα στις {{ invoice.due_at|date("d/m/Y") }}.',
            ],
        ],
        'invoice_overdue' => [
            'el' => [
                'subject' => 'Ληξιπρόθεσμο: προτιμολόγιο #{{ invoice_number }} | {{ total }}',
                'body' => <<<'HTML'
                    # Ληξιπρόθεσμη οφειλή

                    Αγαπητέ/ή {{ user.first_name }},

                    Το προτιμολόγιο **#{{ invoice_number }}** έληξε στις **{{ invoice.due_at|date("d/m/Y") }}** και είναι ληξιπρόθεσμο εδώ και {{ overdue.days }} ημέρα/ες. Αν το έχετε ήδη εξοφλήσει, παραβλέψτε αυτό το μήνυμα· η πληρωμή ίσως δεν έχει καταχωριστεί ακόμα.

                    - Προτιμολόγιο: **#{{ invoice_number }}**
                    - Σύνολο: **{{ total }}**
                    {% if partly_paid %}
                    - Υπόλοιπο προς πληρωμή: **{{ remaining }}**
                    {% endif %}
                    - Έληξε: **{{ invoice.due_at|date("d/m/Y") }}**

                    <div class="table">

                    | Περιγραφή | Ποσότητα | Τιμή |
                    | :--- | :---: | ---: |
                    {% for item in items %}
                    | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                    {% endfor %}
                    </div>

                    {% if overdue.last %}
                    **Αυτή είναι η τελευταία μας ειδοποίηση.**{% if overdue.suspend_on %} Αν δεν εξοφληθεί, η υπηρεσία τίθεται σε αναστολή στις **{{ overdue.suspend_on }}**.{% endif %}
                    {% endif %}

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Εξόφληση προτιμολογίου
                    	</a>
                    </div>

                    Αν έχετε απορίες ή δυσκολία με την πληρωμή, επικοινωνήστε μαζί μας.
                    HTML,
                'in_app_title' => 'Ληξιπρόθεσμο: #{{ invoice_number }}',
                'in_app_body' => 'Το προτιμολόγιο #{{ invoice_number }} ({{ total }}) είναι ληξιπρόθεσμο εδώ και {{ overdue.days }} ημέρα/ες.',
            ],
        ],
        'invoice_modified' => [
            'el' => [
                'subject' => 'Ενημέρωση προτιμολογίου #{{ invoice_number }} | {{ total }}',
                'body' => <<<'HTML'
                    # Ενημέρωση προτιμολογίου

                    Αγαπητέ/ή {{ user.first_name }},

                    Το προτιμολόγιο **#{{ invoice_number }}** ενημερώθηκε. Ακολουθεί όπως είναι τώρα.

                    - Προτιμολόγιο: **#{{ invoice_number }}**
                    - Σύνολο: **{{ total }}**
                    {% if partly_paid %}
                    - Υπόλοιπο προς πληρωμή: **{{ remaining }}**
                    {% endif %}
                    - Ημερομηνία λήξης: **{{ invoice.due_at|date("d/m/Y") }}**

                    <div class="table">

                    | Περιγραφή | Ποσότητα | Τιμή |
                    | :--- | :---: | ---: |
                    {% for item in items %}
                    | {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                    {% endfor %}
                    </div>

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Προβολή & πληρωμή
                    	</a>
                    </div>
                    HTML,
                'in_app_title' => 'Ενημέρωση προτιμολογίου #{{ invoice_number }}',
                'in_app_body' => 'Το προτιμολόγιο #{{ invoice_number }} ενημερώθηκε: σύνολο {{ total }}.',
            ],
        ],
        'client_invite' => [
            'el' => [
                'subject' => 'Πρόσκληση στον λογαριασμό {{ account.name }} στο {{ app.name }}',
                'body' => <<<'HTML'
                    # Σας προσκαλούν

                    Γεια σας,

                    Ο/Η {{ invite.by }} σας προσκάλεσε να διαχειρίζεστε τον λογαριασμό **{{ account.name }}** στο {{ app.name }}.

                    <div class="action">
                    	<a class="button button-blue" href="{{ invite.url }}">
                    		Αποδοχή πρόσκλησης
                    	</a>
                    </div>

                    Συνδεθείτε με τον λογαριασμό σύνδεσης που ήδη έχετε, ή δημιουργήστε νέο, για να την αποδεχτείτε. Η πρόσκληση ισχύει έως {{ invite.expires_at|date("d/m/Y H:i") }} και χρησιμοποιείται μία φορά.

                    Αν δεν περιμένατε αυτή την πρόσκληση, αγνοήστε αυτό το email.
                    HTML,
            ],
        ],
        'domain_renewal_notice' => [
            'el' => [
                'subject' => 'Το domain {{ fqdn }} λήγει στις {{ expires }}',
                'body' => <<<'HTML'
                    # Ανανέωση domain

                    Αγαπητέ/ή {{ user.first_name }},

                    Το domain σας **{{ fqdn }}** λήγει στις **{{ expires }}**{% if days_left %}, σε {{ days_left }} ημέρα/ες{% endif %}.

                    {% if invoice %}
                    Η ανανέωσή του είναι στο προτιμολόγιο **#{{ invoice_number }}** ({{ invoice.formattedTotal }}).{% if auto_charged %} Θα χρεωθεί αυτόματα στον αποθηκευμένο τρόπο πληρωμής σας.{% else %} Παρακαλούμε εξοφλήστε το πριν τη λήξη, ώστε το domain σας να συνεχίσει να λειτουργεί.{% endif %}
                    {% else %}
                    Ανανεώνεται για {{ years }} έτος/η{% if price %} με **{{ price }}**{% endif %}. Θα σας στείλουμε το προτιμολόγιο πριν τη λήξη.
                    {% endif %}

                    {% if late_fee %}
                    **Ανανεώστε το έως τις {{ expires }}:** μετά από αυτή την ημερομηνία η ανανέωση κοστίζει **{{ late_fee }}** επιπλέον (χρέωση καθυστερημένης ανανέωσης από το μητρώο).
                    {% endif %}

                    Αν δεν θέλετε να το κρατήσετε, ενημερώστε μας και δεν θα το ανανεώσουμε.

                    {% if invoice %}
                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		{% if auto_charged %}Προβολή προτιμολογίου{% else %}Εξόφληση προτιμολογίου{% endif %}
                    	</a>
                    </div>
                    {% endif %}
                    HTML,
                'in_app_title' => 'Το {{ fqdn }} λήγει στις {{ expires }}',
                'in_app_body' => 'Το domain σας {{ fqdn }} λήγει στις {{ expires }}.',
            ],
        ],
        'domain_free_renewal_notice' => [
            'el' => [
                'subject' => 'Το domain {{ fqdn }} ανανεώνεται στις {{ expires }}',
                'body' => <<<'HTML'
                    # Ανανέωση domain

                    Αγαπητέ/ή {{ user.first_name }},

                    Το domain σας **{{ fqdn }}** λήγει στις **{{ expires }}**{% if days_left %}, σε {{ days_left }} ημέρα/ες{% endif %}. Η ανανέωσή του είναι δωρεάν με το πακέτο φιλοξενίας σας: δεν χρειάζεται πληρωμή, ανανεώνεται αυτόματα.

                    Αν δεν θέλετε να το κρατήσετε, ενημερώστε μας και δεν θα το ανανεώσουμε.
                    HTML,
                'in_app_title' => 'Το {{ fqdn }} ανανεώνεται στις {{ expires }}',
                'in_app_body' => 'Το domain σας {{ fqdn }} ανανεώνεται δωρεάν στις {{ expires }}.',
            ],
        ],
        'domain_expired_notice' => [
            'el' => [
                'subject' => 'Το domain {{ fqdn }} έληξε',
                'body' => <<<'HTML'
                    # Ληγμένο domain

                    Αγαπητέ/ή {{ user.first_name }},

                    Το domain σας **{{ fqdn }}** έληξε στις **{{ expires }}** και δεν λειτουργεί: η ιστοσελίδα και το email σας σε αυτό είναι εκτός.

                    Μπορεί ακόμα να ανανεωθεί έως τις **{{ last_day }}**{% if late_fee %}, πλέον με επιπλέον χρέωση καθυστερημένης ανανέωσης **{{ late_fee }}** από το μητρώο{% endif %}. Μετά ίσως χαθεί και να μπορεί να το καταχωρίσει οποιοσδήποτε.

                    {% if invoice %}
                    Η ανανέωσή του είναι στο προτιμολόγιο **#{{ invoice_number }}** ({{ invoice.formattedTotal }}). Μόλις εξοφληθεί, ανανεώνεται αμέσως.

                    <div class="action">
                    	<a class="button button-blue" href="{{ urls.invoice }}">
                    		Εξόφληση προτιμολογίου
                    	</a>
                    </div>
                    {% else %}
                    Επικοινωνήστε μαζί μας για να το ανανεώσουμε.
                    {% endif %}
                    HTML,
                'in_app_title' => 'Το {{ fqdn }} έληξε',
                'in_app_body' => 'Το domain σας {{ fqdn }} έληξε στις {{ expires }}· μπορεί να ανανεωθεί έως τις {{ last_day }}.',
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::mapping as $key => $data) {
            DB::table('notification_templates')->insertOrIgnore(array_merge($data, [
                'key' => $key,
                'enabled' => true,
                'translations' => isset(self::translations[$key]) ? json_encode(self::translations[$key], JSON_UNESCAPED_UNICODE) : null,
            ]));
        }
    }
}
