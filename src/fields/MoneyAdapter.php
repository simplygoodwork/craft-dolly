<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\FieldInterface;
use craft\fields\Money;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;

/**
 * Money: the amount in minor units travels with the Source field's currency, so Import can warn when the Target field's
 * currency differs, since the same amount may then be worth a different magnitude (USD cents against yen).
 */
final class MoneyAdapter implements FieldAdapter
{
    /**
     * @return array{amount: string, currency: string}|null
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        $amount = $field->serializeValue($value, $context->element());

        return $field instanceof Money && is_string($amount) ? ['amount' => $amount, 'currency' => $field->currency] : null;
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!$field instanceof Money || !is_array($portable) || !is_string($portable['amount'] ?? null)) {
            return null;
        }

        $currency = $portable['currency'] ?? null;
        if (is_string($currency) && $currency !== $field->currency) {
            $context->plan->warn(sprintf(
                'The field "%s" is in %s in the source environment and %s in this one. Its amount is in minor units and will be imported as it is, so it may change magnitude.',
                $field->name,
                $currency,
                $field->currency,
            ));
        }

        return $portable['amount'];
    }
}
