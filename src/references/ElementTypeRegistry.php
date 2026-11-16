<?php

namespace simplygoodwork\craftportage\references;

use Craft;
use craft\base\ElementInterface;
use simplygoodwork\craftportage\events\RegisterElementTypesEvent;
use simplygoodwork\craftportage\references\definitions\AssetDefinition;
use simplygoodwork\craftportage\references\definitions\CalendarEventDefinition;
use simplygoodwork\craftportage\references\definitions\CategoryDefinition;
use simplygoodwork\craftportage\references\definitions\EntryDefinition;
use simplygoodwork\craftportage\references\definitions\FormieFormDefinition;
use simplygoodwork\craftportage\references\definitions\TagDefinition;
use simplygoodwork\craftportage\references\definitions\UserDefinition;
use Solspace\Calendar\Elements\Event as CalendarEvent;
use verbb\formie\elements\Form;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * The element types a Payload may carry as References (and, for entries, as Root elements), by short name. The
 * Payload names a type only by its short name, and a short name that isn't registered is refused, so the Payload never
 * decides which PHP class is used (ADR-0004).
 */
final class ElementTypeRegistry extends Component
{
    /**
     * @event RegisterElementTypesEvent Triggered when the registry is built, to register an element type.
     */
    public const EVENT_REGISTER_ELEMENT_TYPES = 'registerElementTypes';

    /**
     * Letters and digits, starting with a letter, so the name also works as the element type of a reference tag.
     */
    private const NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9]*$/';

    /**
     * @var array<string, ElementTypeDefinition>
     */
    private array $definitions = [];

    /**
     * @var array<class-string<ElementInterface>, string>
     */
    private array $names = [];

    /**
     * @throws InvalidConfigException if a registered element type isn't valid
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $types = [
            'entry' => new EntryDefinition(),
            'category' => new CategoryDefinition(),
            'tag' => new TagDefinition(),
            'user' => new UserDefinition(),
            'asset' => new AssetDefinition(),
        ];
        // Plugins Portage supports: registered only when the plugin is installed.
        if (class_exists(Form::class)) {
            $types['formieForm'] = new FormieFormDefinition();
        }
        if (class_exists(CalendarEvent::class) && Craft::$app->getPlugins()->isPluginEnabled('calendar')) {
            $types['calendarEvent'] = new CalendarEventDefinition();
        }

        $event = new RegisterElementTypesEvent(['types' => $types]);
        $this->trigger(self::EVENT_REGISTER_ELEMENT_TYPES, $event);

        foreach ($event->types as $name => $definition) {
            $this->register((string)$name, $definition);
        }
    }

    /**
     * Returns the definition registered under a short name, or null if there's none. The name may come from a Payload.
     */
    public function get(mixed $name): ?ElementTypeDefinition
    {
        return is_string($name) ? $this->definitions[$name] ?? null : null;
    }

    /**
     * Returns the short name of an element class, matched exactly, or null if it isn't registered.
     */
    public function nameOfClass(string $class): ?string
    {
        return $this->names[$class] ?? null;
    }

    /**
     * @throws InvalidConfigException
     */
    private function register(string $name, mixed $definition): void
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidConfigException("The element type name \"$name\" isn't valid: use letters and digits, starting with a letter.");
        }
        if ($name === ElementTypes::NEO_BLOCK) {
            throw new InvalidConfigException("The element type name \"$name\" is reserved for Neo blocks.");
        }
        if (!$definition instanceof ElementTypeDefinition) {
            throw new InvalidConfigException("The element type registered as \"$name\" must extend " . ElementTypeDefinition::class . '.');
        }

        $class = $definition->elementClass();
        if (!is_subclass_of($class, ElementInterface::class)) {
            throw new InvalidConfigException("The element type registered as \"$name\" names $class, which isn't an element class.");
        }
        if (isset($this->names[$class])) {
            throw new InvalidConfigException("The element type registered as \"$name\" names $class, which is already registered as \"{$this->names[$class]}\".");
        }

        $this->definitions[$name] = $definition;
        $this->names[$class] = $name;
    }
}
