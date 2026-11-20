<?php

namespace simplygoodwork\craftdolly\fields;

use Craft;
use craft\base\FieldInterface;
use craft\helpers\Json;
use percipiolondon\colourswatches\fields\ColourSwatches;
use percipiolondon\colourswatches\models\ColourSwatches as Swatch;
use percipiolondon\colourswatches\models\Settings;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;

/**
 * Colour Swatches: the selected swatch travels as its label and colour.
 *
 * The field finds a swatch by its label in its palette (its own options, or a palette from the plugin's config file)
 * each time it saves a value, and takes the colour, class and default flag from there. A label the palette doesn't
 * have is silently replaced with the field's default swatch, or a random one. So on Import the label is checked
 * against the Target field's palette: a swatch it has gets the Target palette's colour, and one it doesn't have is
 * dropped with a warning that says what the field may use instead.
 */
final class ColourSwatchesAdapter implements FieldAdapter
{
    /**
     * @return array{label: string, color: mixed}|null
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if (!$value instanceof Swatch || $value->label === '') {
            return null;
        }

        return [
            'label' => $value->label,
            // A swatch's colour is a hex string, or a list of colours with their own options from a config palette.
            'color' => Json::decode(Json::encode($value->color)),
        ];
    }

    /**
     * @return array<mixed>|null the Target palette's swatch with the exported label, or null for no value
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!$field instanceof ColourSwatches || !is_array($portable)) {
            return null;
        }
        $label = $portable['label'] ?? null;
        if (!is_string($label) || $label === '') {
            return null;
        }

        $palette = $this->palette($field);
        if (isset($palette[$label])) {
            return $palette[$label];
        }

        $context->plan->warn(sprintf(
            'The field "%s" no longer has the colour "%s" in this environment. That value will be dropped%s.',
            $field->name,
            $label,
            $this->fallback($field, $palette),
        ));

        return null;
    }

    /**
     * Returns the swatches the field offers, by label, found the way the field finds them when it saves a value. Like
     * the field, the last swatch with a label wins.
     *
     * @return array<string, array<mixed>>
     */
    private function palette(ColourSwatches $field): array
    {
        $palette = $field->options;
        if ($field->useConfigFile) {
            $settings = Craft::$app->getPlugins()->getPlugin('colour-swatches')?->getSettings();
            $palette = $settings instanceof Settings
                ? ($settings->palettes[$field->palette ?? ''] ?? null) ?: $settings->colors
                : [];
        }

        $swatches = [];
        foreach (is_array($palette) ? $palette : [] as $swatch) {
            if (is_array($swatch) && is_string($swatch['label'] ?? null)) {
                $swatches[$swatch['label']] = $swatch;
            }
        }

        return $swatches;
    }

    /**
     * Describes what the field shows instead of a dropped value. The first swatch flagged as default is used whenever
     * the value is empty. The field's default setting, and its random pick, are only applied when it serializes an
     * empty value, which Craft skips for an element that's new to the site, so the warning only says they may apply.
     *
     * @param array<string, array<mixed>> $palette
     */
    private function fallback(ColourSwatches $field, array $palette): string
    {
        foreach ($palette as $label => $swatch) {
            if (filter_var($swatch['default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return sprintf(', and the field will use its default colour "%s"', $label);
            }
        }
        if ($field->default !== null && isset($palette[(string)$field->default])) {
            return sprintf(', and the field may use its default colour "%s" instead', $field->default);
        }

        return $field->setRandom && $palette ? ', and the field may pick a random colour instead' : '';
    }
}
