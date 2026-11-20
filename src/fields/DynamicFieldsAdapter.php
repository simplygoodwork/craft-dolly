<?php

namespace simplygoodwork\craftdolly\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use lewisjenkins\craftdynamicfields\fields\Checkboxes;
use lewisjenkins\craftdynamicfields\fields\Multiselect;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\references\ElementTypes;
use yii\base\InvalidConfigException;

/**
 * LJ Dynamic Fields' dropdown, radio buttons, checkboxes and multi-select fields. Their options are rendered from a
 * Twig template, so their values are often IDs: pbs-hub's "sectionIDs" stores section IDs. Each stores its value as a
 * string: the selected option's value, or a JSON array of them for checkboxes and multi-select.
 *
 * Dolly can't tell what the IDs are of, so `config/dolly.php` declares it by the field's handle (`dynamicFields`).
 * A declared field's values travel as UIDs instead: Schema references (with Drift) for a Schema reference kind, or
 * References (matched, replaced or Stubbed like any other) for an element type. A value that doesn't resolve is dropped
 * from the field with a warning.
 *
 * The Payload carries the kind it was declared as, so the Import can check that both environments declare the field the
 * same way: if they don't (or only one declares it), the IDs or UIDs would be read as something they aren't, so the
 * value is skipped with a warning. An undeclared field in both environments falls back to Craft's serialization, with
 * its warning.
 */
final class DynamicFieldsAdapter implements CompletesTokens
{
    /**
     * Schema reference kinds a Dynamic field can be declared as => [the Payload's `schema` kind, label for messages].
     */
    private const SCHEMA_KINDS = [
        'section' => ['sections', 'section'],
        'entryType' => ['entryTypes', 'entry type'],
        'categoryGroup' => ['categoryGroups', 'category group'],
        'volume' => ['volumes', 'volume'],
        'site' => ['sites', 'site'],
    ];

    public function __construct(private readonly FallbackAdapter $fallback = new FallbackAdapter())
    {
    }

    /**
     * @return array{kind: string, values: list<string>, unmatched?: list<string>}|mixed for a declared field, the UIDs
     * of what its IDs identify, and any values that identify nothing; otherwise the fallback's value
     * @throws InvalidConfigException if the field is declared as a kind Dolly doesn't know
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        $kind = $this->declaredKind($field);
        if ($kind === null) {
            return $this->fallback->export($field, $value, $context);
        }

        $values = [];
        $unmatched = [];
        foreach ($this->selected($field, $value) as $selected) {
            $uid = $this->exportValue($kind, $selected, $context);
            if ($uid !== null) {
                $values[] = $uid;
            } else {
                $unmatched[] = $selected;
            }
        }

        $portable = ['kind' => $kind, 'values' => array_values(array_unique($values))];
        if ($unmatched) {
            $portable['unmatched'] = $unmatched;
        }

        return $portable;
    }

    /**
     * @return list<string>|string|SkippedValue|null the IDs (or Stub and fetched asset tokens) in this environment, as a
     * list for checkboxes and multi-select or one value for the others, or null for none; or the fallback's value
     * @throws InvalidConfigException if the field is declared as a kind Dolly doesn't know
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        $kind = $this->declaredKind($field);
        $exportedKind = is_array($portable) && is_string($portable['kind'] ?? null) ? $portable['kind'] : null;

        if ($this->isEmpty($portable)) {
            return null;
        }

        if ($kind === null && $exportedKind === null) {
            return $this->fallback->import($field, $portable, $context);
        }

        if ($kind !== $exportedKind) {
            $context->plan->warn(sprintf(
                'The field "%s" %s. Its values will be skipped.',
                $field->name,
                match (true) {
                    $exportedKind === null => sprintf('holds %s IDs in this environment, but it wasn\'t declared in the dynamicFields setting where it was exported, so its values are IDs from that environment', $this->kindName($kind)),
                    $kind === null => sprintf('held %s IDs where it was exported, but it isn\'t declared in the dynamicFields setting in this environment', $this->kindName($exportedKind)),
                    default => sprintf('held %s IDs where it was exported, but holds %s IDs in this environment', $this->kindName($exportedKind), $this->kindName($kind)),
                },
            ));

            return SkippedValue::Skipped;
        }

        foreach (is_array($portable['unmatched'] ?? null) ? $portable['unmatched'] : [] as $unmatched) {
            $context->plan->warn(sprintf(
                'The value "%s" in the field "%s" wasn\'t the ID of a %s where it was exported. It will be dropped from the field.',
                is_scalar($unmatched) ? (string)$unmatched : '',
                $field->name,
                $this->kindName($kind),
            ));
        }

        $ids = [];
        foreach (is_array($portable['values'] ?? null) ? $portable['values'] : [] as $uid) {
            $id = is_string($uid) && $uid !== '' ? $this->importValue((string)$kind, $uid, $field, $context) : null;
            if ($id !== null) {
                $ids[] = (string)$id;
            }
        }

        if (!$ids) {
            return null;
        }

        return $this->isMultiple($field) ? $ids : $ids[0];
    }

    /**
     * Replaces the tokens of Stubs and fetched assets with their IDs. A fetched asset whose fetch failed has no ID, so
     * it's dropped from the field; the fetch failure is already reported.
     *
     * @return list<string>|string|null
     */
    public function completeTokens(mixed $value, ElementInterface $element, callable $render, array $linkTokens): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = Json::decodeIfJson($value);
        $multiple = is_array($decoded);
        $values = $multiple ? $decoded : [$value];
        if (!$render($values)) {
            return null;
        }

        $values = array_values(array_filter($values, fn($id) => is_scalar($id) && (string)$id !== ''));
        $values = array_map(fn($id) => (string)$id, $values);

        return $multiple ? $values : $values[0] ?? '';
    }

    /**
     * Returns the kind the field is declared as in this environment, or null if it isn't declared.
     *
     * Fields are declared by their own handle, not a handle a field layout overrides it with.
     *
     * @throws InvalidConfigException if the field is declared as a kind Dolly doesn't know
     */
    private function declaredKind(FieldInterface $field): ?string
    {
        $handle = ($field->uid !== null ? Craft::$app->getFields()->getFieldByUid($field->uid)?->handle : null) ?? $field->handle;
        $kind = Plugin::getInstance()->getSettings()->dynamicFields[$handle] ?? null;
        if ($kind === null) {
            return null;
        }

        if (!is_string($kind) || (!isset(self::SCHEMA_KINDS[$kind]) && ElementTypes::definition($kind) === null)) {
            throw new InvalidConfigException(sprintf(
                'The dynamicFields setting declares the field "%s" as "%s", which isn\'t a kind Dolly knows. Use one of %s, or the short name of a registered element type, such as entry, category, tag, user or asset.',
                $handle,
                is_scalar($kind) ? (string)$kind : gettype($kind),
                implode(', ', array_keys(self::SCHEMA_KINDS)),
            ));
        }

        return $kind;
    }

    /**
     * Returns the selected values of a field's value: the selected option's value, or each of them for checkboxes and
     * multi-select, which store a JSON array.
     *
     * @return list<string>
     */
    private function selected(FieldInterface $field, mixed $value): array
    {
        if ($this->isMultiple($field) && is_string($value)) {
            $value = Json::decodeIfJson($value);
        }
        $values = is_array($value) ? $value : [$value];

        return array_values(array_map(
            fn($selected) => (string)$selected,
            array_filter($values, fn($selected) => is_scalar($selected) && (string)$selected !== ''),
        ));
    }

    /**
     * Returns the UID of the Schema reference or element an ID identifies, after recording it as a Schema reference or
     * Reference, or null if it identifies nothing of the kind.
     */
    private function exportValue(string $kind, string $value, ExportContext $context): ?string
    {
        if (!ctype_digit($value) || (int)$value === 0) {
            return null;
        }
        $id = (int)$value;

        if (isset(self::SCHEMA_KINDS[$kind])) {
            $uid = match ($kind) {
                'section' => Craft::$app->getEntries()->getSectionById($id)?->uid,
                'entryType' => Craft::$app->getEntries()->getEntryTypeById($id)?->uid,
                'categoryGroup' => Craft::$app->getCategories()->getGroupById($id)?->uid,
                'volume' => Craft::$app->getVolumes()->getVolumeById($id)?->uid,
                'site' => Craft::$app->getSites()->getSiteById($id, true)?->uid,
            };

            return $uid !== null ? $context->recordSchemaReference(self::SCHEMA_KINDS[$kind][0], $uid) : null;
        }

        $element = ElementTypes::definition($kind)?->query()->id($id)->status(null)->site('*')->unique()->one();

        return $element instanceof ElementInterface ? $context->addReference($element) : null;
    }

    /**
     * Returns the ID in this environment of the Schema reference or element a UID identifies, or a token for the ID of
     * a Stub or fetched asset. Returns null, after warning, if it doesn't resolve.
     */
    private function importValue(string $kind, string $uid, FieldInterface $field, ImportContext $context): int|string|null
    {
        if (!isset(self::SCHEMA_KINDS[$kind])) {
            $class = ElementTypes::classFor($kind);
            if ($class === null) {
                return null;
            }

            return $context->embeddedId(
                $uid,
                $class,
                $field,
                sprintf('A value in the field "%s" doesn\'t point at a %s the Payload carries. It will be dropped from the field.', $field->name, ElementTypes::displayName($kind)),
                'selected',
                'It will be dropped from the field.',
            );
        }

        [$schemaKind, $label] = self::SCHEMA_KINDS[$kind];
        $id = match ($kind) {
            'section' => Craft::$app->getEntries()->getSectionByUid($uid)?->id,
            'entryType' => Craft::$app->getEntries()->getEntryTypeByUid($uid)?->id,
            'categoryGroup' => Craft::$app->getCategories()->getGroupByUid($uid)?->id,
            'volume' => Craft::$app->getVolumes()->getVolumeByUid($uid)?->id,
            'site' => ArrayHelper::firstWhere(Craft::$app->getSites()->getAllSites(true), 'uid', $uid)?->id,
        };
        if ($id === null) {
            $context->plan->warn(sprintf(
                'The %s "%s" selected in the field "%s" doesn\'t exist in this environment. It will be dropped from the field.',
                $label,
                $context->drift->get($schemaKind, $uid)->name ?? $uid,
                $field->name,
            ));
        }

        return $id;
    }

    /**
     * What the IDs of a kind identify, in messages: "section", "entry", "form".
     */
    private function kindName(?string $kind): string
    {
        return self::SCHEMA_KINDS[$kind][1] ?? ElementTypes::displayName($kind);
    }

    private function isMultiple(FieldInterface $field): bool
    {
        return $field instanceof Multiselect || $field instanceof Checkboxes;
    }

    /**
     * Whether a portable value selects nothing, whichever way either environment declares it, so there's nothing to
     * translate or warn about.
     */
    private function isEmpty(mixed $portable): bool
    {
        if (is_array($portable) && isset($portable['kind'])) {
            return empty($portable['values']) && empty($portable['unmatched']);
        }
        if (is_string($portable)) {
            $portable = Json::decodeIfJson($portable);
        }

        return $portable === null || $portable === '' || $portable === [];
    }
}
