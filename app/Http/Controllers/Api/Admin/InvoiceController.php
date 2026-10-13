<?php

namespace App\Http\Controllers\Api\Admin;

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
        'user',
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
            ->allowedFilters(['id', 'currency_code', 'user_id', 'status'])
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->allowedSorts(['id', 'created_at', 'updated_at', 'currency_code'])
            ->simplePaginate(request('per_page', 15));

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
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->findOrFail($invoice->id);

        // Return the invoice as a JSON response
        return new InvoiceResource($invoice);
    }

    /**
     * Update a specific invoice
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        // An invoice with payments keeps its customer and currency (InvoiceObserver::updating, docs/money-trail.md §8).
        $moves = (array_key_exists('user_id', $data) && (int) $data['user_id'] !== (int) $invoice->user_id)
            || (array_key_exists('currency_code', $data) && $data['currency_code'] !== $invoice->currency_code);
        if ($moves && $invoice->hasPayments()) {
            abort(422, 'An invoice with payments keeps its customer and currency: delete its payments first (a refunded payment keeps the invoice where it is).');
        }
        $invoice->update($data);

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
        $invoice->delete();

        return $this->returnNoContent();
    }
}
