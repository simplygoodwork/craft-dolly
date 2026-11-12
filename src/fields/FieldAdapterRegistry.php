<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Tags;
use craft\fields\Users;

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
            Entries::class => new RelationFieldAdapter(Entry::class),
            Categories::class => new RelationFieldAdapter(Category::class),
            Tags::class => new RelationFieldAdapter(Tag::class),
            Users::class => new RelationFieldAdapter(User::class),
            Assets::class => new RelationFieldAdapter(Asset::class),
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
