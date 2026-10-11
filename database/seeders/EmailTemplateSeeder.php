<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmailTemplateSeeder extends Seeder
{
    public const mapping = [
        'new_login_detected' => [
            'subject' => 'New login detected',
            'body' => <<<'HTML'
                # New login detected  
                            
                A new login was detected on your account.
                            
                - IP: {{ ip }}  
                - Device: {{ device }}
                - Time: {{ time }}

                **If this was you**  
                You can ignore this message, there is no need to take any action.
                            
                **If this wasn't you**  
                Please reset your password [here]({{ urls.password_reset }}).
                HTML,
            'in_app_title' => 'New login detected',
            'in_app_body' => 'A new login was detected on your account from IP: {{ ip }} using {{ device }} at {{ time }}.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_off',
            'edit_preference_message' => 'Alert me about new login attempts',
            'in_app_url' => '{{ urls.security }}',
        ],
        'new_invoice_created' => [
            'subject' => 'New invoice created',
            'body' => <<<'HTML'
                # New invoice created  
                            
                A new invoice was created on your account.
                            
                Total amount: **{{ total }}**
                            
                            
                <div class="table">  
                            
                |   Item   | Quantity |  Price   |  
                | :------: | :------: | :------: |
                {% for item in items %}
                | {{ item.description }} | {{ item.quantity }} | {{ item.price }} |
                {% endfor %}
                </div>
                            
                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		Go to invoice
                	</a>
                </div>
                            
                {% if has_subscription %}
                You have a active subscription, the invoice will be automatically paid.
                {% endif %}
                HTML,
            'in_app_title' => 'New invoice created',
            'in_app_body' => 'A new invoice was created on your account with total amount: {{ total }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about new invoices',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'invoice_paid' => [
            'subject' => 'Invoice paid',
            'body' => <<<'HTML'
                # Invoice paid  
                            
                Your invoice has been successfully paid.
                            
                Total amount: **{{ invoice.formattedTotal }}**
                            
                You can view your invoice details by clicking the button below.
                            
                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		View Invoice
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Invoice paid',
            'in_app_body' => 'Your invoice #{{ invoice.id }} has been successfully paid with total amount: {{ invoice.formattedTotal }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about successful payments',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'invoice_payment_failed' => [
            'subject' => 'Invoice payment failed',
            'body' => <<<'HTML'
                # Invoice payment failed  

                Your invoice payment has failed.

                Total amount: **{{ invoice.formattedTotal }}**
                            
                Please pay the invoice to avoid service interruptions.
                            
                <div class="action">
                	<a class="button button-blue" href="{{ urls.invoice }}">
                		Pay Invoice
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Invoice payment failed',
            'in_app_body' => 'Your invoice #{{ invoice.id }} payment has failed. Please pay the invoice to avoid service interruptions.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Alert me about payment failures',
            'in_app_url' => '{{ urls.invoice }}',
        ],
        'new_order_created' => [
            'subject' => 'New order created',
            'body' => <<<'HTML'
                # New order created

                A new order was created on your account.

                **Order details**
                <div class="table">  
                            
                |   Item   | Quantity |  Price   |  
                | :------: | :------: | :------: |
                {% for item in items %}
                | {{ item.product.name }} | {{ item.quantity }} | {{ item.formattedPrice }} |
                {% endfor %}
                </div>
                HTML,
            'in_app_title' => 'New order created',
            'in_app_body' => 'A new order was created on your account.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Send me order confirmations',
            'in_app_url' => '{{ urls.services }}',
        ],
        'new_server_created' => [
            'subject' => 'Service activated',
            'body' => <<<'HTML'
                # Service activated

                Your service has been activated.

                **Service details**
                - Name: {{ service.product.name }}

                {% if product_info %}
                **Service information**  
                {{ product_info }}
                {% endif %}
                HTML,
            'in_app_title' => 'Service activated',
            'in_app_body' => 'Your service {{ service.product.name }} has been activated.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about new service activations',
            'in_app_url' => '{{ urls.service }}',
        ],
        'server_suspended' => [
            'subject' => 'Service suspended',
            'body' => <<<'HTML'
                # Service suspended

                Your service has been suspended due to a payment failure.

                **Service details**
                - Name: {{ service.product.name }}

                Please pay the invoice to reactivate the service.
                HTML,
            'in_app_title' => 'Service suspended',
            'in_app_body' => 'Your service {{ service.product.name }} has been suspended due to a payment failure. Please pay the invoice to reactivate the service.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Alert me about service suspensions',
            'in_app_url' => '{{ urls.service }}',
        ],
        'server_terminated' => [
            'subject' => 'Service terminated',
            'body' => <<<'HTML'
                # Service terminated

                Your service has been terminated.

                **Service details**
                - Name: {{ service.product.name }}

                Do you consider it a mistake?
                <div class="action">
                	<a class="button button-blue" href="{{ urls.new_ticket }}">
                		Contact us
                	</a>
                </div>
                HTML,
            'in_app_title' => 'Server terminated',
            'in_app_body' => 'Your server {{ service.product.name }} has been terminated.',
            'mail_enabled' => 'force',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Alert me about service terminations',
            'in_app_url' => '{{ urls.service }}',
        ],
        'new_ticket_message' => [
            'subject' => '[Ticket #{{ ticketMessage.ticket_id }}] New reply',
            'body' => <<<'HTML'
                # New ticket reply

                {{ ticketMessage.user.name }} replied to your ticket.

                **Message**
                {{ message_html }}
                HTML,
            'in_app_title' => 'New ticket reply',
            'in_app_body' => 'You have a new reply on your ticket #{{ ticketMessage.ticket_id }}.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about ticket replies',
            'in_app_url' => '{{ urls.ticket }}',
        ],
        'email_verification' => [
            'subject' => 'Email verification',
            'body' => <<<'HTML'
                # Email verification
                Please verify your email address by clicking the link below.
                <div class="action">
                    <a class="button button-blue" href="{{ url }}">
                        Verify email
                    </a>
                </div>
                This link will expire in 60 minutes.
                If you did not create an account, you can ignore this email.
                HTML,
            'mail_enabled' => 'force',
            'in_app_enabled' => 'never',
        ],
        'password_reset' => [
            'subject' => 'Password reset',
            'body' => <<<'HTML'
                # Password reset
                You are receiving this email because we received a password reset request for your account.

                **Reset password**
                <div class="action">
                	<a class="button button-blue" href="{{ url }}">
                		Reset password
                	</a>
                </div>

                This password reset link will expire in 60 minutes.

                If you did not request a password reset, no further action is required.
                HTML,
            'mail_enabled' => 'force',
            'in_app_enabled' => 'never',
        ],
        'service_cancellation_received' => [
            'subject' => 'Service cancellation received',
            'body' => <<<'HTML'
                # Server Cancellation Received

                We're sorry to see you go! Your server cancellation has been successfully received.
                
                **Cancellation Details**
                - Server: {{ service.product.name }}
                {% if cancellation.reason %}
                - Reason: {{ cancellation.reason }}
                {% endif %}
                - Requested at: {{ cancellation.created_at|date("F j, Y, g:i A") }}
                
                {% if cancellation.type == "end_of_period" %}
                Your server will remain active until {{ service.expires_at|date("F j, Y") }} (end of your current billing period).
                {% else %}
                Your server has been terminated immediately.
                {% endif %}

                HTML,
            'in_app_title' => 'Service cancellation received',
            'in_app_body' => 'Your server cancellation has been successfully received.',
            'mail_enabled' => 'choice_on',
            'in_app_enabled' => 'choice_on',
            'edit_preference_message' => 'Notify me about service cancellations',
            'in_app_url' => '{{ urls.service }}',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        foreach (self::mapping as $key => $data) {
            DB::table('notification_templates')->insertOrIgnore(
                array_merge($data, ['key' => $key, 'enabled' => true])
            );
        }
    }
}
