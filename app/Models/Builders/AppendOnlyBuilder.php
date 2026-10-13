<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Query builder of an append-only table (App\Models\MoneyEvent): a mass update or delete never fires model events,
 * so it is refused here too. Rows are only inserted.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('This table is append-only: write a reversal instead.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('This table is append-only: write a reversal instead.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('This table is append-only: write a reversal instead.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('This table is append-only: write a reversal instead.');
    }

    public function delete()
    {
        throw new LogicException('This table is append-only: write a reversal instead.');
    }

    public function forceDelete()
    {
        throw new LogicException('This table is append-only: write a reversal instead.');
    }
}
