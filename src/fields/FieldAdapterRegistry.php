<?php

namespace simplygoodwork\craftdolly\fields;

use benf\neo\Field as NeoField;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkEditorField;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
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
use ether\seo\fields\SeoField as EtherSeoField;
use ether\simplemap\fields\MapField;
use jalendport\preparse\fields\PreparseFieldType;
use lewisjenkins\craftdynamicfields\fields\Checkboxes as DynamicCheckboxes;
use lewisjenkins\craftdynamicfields\fields\Dropdown as DynamicDropdown;
use lewisjenkins\craftdynamicfields\fields\Multiselect as DynamicMultiselect;
use lewisjenkins\craftdynamicfields\fields\Radiobuttons as DynamicRadioButtons;
use nystudio107\codefield\fields\Code;
use nystudio107\retour\fields\ShortLink;
use nystudio107\seomatic\fields\SeoSettings;
use percipiolondon\colourswatches\fields\ColourSwatches;
use presseddigital\linkit\fields\LinkitField;
use rias\positionfieldtype\fields\Position;
use simplygoodwork\craftdolly\events\RegisterFieldAdaptersEvent;
use simplygoodwork\craftdolly\references\ElementTypes;
use verbb\hyper\fields\HyperField;
use verbb\iconpicker\fields\IconPickerField;
use verbb\tablemaker\fields\TableMakerField;
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

    /**
     * @var array<class-string<ElementInterface>, RelationFieldAdapter> adapters for relation fields with no adapter of
     * their own, by the element type they relate to
     */
    private array $relations = [];

    public function __construct($config = [])
    {
        parent::__construct($config);

        $serialized = new SerializedValueAdapter();
        $options = new OptionsFieldAdapter();
        $this->fallback = new FallbackAdapter();
        $this->nestedContent = new UnsupportedNestedContentAdapter();
        $this->adapters = [
            PlainText::class => new PlainTextAdapter(),
            Matrix::class => new MatrixAdapter(),
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
            Table::class => $serialized,
            Country::class => $serialized,
            Icon::class => $serialized,
            Dropdown::class => $options,
            RadioButtons::class => $options,
            Checkboxes::class => $options,
            MultiSelect::class => $options,
        ];
        // Core field types added after Craft 5.0: registered only when this Craft version has them.
        if (class_exists(Link::class)) { // Craft 5.3
            $this->adapters[Link::class] = new LinkAdapter();
        }
        if (class_exists(Range::class)) { // Craft 5.5
            $this->adapters[Range::class] = $serialized;
        }
        if (class_exists(Json::class)) { // Craft 5.7
            $this->adapters[Json::class] = $serialized;
        }
        if (class_exists(ButtonGroup::class)) { // Craft 5.7
            $this->adapters[ButtonGroup::class] = $options;
        }
        // CKEditor before 4.0.4 replaces nested entries with their rendered HTML whenever it normalizes a value outside
        // the control panel, so it would lose them in a console or queued Export and when an Import sets the value. Its
        // fields are skipped like other nested content Dolly can't move.
        if (!InstalledVersions::isInstalled('craftcms/ckeditor') || InstalledVersions::satisfies(new VersionParser(), 'craftcms/ckeditor', '>=4.0.4')) {
            $this->adapters[CkEditorField::class] = new CkEditorAdapter();
        }
        // Optional plugins: their adapters are registered only when the plugin's field class exists.
        if (class_exists(LinkitField::class)) {
            $this->adapters[LinkitField::class] = new LinkitAdapter();
        }
        if (class_exists(EtherSeoField::class)) {
            $this->adapters[EtherSeoField::class] = new EtherSeoAdapter();
        }
        if (class_exists(ColourSwatches::class)) {
            $this->adapters[ColourSwatches::class] = new ColourSwatchesAdapter();
        }
        if (class_exists(ShortLink::class)) {
            $this->adapters[ShortLink::class] = new RetourShortLinkAdapter();
        }
        $dynamic = new DynamicFieldsAdapter();
        foreach ([DynamicDropdown::class, DynamicRadioButtons::class, DynamicCheckboxes::class, DynamicMultiselect::class] as $class) {
            if (class_exists($class)) {
                $this->adapters[$class] = $dynamic;
            }
        }
        if (class_exists(MapField::class)) {
            $this->adapters[MapField::class] = new SimpleMapAdapter();
        }
        if (class_exists(PreparseFieldType::class)) {
            $this->adapters[PreparseFieldType::class] = new PreparseAdapter();
        }
        if (class_exists(TableMakerField::class)) {
            $this->adapters[TableMakerField::class] = new TableMakerAdapter();
        }
        if (class_exists(IconPickerField::class)) {
            $this->adapters[IconPickerField::class] = new IconPickerAdapter();
        }
        foreach ([Position::class, Code::class] as $class) {
            if (class_exists($class)) {
                $this->adapters[$class] = $serialized;
            }
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
     * Returns the adapter for a field's exact class (or Matrix's, for a Matrix subclass). A relation field with no
     * adapter of its own, relating to a registered element type, gets `RelationFieldAdapter`. Any other field gets the
     * fallback (or, for a field that holds Owned content, an adapter that skips it). Returns null for a field that's
     * missing its type (a `MissingField`), which holds nothing to move.
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

        if ($field instanceof BaseRelationField && ElementTypes::supports($field::elementType())) {
            return $this->relations[$field::elementType()] ??= new RelationFieldAdapter($field::elementType());
        }

        // Raw serialization of nested content would skip Resolution of everything inside it.
        return $field instanceof ElementContainerFieldInterface ? $this->nestedContent : $this->fallback;
    }
}
