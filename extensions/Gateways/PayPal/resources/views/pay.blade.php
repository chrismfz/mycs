<div id="paypal-button-container"></div>
<p id="paypal-result-message" class="mt-2 text-sm text-red-600" role="alert"></p>

@script
    <script>
        const script = document.createElement("script");
        script.src =
            "https://www.paypal.com/sdk/js?client-id={{ $clientId }}&currency={{ $invoice->currency_code }}&components=buttons{!! isset($plan) ? '&intent=subscription&vault=true' : '' !!}";
        script.async = true;
        document.body.appendChild(script);

        script.onload = () => {
            // Render the PayPal button
            paypal
                .Buttons({
                    style: {
                        shape: "rect",
                        layout: "vertical",
                        color: "gold",
                        label: "paypal",
                    },
                    message: {
                        amount: {{ $total }},
                    },
                    async createOrder(){
                        return '{{ $order->id }}';
                    },

                    async onApprove(data, actions) {
                        try {
                            const response = await fetch(
                                `{{ route('extensions.gateways.paypal.capture', ['invoice' => $invoice, 'orderID' => $order->id]) }}`, {
                                    method: "POST",
                                    headers: {
                                        "Content-Type": "application/json",
                                        "X-CSRF-TOKEN": "{{ csrf_token() }}"
                                    },
                                });

                            const orderData = await response.json();


                            const errorDetail = orderData?.details?.[0];

                            if (errorDetail?.issue === "INSTRUMENT_DECLINED") {
                                return actions.restart();
                            } else if (errorDetail) {
                                throw new Error(`${errorDetail.description} (${orderData.debug_id})`);
                            } else if (!orderData.purchase_units) {
                                throw new Error(JSON.stringify(orderData));
                            } else {
                                actions.redirect('{{ route('invoices.show', $invoice) }}?checkPayment=true');
                            }
                        } catch (error) {
                            console.error(error);
                            // Text, never HTML: the message can carry PayPal's own error description.
                            document.getElementById('paypal-result-message').textContent =
                                `Sorry, your transaction could not be processed. ${error.message ?? error}`;
                        }
                    },
                }).render("#paypal-button-container")
        };
    </script>
@endscript
