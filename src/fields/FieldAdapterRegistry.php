<?php

namespace simplygoodwork\craftportage\fields;

use benf\neo\Field as NeoField;
use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkEditorField;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\Assets;
use craft\fields\ButtonGroup;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Entries;
use craft\fields\Icon;
use craft\fields\Json;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\MissingField;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Range;
use craft\fields\Table;
use craft\fields\Tags;
use craft\fields\Time;
use craft\fields\Users;
use nystudio107\seomatic\fields\SeoSettings;
use presseddigital\linkit\fields\LinkitField;
use simplygoodwork\craftportage\events\RegisterFieldAdaptersEvent;
use verbb\hyper\fields\HyperField;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Maps field classes to their Field adapters.
 */
final class FieldAdapterRegistry extends Component
{
    /**
     * @event RegisterFieldAdaptersEvent Triggered when the registry is built, to register a Field adapter for a field class.
     */
    public const EVENT_REGISTER_ADAPTERS = 'registerAdapters';

    /**
     * @var array<class-string<FieldInterface>, FieldAdapter>
     */
    private array $adapters;

    private readonly FieldAdapter $fallback;

    private readonly FieldAdapter $nestedContent;

    public function __construct($config = [])
    {
        parent::__construct($config);

        $serialized = new SerializedValueAdapter();
        $options = new OptionsFieldAdapter();
        $this->fallback = new FallbackAdapter();
        $this->nestedContent = new UnsupportedNestedContentAdapter();
        $this->adapters = [
            PlainText::class => new PlainTextAdapter(),
            Link::class => new LinkAdapter(),
            Matrix::class => new MatrixAdapter(),
            CkEditorField::class => new CkEditorAdapter(),
            NeoField::class => new NeoAdapter(),
            HyperField::class => new HyperAdapter(),
            SeoSettings::class => new SeoSettingsAdapter(),
            Entries::class => new RelationFieldAdapter(Entry::class),
            Categories::class => new RelationFieldAdapter(Category::class),
            Tags::class => new RelationFieldAdapter(Tag::class),
            Users::class => new RelationFieldAdapter(User::class),
            Assets::class => new RelationFieldAdapter(Asset::class),
            Number::class => $serialized,
            Lightswitch::class => $serialized,
            Date::class => $serialized,
            Time::class => $serialized,
            Color::class => $serialized,
            Email::class => $serialized,
            Money::class => new MoneyAdapter(),
            Range::class => $serialized,
            Table::class => $serialized,
            Json::class => $serialized,
            Country::class => $serialized,
            Icon::class => $serialized,
            Dropdown::class => $options,
            RadioButtons::class => $options,
            Checkboxes::class => $options,
            MultiSelect::class => $options,
            ButtonGroup::class => $options,
        ];
        // Optional plugins: their adapters are registered only when the plugin's field class exists.
        if (class_exists(LinkitField::class)) {
            $this->adapters[LinkitField::class] = new LinkitAdapter();
        }

        $event = new RegisterFieldAdaptersEvent(['adapters' => $this->adapters]);
        $this->trigger(self::EVENT_REGISTER_ADAPTERS, $event);
        foreach ($event->adapters as $class => $adapter) {
            if (!$adapter instanceof FieldAdapter) {
                throw new InvalidConfigException("The adapter registered for $class must implement " . FieldAdapter::class . '.');
            }
        }
        $this->adapters = $event->adapters;
    }

    /**
     * Returns the adapter for a field's exact class (or Matrix's, for a Matrix subclass), the fallback if it has none (or, for a field that holds Owned content, an adapter that skips it), or null for a field that's missing its
     * type (a `MissingField`), which holds nothing to move.
     */
    public function adapterFor(FieldInterface $field): ?FieldAdapter
    {
        if ($field instanceof MissingField) {
            return null;
        }

        if (isset($this->adapters[$field::class])) {
            return $this->adapters[$field::class];
        }

        // A Matrix subclass (Super Table 4, for one) stores its blocks as Matrix does: nested entries of its entry types.
        if ($field instanceof Matrix && isset($this->adapters[Matrix::class])) {
            return $this->adapters[Matrix::class];
        }

        // Raw serialization of nested content would skip Resolution of everything inside it.
        return $field instanceof ElementContainerFieldInterface ? $this->nestedContent : $this->fallback;
    }
}
