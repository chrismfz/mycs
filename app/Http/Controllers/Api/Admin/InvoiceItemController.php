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
            ->allowedFilters('id', 'quantity', 'price', 'reference_type', 'reference_id')
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->allowedSorts('id', 'created_at', 'updated_at', 'quantity', 'price')
            ->simplePaginate(min(max((int) request('per_page', 15), 1), 100));

        // Return the invoices as a JSON response
        return InvoiceItemResource::collection($invoices);
    }

    /**
     * Create a new invoice item
     *
     * @response 422 {"message": "This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first."}
     */
    public function store(CreateInvoiceItemRequest $request)
    {
        // Validate and create the invoice item
        // Under the payment locks (StaffLineEdit); an invoice with payments takes it too (owner 2026-11-10).
        $create = fn () => StaffLineEdit::run($request->validated('invoice_id'), true, function (Invoice $invoice) use ($request) {
            StaffLineEdit::refuseReferencedLine($invoice, $request->validated('reference_type'), 'add');

            return InvoiceItem::create($request->validated());
        });
        // A domain's renewal: the domain first (its lock order: domain, customer, invoice), so that the cron billing it
        // at the same moment does not bill the same year (F5 of #174).
        $invoiceItem = $this->guarded(fn () => $request->validated('kind') === InvoiceItem::KIND_RENEWAL
            ? DB::transaction(function () use ($create, $request) {
                Domain::query()->whereKey($request->validated('reference_id'))->lockForUpdate()->first();

                return $create();
            })
            : $create());

        // Return the created invoice as a JSON response
        return $this->withBalanceNotice(new InvoiceItemResource($this->loadAllowedIncludes($invoiceItem, self::INCLUDES)), $invoiceItem);
    }

    /**
     * Show a specific invoice item
     */
    public function show(GetInvoiceItemRequest $request, InvoiceItem $invoiceItem)
    {
        $invoiceItem = QueryBuilder::for(InvoiceItem::class)
            ->allowedIncludes(...$this->allowedIncludes(self::INCLUDES))
            ->findOrFail($invoiceItem->id);

        // Return the invoice item as a JSON response
        return new InvoiceItemResource($invoiceItem);
    }

    /**
     * Update a specific invoice item
     *
     * @response 422 {"message": "This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first."}
     */
    public function update(UpdateInvoiceItemRequest $request, InvoiceItem $invoiceItem)
    {
        // Validate and update the invoice item
        // Under the payment locks, the line read again there (StaffLineEdit).
        $this->guarded(fn () => StaffLineEdit::run($invoiceItem->invoice_id, false, function () use ($invoiceItem, $request) {
            $locked = InvoiceItem::query()->whereKey($invoiceItem->getKey())->lockForUpdate()->firstOrFail();
            $locked->fill($request->validated());
            $locked->save();
            $invoiceItem->setRawAttributes($locked->getAttributes(), true);
        }));

        // Return the updated invoice item as a JSON response
        return $this->withBalanceNotice(new InvoiceItemResource($this->loadAllowedIncludes($invoiceItem, self::INCLUDES)), $invoiceItem);
    }

    /**
     * Delete a specific invoice item
     *
     * @response 422 {"message": "This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first."}
     */
    public function destroy(DeleteInvoiceItemRequest $request, InvoiceItem $invoiceItem)
    {
        // Delete the invoice item
        $this->guarded(fn () => StaffLineEdit::run($invoiceItem->invoice_id, true, function (Invoice $invoice) use ($invoiceItem) {
            StaffLineEdit::refuseReferencedLine($invoice, $invoiceItem->reference_type, 'remove');
            $invoiceItem->delete();
        }));

        return $this->returnNoContent();
    }

    /** `warning`, beside `data`: its invoice now owes on while paid, or is paid over (StaffLineEdit::balanceNotice). */
    private function withBalanceNotice(InvoiceItemResource $resource, InvoiceItem $line): InvoiceItemResource
    {
        $notice = StaffLineEdit::balanceNotice($line->invoice_id);

        return $notice === null ? $resource : $resource->additional(['warning' => $notice]);
    }

    /** The model's money rules (a refunded invoice keeps its lines, InvoiceItemObserver) answer 422, not 500. */
    private function guarded(\Closure $change): mixed
    {
        try {
            return $change();
        } catch (DisplayException $e) {
            abort(422, $e->getMessage());
        }
    }
}
