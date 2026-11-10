<?php

namespace App\Http\Controllers\Api\Admin;

use App\Admin\Actions\VoidAdjustmentNote;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\AdjustmentNotes\CreateAdjustmentNoteRequest;
use App\Http\Requests\Api\Admin\AdjustmentNotes\DeleteAdjustmentNoteRequest;
use App\Http\Requests\Api\Admin\AdjustmentNotes\GetAdjustmentNotesRequest;
use App\Http\Requests\Api\Admin\AdjustmentNotes\UpdateAdjustmentNoteRequest;
use App\Http\Resources\AdjustmentNoteResource;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;

/**
 * The adjustment notes of an invoice, as staff write and correct them in the panel's «Adjustment Notes» table (owner
 * 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-32, docs/staff-freedom.md): through the note's own rules
 * (AdjustmentNoteObserver) and the panel's void, edit and delete (VoidAdjustmentNote), under the payments' locks. A note
 * is always addressed under its invoice (404 otherwise). `warning`, beside `data`: the invoice now owes on while paid,
 * or is paid over.
 */
#[Group(name: 'Invoice adjustment notes', weight: 5)]
class InvoiceAdjustmentNoteController extends ApiController
{
    /**
     * List the adjustment notes of an invoice
     */
    public function index(GetAdjustmentNotesRequest $request, Invoice $invoice)
    {
        return AdjustmentNoteResource::collection($invoice->adjustmentNotes()->orderBy('id')->get());
    }

    /**
     * Write an adjustment note
     *
     * @response 422 {"message": "A refunded invoice keeps its total: its adjustment notes stay as they are."}
     */
    public function store(CreateAdjustmentNoteRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        $note = $this->guarded(fn () => DB::transaction(function () use ($invoice, $data) {
            (new RecordPaymentService)->lockInvoice($invoice->getKey());

            return $invoice->adjustmentNotes()->create(['amount' => $data['amount'], 'description' => $data['description'], 'type' => self::type($data['amount'])]);
        }));

        return $this->withBalanceNotice($note);
    }

    /**
     * Edit an adjustment note
     *
     * @response 422 {"message": "This note follows a refund or a cancellation, or its invoice is refunded or has a fiscal document from Ekdosi: it stays as it is."}
     */
    public function update(UpdateAdjustmentNoteRequest $request, Invoice $invoice, AdjustmentNote $adjustmentNote)
    {
        $data = array_intersect_key($request->validated(), array_flip(['amount', 'description']));
        if (array_key_exists('amount', $data)) {
            $data['type'] = self::type($data['amount']);
        }
        $this->guarded(fn () => VoidAdjustmentNote::change($adjustmentNote, fn (AdjustmentNote $note) => $note->update($data), VoidAdjustmentNote::editable(...)));

        return $this->withBalanceNotice($adjustmentNote);
    }

    /**
     * Void an adjustment note
     *
     * Its amount no longer counts in the invoice total; the note stays, marked voided.
     */
    public function void(UpdateAdjustmentNoteRequest $request, Invoice $invoice, AdjustmentNote $adjustmentNote)
    {
        $this->guarded(fn () => VoidAdjustmentNote::toggle($adjustmentNote, VoidAdjustmentNote::voidable(...), AdjustmentNoteStatus::Voided));

        return $this->withBalanceNotice($adjustmentNote);
    }

    /**
     * Unvoid an adjustment note
     */
    public function unvoid(UpdateAdjustmentNoteRequest $request, Invoice $invoice, AdjustmentNote $adjustmentNote)
    {
        $this->guarded(fn () => VoidAdjustmentNote::toggle($adjustmentNote, VoidAdjustmentNote::voidable(...), AdjustmentNoteStatus::Active));

        return $this->withBalanceNotice($adjustmentNote);
    }

    /**
     * Delete an adjustment note
     */
    public function destroy(DeleteAdjustmentNoteRequest $request, Invoice $invoice, AdjustmentNote $adjustmentNote)
    {
        $this->guarded(fn () => VoidAdjustmentNote::change($adjustmentNote, fn (AdjustmentNote $note) => $note->delete(), VoidAdjustmentNote::editable(...)));

        return $this->returnNoContent();
    }

    /** The type follows the sign, as the panel's form sets it. */
    private static function type(string $amount): string
    {
        return (float) $amount < 0 ? AdjustmentNoteType::Credit->value : AdjustmentNoteType::Debit->value;
    }

    private function withBalanceNotice(AdjustmentNote $note): AdjustmentNoteResource
    {
        $resource = new AdjustmentNoteResource($note->refresh());
        $notice = StaffLineEdit::balanceNotice($note->invoice_id);

        return $notice === null ? $resource : $resource->additional(['warning' => $notice]);
    }

    private function guarded(\Closure $change): mixed
    {
        try {
            return $change();
        } catch (DisplayException $e) {
            abort(422, $e->getMessage());
        }
    }
}
