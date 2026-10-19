<?php

namespace App\Models;

use App\Observers\CategoryObserver;
use App\Services\Admin\ClientSummary;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy(CategoryObserver::class)]
class Category extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $guarded = [];

    protected $casts = [
        'show_in_menu' => 'boolean',
    ];

    /**
     * The shop menu and the home page (docs/admin-ux.md, «Μενού επισκέπτη»): top level, «Show in menu», with something
     * listed in it. The others still open by their link.
     *
     * @param  Builder<Category>  $query
     */
    public function scopeInMenu(Builder $query): void
    {
        $query->whereNull('parent_id')->where('show_in_menu', true)
            ->where(fn (Builder $query) => $query->whereHas('children')->orWhereHas('products', fn (Builder $query) => $query->where('hidden', false)))
            ->orderBy('sort');
    }

    /**
     * «From …» for the home page: of its listed products, the lowest of the prices its page shows (each product's first
     * plan with a price in the visitor's currency, as HasPlans::price()), with that plan's cycle, and per month when the
     * cycle is longer. Null when nothing has a price. Needs products.plans.prices loaded.
     *
     * @return array{price: string, cycle: string, monthly: ?string}|null
     */
    public function startingPrice(): ?array
    {
        $best = null;
        foreach ($this->products->where('hidden', false) as $product) {
            $price = $product->price();
            if (!$price->available) {
                continue;
            }
            $currency = session('currency', config('settings.default_currency'));
            $plan = $price->is_free ? null : $product->availablePlans($currency)
                ->first(fn (Plan $plan) => $plan->prices->contains('currency_code', $currency));
            if ($best === null || $price->price < $best[0]->price) {
                $best = [$price, $plan];
            }
        }
        if ($best === null) {
            return null;
        }
        [$price, $plan] = $best;
        $months = $plan?->type === 'recurring' ? match ($plan->billing_unit) {
            'month' => $plan->billing_period,
            'year' => $plan->billing_period * 12,
            default => null,
        } : null;

        return [
            'price' => $price->is_free ? __('Free') : $price->formatted->price,
            'cycle' => ClientSummary::cycle($plan),
            'monthly' => $months !== null && $months > 1 ? $price->format($price->price / $months) : null,
        ];
    }

    /**
     * Get the products of the category.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the parent category of the category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Get the children categories of the category.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    protected $auditExclude = [
        'remember_token',
    ];
}
