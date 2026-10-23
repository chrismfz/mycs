<?php

namespace App\Admin\Concerns;

use Filament\Schemas\Schema;

/**
 * A page's form in two columns with its labels beside the fields (owner 2026-10-23, as WHMCS): Filament's inline labels
 * (BasePage::inlineLabels) make a form without columns of its own one column wide; this keeps it two (review of #399).
 */
trait TwoColumnForm
{
    public function defaultForm(Schema $schema): Schema
    {
        if (!$schema->hasCustomColumns()) {
            $schema->columns(2);
        }

        return parent::defaultForm($schema);
    }
}
