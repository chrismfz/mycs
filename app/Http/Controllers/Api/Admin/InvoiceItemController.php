<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DisplayException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\InvoiceItems\CreateInvoiceItemRequest;
use App\Http\Requests\Api\Admin\InvoiceItems\DeleteInvoiceItemRequest;
use App\Http\Requests\Api\Admin\InvoiceItems\GetInvoiceItemRequest;
use App\Http\Requests\Api\Admin\InvoiceItems\GetInvoiceItemsRequest;
use App\Http\Requests\Api\Admin\InvoiceItems\UpdateInvoiceItemRequest;
use App\Http\Resources\InvoiceItemResource;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Invoice\StaffLineEdit;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(name: 'InvoiceItems', weight: 5)]
class InvoiceItemController extends ApiController
{
    protected const INCLUDES = [
        'invoice',
    ];

    /**
     * List invoice items
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetInvoiceItemsRequest $request)
    {
        // Fetch invoices with pagination
        $invoices = QueryBuilder::for(InvoiceItem::class)
            ->allowedFilters(['id', 'quantity', 'price', 'reference_type', 'reference_id'])
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->allowedSorts(['id', 'created_at', 'updated_at', 'quantity', 'price'])
            ->simplePaginate(min(max((int) request('per_page', 15), 1), 100));

        // Return the invoices as a JSON response
        return InvoiceItemResource::collection($invoices);
    }

    /**
     * Create a new invoice item
     *
     * @response 422 {"message": "A paid invoice keeps its lines: set it back to unpaid first."}
     */
    public function store(CreateInvoiceItemRequest $request)
    {
        // Validate and create the invoice item
        // Under the payment locks; an invoice with payments keeps its amounts (StaffLineEdit).
        $create = fn () => StaffLineEdit::run($request->validated('invoice_id'), true, fn () => InvoiceItem::create($request->validated()));
        // A domain's renewal: the domain first (its lock order: domain, customer, invoice), so that the cron billing it
        // at the same moment does not bill the same year (F5 of #174).
        $invoiceItem = $this->guarded(fn () => $request->validated('kind') === InvoiceItem::KIND_RENEWAL
            ? DB::transaction(function () use ($create, $request) {
                Domain::query()->whereKey($request->validated('reference_id'))->lockForUpdate()->first();

                return $create();
            })
            : $create());

        // Return the created invoice as a JSON response
        return new InvoiceItemResource($this->loadAllowedIncludes($invoiceItem, self::INCLUDES));
    }

    /**
     * Show a specific invoice item
     */
    public function show(GetInvoiceItemRequest $request, InvoiceItem $invoiceItem)
    {
        $invoiceItem = QueryBuilder::for(InvoiceItem::class)
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->findOrFail($invoiceItem->id);

        // Return the invoice item as a JSON response
        return new InvoiceItemResource($invoiceItem);
    }

    /**
     * Update a specific invoice item
     *
     * @response 422 {"message": "A paid invoice keeps its lines: set it back to unpaid first."}
     */
    public function update(UpdateInvoiceItemRequest $request, InvoiceItem $invoiceItem)
    {
        // Validate and update the invoice item
        // Under the payment locks, the line read again there; its amounts stay on an invoice with payments, its
        // description still changes (StaffLineEdit).
        $this->guarded(fn () => StaffLineEdit::run($invoiceItem->invoice_id, false, function (Invoice $invoice) use ($invoiceItem, $request) {
            $locked = InvoiceItem::query()->whereKey($invoiceItem->getKey())->lockForUpdate()->firstOrFail();
            $locked->fill($request->validated());
            if ($locked->isDirty(['price', 'quantity', 'taxed', 'reference_type', 'reference_id'])) {
                StaffLineEdit::refuseWithPayments($invoice);
            }
            $locked->save();
            $invoiceItem->setRawAttributes($locked->getAttributes(), true);
        }));

        // Return the updated invoice item as a JSON response
        return new InvoiceItemResource($this->loadAllowedIncludes($invoiceItem, self::INCLUDES));
    }

    /**
     * Delete a specific invoice item
     *
     * @response 422 {"message": "A paid invoice keeps its lines: set it back to unpaid first."}
     */
    public function destroy(DeleteInvoiceItemRequest $request, InvoiceItem $invoiceItem)
    {
        // Delete the invoice item
        $this->guarded(fn () => StaffLineEdit::run($invoiceItem->invoice_id, true, fn () => $invoiceItem->delete()));

        return $this->returnNoContent();
    }

    /** The model's money rules (a paid invoice keeps its lines, InvoiceItemObserver) answer 422, not 500. */
    private function guarded(\Closure $change): mixed
    {
        try {
            return $change();
        } catch (DisplayException $e) {
            abort(422, $e->getMessage());
        }
    }
}
