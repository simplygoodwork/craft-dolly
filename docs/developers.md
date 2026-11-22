# Extending Dolly

## Register a field adapter

Register an adapter for your field class with `FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS`. Implement `simplygoodwork\craftdolly\fields\FieldAdapter`:

- `export()` returns a JSON-safe value for the Payload.
- `import()` returns a value for `setFieldValue()` in the Target environment.

Adapters match the field’s exact class. Your registered adapter replaces the fallback and any adapter Dolly ships for that class.

```php
use simplygoodwork\craftdolly\events\RegisterFieldAdaptersEvent;
use simplygoodwork\craftdolly\fields\FieldAdapterRegistry;
use yii\base\Event;

Event::on(
    FieldAdapterRegistry::class,
    FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS,
    function(RegisterFieldAdaptersEvent $event) {
        $event->adapters[MyField::class] = new MyFieldAdapter();
    },
);
```

Register the handler while your plugin initializes, before Dolly is first used in the request. The registry is built once; later registrations are ignored. Every value must implement `FieldAdapter`. Otherwise, building the registry throws an `InvalidConfigException`.

During `export()`, `$context->element()` gives you the element holding the value. Use it if the field’s `serializeValue()` needs the owner. Import has no Target element while it plans, so `import()` has none.

Use `ExportContext::addReference()` and `ImportContext::resolveReference()` for elements your value points to. Use `$context->plan->warn()` to report anything the Import drops.

## Register an element type

Dolly’s registered element types are entries, categories, tags, users and assets. It also registers Formie forms (`formieForm`) and Solspace Calendar events (`calendarEvent`) when their plugins are installed and enabled.

Register your own type with `ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES`. Give it a short name that the Payload carries instead of its class ([ADR-0004](adr/0004-registry-of-element-types.md)). Relation fields for that type then work without their own Field adapter. So do Link and Hyper links to it. References match by UID, then by the type’s Natural key.

Extend `simplygoodwork\craftdolly\references\ElementTypeDefinition`. Only three methods are required:

- `elementClass()`: the element class.
- `naturalKey()`: the identifying attributes carried by the Reference, in JSON-safe form.
- `find()`: the ID of the single element matching those attributes, or null.

By default, References of your type are not limited to an element source and cannot be Stubbed. Override `sourceFor()`, `isInSource()` and `sourceName()` to limit replacements to the Reference’s own source. Override `canBeStubbed()` and `stub()` to allow Stubs.

Override `query()` if the type’s own element query has limits that do not apply to relations. Dolly uses this query to match References by UID and load matched or replacement elements.

```php
use simplygoodwork\craftdolly\events\RegisterElementTypesEvent;
use simplygoodwork\craftdolly\references\ElementTypeRegistry;
use yii\base\Event;

Event::on(
    ElementTypeRegistry::class,
    ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES,
    function(RegisterElementTypesEvent $event) {
        $event->types['widget'] = new WidgetDefinition();
    },
);
```

Register the type under the same short name in every environment. An unregistered name refuses the Payload. Names must contain only letters and digits, starting with a letter.

The short name is used only in the Payload. Messages and the Preview use the class’s `lowerDisplayName()`, such as “form” or “event”. Imported reference tags use its `refHandle()` in the Target environment.

As with adapters, register the handler before Dolly is first used in the request. An `InvalidConfigException` is thrown for a definition that does not extend `ElementTypeDefinition`, an invalid or reserved name, or a class already registered under another name.

See `tests/_support/WidgetDefinition.php` for a complete example.

## Configure Dynamic Fields

LJ Dynamic Fields’ Dropdown, Radio Buttons, Checkboxes and Multi-select fields render options from Twig templates. Their values are often IDs that mean something else in another environment.

In `config/dolly.php`, declare what each field’s values identify. Use the field’s own handle, even if a field layout overrides it:

```php
return [
    'dynamicFields' => [
        'sectionIDs' => 'section',
        'featuredEntry' => 'entry',
    ],
];
```

Choose a kind for each field:

- `section`, `entryType`, `categoryGroup`, `volume` or `site`: values travel as Schema references, with their Drift listed in the Preview.
- A registered element type’s short name, such as `entry`, `category`, `tag`, `user` or `asset`: values travel as References. They can be matched, replaced, Stubbed or Fetched as appropriate. You can use any [registered element type](#register-an-element-type).

On Import, each value becomes the ID of the same thing in the Target environment. A value that does not resolve, or was not an ID of that kind in the Source environment, is dropped with a warning. An unknown kind makes Export and Import fail with an `InvalidConfigException`.

Declare the field the same way in every environment. The Payload carries its declared kind. If the Target environment declares another kind, or only one environment declares the field, its values are skipped with a warning. This prevents Dolly from reading them as IDs of something else.

An empty selection is imported either way. An undeclared Dynamic field uses Craft’s serialization fallback, with its warning.

## Use the core service for block pastes and queues

For a block paste, pass a `BlockTarget` to the core service’s `import()` and `preview()`. It identifies the draft and one of its Matrix or Neo fields.

The core service’s `import()` only queues when called with `queue: true`, as the Dolly utility does. The CLI and block pastes run inline. Keeping block pastes inline is a product decision that departs from “any asset fetch” in the spec: the editor needs the blocks in its draft when the paste finishes.
