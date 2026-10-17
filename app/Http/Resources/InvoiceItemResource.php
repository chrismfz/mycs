<?php

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;

class InvoiceItemResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'description',
        'quantity',
        'price',
        'taxed',
        'tax_rate',
        'tax_amount',
        'line_total',
        'reference_type',
        'reference_id',
        // What it is and the period it bills (F12 of #174): a domain line made with kind «renewal» says which year.
        'kind',
        'period_start',
        'period_end',
        'updated_at',
        'created_at',
    ];

    public function toRelationships($request)
    {
        return [
            'reference' => function () {
                if ($this->reference_type === 'App\Models\Credit') {
                    return new CreditResource($this->reference);
                } elseif ($this->reference_type === 'App\Models\ServiceUpgrade') {
                    return new ServiceUpgradeResource($this->reference);
                } elseif ($this->reference_type === 'App\Models\Domain') {
                    return null; // no domain resource in the API yet: its id is in reference_id
                }

                return new ServiceResource($this->reference);
            },
            'invoice' => $this->whenLoaded('invoice', function () {
                return new InvoiceResource($this->invoice);
            }),
        ];
    }
}
