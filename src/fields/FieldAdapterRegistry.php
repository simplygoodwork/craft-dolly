<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\PlainText;

/**
 * Maps field classes to their Field adapters.
 */
final class FieldAdapterRegistry
{
    /**
     * @var array<class-string<FieldInterface>, FieldAdapter>
     */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            PlainText::class => new PlainTextAdapter(),
            Matrix::class => new MatrixAdapter(),
            Entries::class => new EntriesAdapter(),
        ];
    }

    /**
     * Returns the adapter for a field's exact class, or null if the field type isn't supported.
     */
    public function adapterFor(FieldInterface $field): ?FieldAdapter
    {
        return $this->adapters[$field::class] ?? null;
    }
}
