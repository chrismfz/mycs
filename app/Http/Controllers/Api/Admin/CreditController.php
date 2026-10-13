<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\Credits\CreateCreditRequest;
use App\Http\Requests\Api\Admin\Credits\DeleteCreditRequest;
use App\Http\Requests\Api\Admin\Credits\GetCreditRequest;
use App\Http\Requests\Api\Admin\Credits\GetCreditsRequest;
use App\Http\Requests\Api\Admin\Credits\UpdateCreditRequest;
use App\Http\Resources\CreditResource;
use App\Models\Credit;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Support\Facades\DB;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(name: 'Credits', weight: 4)]
class CreditController extends ApiController
{
    protected const INCLUDES = [
        'user',
    ];

    /**
     * List Credits
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetCreditsRequest $request)
    {
        // Fetch credits with pagination
        $credits = QueryBuilder::for(Credit::class)
            ->allowedFilters(['id', 'currency_code', 'user_id'])
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->allowedSorts(['id', 'created_at', 'updated_at', 'currency_code'])
            ->simplePaginate(request('per_page', 15));

        // Return the credits as a JSON response
        return CreditResource::collection($credits);
    }

    /**
     * Create a new credit
     *
     * The amount is journaled as a staff adjustment with its reason (docs/money-trail.md §2).
     */
    public function store(CreateCreditRequest $request)
    {
        $data = $request->validated();
        $cents = RecordPaymentService::toCents($data['amount']);
        $credit = $cents > 0
            ? (new CreditLedger)->add((int) $data['user_id'], $data['currency_code'], $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_API, note: $data['reason'] ?? null)
            : Credit::query()->create(['user_id' => $data['user_id'], 'currency_code' => $data['currency_code'], 'amount' => 0]);

        // Return the created credit as a JSON response
        return new CreditResource($this->loadAllowedIncludes($credit, self::INCLUDES));
    }

    /**
     * Show a specific credit
     */
    public function show(GetCreditRequest $request, Credit $credit)
    {
        $credit = QueryBuilder::for(Credit::class)
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->findOrFail($credit->id);

        // Return the credit as a JSON response
        return new CreditResource($credit);
    }

    /**
     * Update a specific credit
     *
     * A new amount is applied as a staff adjustment of the difference, with its reason. The currency changes only
     * while the balance is zero.
     *
     * @response 422 {"message": "The currency of a credit changes only while its balance is zero."}
     */
    public function update(UpdateCreditRequest $request, Credit $credit)
    {
        $data = $request->validated();
        if (array_key_exists('currency_code', $data) && $data['currency_code'] !== $credit->currency_code) {
            // Checked and changed under the ledger's lock: a payment can move this balance in between.
            DB::transaction(function () use ($credit, $data) {
                $locked = (new CreditLedger)->lock((int) $credit->user_id, $credit->currency_code);
                if ($locked === null || RecordPaymentService::toCents($locked->amount) !== 0) {
                    abort(422, 'The currency of a credit changes only while its balance is zero.');
                }
                $locked->update(['currency_code' => $data['currency_code']]);
            });
            $credit->refresh();
        }
        if (array_key_exists('amount', $data)) {
            (new CreditLedger)->setBalance((int) $credit->user_id, $credit->currency_code, RecordPaymentService::toCents($data['amount']),
                MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_API, $data['reason'] ?? null);
            $credit->refresh();
        }

        // Return the updated credit as a JSON response
        return new CreditResource($this->loadAllowedIncludes($credit, self::INCLUDES));
    }

    /**
     * Delete a specific credit
     *
     * Only a zero balance: set the amount to 0 first (with a reason), so the change is journaled.
     *
     * @response 422 {"message": "A credit with a balance cannot be deleted: set its amount to 0 first (with a reason)."}
     */
    public function destroy(DeleteCreditRequest $request, Credit $credit)
    {
        // Checked and deleted under the ledger's lock: a payment can move this balance in between.
        DB::transaction(function () use ($credit) {
            $locked = (new CreditLedger)->lock((int) $credit->user_id, $credit->currency_code);
            if ($locked !== null && RecordPaymentService::toCents($locked->amount) !== 0) {
                abort(422, 'A credit with a balance cannot be deleted: set its amount to 0 first (with a reason).');
            }
            $locked?->delete();
        });

        return $this->returnNoContent();
    }
}
