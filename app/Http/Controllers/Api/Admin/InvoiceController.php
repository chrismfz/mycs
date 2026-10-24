<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DisplayException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\Invoices\CreateInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\DeleteInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\GetInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\GetInvoicesRequest;
use App\Http\Requests\Api\Admin\Invoices\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(name: 'Invoices', weight: 4)]
class InvoiceController extends ApiController
{
    protected const INCLUDES = [
        'items',
        'client',
    ];

    /**
     * List Invoices
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetInvoicesRequest $request)
    {
        // Fetch invoices with pagination
        $invoices = QueryBuilder::for(Invoice::class)
            ->allowedFilters('id', 'currency_code', 'client_id', 'status')
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->allowedSorts('id', 'created_at', 'updated_at', 'currency_code')
            ->simplePaginate(min(max((int) request('per_page', 15), 1), 100));

        // Return the invoices as a JSON response
        return InvoiceResource::collection($invoices);
    }

    /**
     * Create a new invoice
     */
    public function store(CreateInvoiceRequest $request)
    {
        // Validate and create the invoice
        $invoice = Invoice::create($request->validated());

        // Return the created invoice as a JSON response
        return new InvoiceResource($this->loadAllowedIncludes($invoice, self::INCLUDES));
    }

    /**
     * Show a specific invoice
     */
    public function show(GetInvoiceRequest $request, Invoice $invoice)
    {
        $invoice = QueryBuilder::for(Invoice::class)
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->findOrFail($invoice->id);

        // Return the invoice as a JSON response
        return new InvoiceResource($invoice);
    }

    /**
     * Update a specific invoice
     *
     * @response 422 {"message": "An invoice becomes paid when its payment is recorded: use Mark Paid instead."}
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        // An invoice with payments keeps its customer and currency (InvoiceObserver::updating, docs/money-trail.md §8).
        $moves = (array_key_exists('client_id', $data) && (int) $data['client_id'] !== (int) $invoice->client_id)
            || (array_key_exists('currency_code', $data) && $data['currency_code'] !== $invoice->currency_code);
        if ($moves && $invoice->hasPayments()) {
            abort(422, 'An invoice with payments keeps its customer and currency: delete its payments first (a refunded payment keeps the invoice where it is).');
        }
        try {
            $invoice->update($data);
        } catch (DisplayException $e) {
            // The model's money rules (InvoiceObserver::updating): paid only without a balance, a cancelled invoice is
            // reopened before it is paid, an invoice with payments keeps its customer and currency.
            abort(422, $e->getMessage());
        }

        // Return the updated invoice as a JSON response
        return new InvoiceResource($this->loadAllowedIncludes($invoice, self::INCLUDES));
    }

    /**
     * Delete a specific invoice
     */
    public function destroy(DeleteInvoiceRequest $request, Invoice $invoice)
    {
        // An invoice with payments is never deleted (InvoiceObserver::deleting, docs/payments.md).
        if ($invoice->hasPayments()) {
            abort(422, 'An invoice with payments cannot be deleted: cancel it, or delete its transactions first.');
        }
        // It records a period given up on (Δ3a): kept (InvoiceObserver::deleting).
        if ($invoice->recordsForgonePeriod()) {
            abort(422, 'This cancelled invoice records a service period given up on: it is kept (reopen it to bill that period again).');
        }
        $invoice->delete();

        return $this->returnNoContent();
    }
}
