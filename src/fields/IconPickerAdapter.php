<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\FieldInterface;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;
use verbb\iconpicker\base\IconSet;
use verbb\iconpicker\fields\IconPickerField;
use verbb\iconpicker\IconPicker;
use verbb\iconpicker\iconsets\SvgFolder;
use verbb\iconpicker\models\Icon;
use verbb\iconpicker\models\Settings;

/**
 * Icon Picker: the selected icon travels as Icon Picker stores it: the icon's value (an SVG file's path in the icon
 * sets folder, a sprite ID, a CSS class or a glyph), its type and label, and its icon set.
 *
 * Icon sets are in project config, so the icon set's UID (stored from Icon Picker 3.1) names the same set in every
 * environment, and its handle (stored from 3.0.1) names it on versions before that. On Import the icon set is found by UID, or by handle
 * when the value has no UID. Like Icon Picker, a UID that's missing doesn't fall back to the handle. An icon whose set
 * doesn't exist in the Target environment, or that the Target field doesn't offer, is dropped with a warning. An icon
 * from an SVG folder whose file isn't in the Target's icon sets folder is kept with a warning, since that folder isn't
 * in project config and is usually deployed with the site. A value from Icon Picker 3.0.0, which names neither, is
 * imported as it is.
 */
final class IconPickerAdapter implements FieldAdapter
{
    /**
     * @return array<string, string>|null
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if (!$value instanceof Icon || $value->value === null || $value->value === '') {
            return null;
        }

        $serialized = $field->serializeValue($value, $context->element());
        $serialized = is_array($serialized) ? $serialized : Json::decodeIfJson((string)Json::encode($serialized));
        if (!is_array($serialized)) {
            return null;
        }
        // Presentation markup, which Icon Picker rebuilds from the icon set and ignores in a value it's given.
        unset($serialized['displayValue']);

        return array_filter($serialized, fn($v) => is_string($v) && $v !== '');
    }

    /**
     * @return array<string, string>|null the icon for the Target field, or null for no value
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!$field instanceof IconPickerField || !is_array($portable)) {
            return null;
        }
        $value = $portable['value'] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        // Only the properties the Target's Icon Picker version has: an Icon is a model, which refuses others.
        $icon = array_filter(
            $portable,
            fn($v, $key) => is_string($key) && is_string($v) && $key !== 'displayValue' && property_exists(Icon::class, $key),
            ARRAY_FILTER_USE_BOTH,
        );

        $uid = is_string($portable['iconSetUid'] ?? null) ? $portable['iconSetUid'] : null;
        $handle = is_string($portable['iconSetHandle'] ?? null) ? $portable['iconSetHandle'] : null;
        if ($uid === null && $handle === null) {
            return $icon;
        }

        $label = is_string($portable['label'] ?? null) && $portable['label'] !== '' ? $portable['label'] : $value;
        $iconSets = IconPicker::$plugin->getIconSets();
        $iconSet = $uid !== null ? $iconSets->getIconSetByUid($uid) : $iconSets->getIconSetByHandle($handle);
        if (!$iconSet instanceof IconSet) {
            $context->plan->warn(sprintf(
                'The field "%s" has the icon "%s" from the icon set "%s", which doesn\'t exist in this environment. That value will be dropped.',
                $field->name,
                $label,
                $handle ?? $uid,
            ));

            return null;
        }

        if (!$this->offers($field, $iconSet)) {
            $context->plan->warn(sprintf(
                'The field "%s" doesn\'t offer the icon set "%s" in this environment. Its icon "%s" will be dropped.',
                $field->name,
                $iconSet->name ?? $iconSet->handle,
                $label,
            ));

            return null;
        }

        // The icon set may have been renamed: the value names it as the Target environment does.
        foreach (['iconSetUid' => $iconSet->uid, 'iconSetHandle' => $iconSet->handle] as $key => $identifier) {
            if (property_exists(Icon::class, $key) && is_string($identifier)) {
                $icon[$key] = $identifier;
            }
        }

        if ($iconSet instanceof SvgFolder && ($portable['type'] ?? null) === Icon::TYPE_SVG && !$this->svgExists($value)) {
            $context->plan->warn(sprintf(
                'The field "%s" has the icon "%s", whose file "%s" isn\'t in this environment\'s icon sets folder. The icon will be imported, but won\'t show until the file is added.',
                $field->name,
                $label,
                ltrim($value, '/\\'),
            ));
        }

        return $icon;
    }

    /**
     * Whether the field offers the icon set, the way Icon Picker decides which sets its input shows: every enabled set,
     * or the enabled sets among those the field chose, by UID.
     */
    private function offers(IconPickerField $field, IconSet $iconSet): bool
    {
        foreach (IconPicker::$plugin->getIconSets()->getIconSetsForField($field) as $offered) {
            if ($offered instanceof IconSet && $offered->uid === $iconSet->uid) {
                return true;
            }
        }

        return false;
    }

    private function svgExists(string $value): bool
    {
        $settings = IconPicker::$plugin->getSettings();
        $root = $settings instanceof Settings ? $settings->getIconSetsPath() : '';

        return $root !== '' && is_file(FileHelper::normalizePath($root . DIRECTORY_SEPARATOR . ltrim($value, '/\\')));
    }
}
