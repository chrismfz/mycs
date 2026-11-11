<?php

namespace App\Http\Requests\Api\Admin\Payments;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class GetPaymentsRequest extends AdminApiRequest
{
    protected $permission = 'invoice_transactions.view';
}
