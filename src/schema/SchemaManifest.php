<?php

namespace simplygoodwork\craftdolly\schema;

use Craft;
use craft\base\FieldInterface;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use verbb\hyper\fields\HyperField;

/**
 * Describes the Schema references a Payload touches (the envelope's `schema` section), with a Fingerprint for each,
 * and classifies their Drift in the Target environment.
 *
 * A Fingerprint is a hash of the item's project config subtree, so environments with the same project config
 * produce the same Fingerprint.
 */
final class SchemaManifest
{
    /**
     * Schema reference kinds => [project config path, label for messages]. A kind with no path of its own (a Hyper link
     * type is part of its field's settings, or of a shared link type config) is looked up by `lookup()`.
     */
    public const KINDS = [
        'sites' => ['sites', 'site'],
        'sections' => ['sections', 'section'],
        'entryTypes' => ['entryTypes', 'entry type'],
        'neoBlockTypes' => ['neo.blockTypes', 'Neo block type'],
        'hyperLinkTypes' => [null, 'Hyper link type'],
        'fields' => ['fields', 'field'],
        'volumes' => ['volumes', 'volume'],
        'categoryGroups' => ['categoryGroups', 'category group'],
    ];

    /**
     * Keys that can differ between environments with the same schema.
     */
    private const VOLATILE_KEYS = ['dateAdded', 'dateModified'];

    /**
     * Where Hyper 3 keeps its shared link type configs, by UID.
     */
    private const HYPER_LINK_TYPE_CONFIGS = 'plugins.hyper.linkTypeConfigs';

    /**
     * The key of a Hyper link type as a Schema reference: its field's UID and its handle.
     *
     * A link type is keyed per field, and by handle rather than by the UID of its field layout. On Hyper 3, fields that
     * share a link type config share its layout UIDs, and a link type saved without one gets a new one on every
     * request. Hyper itself matches a link to its link type by handle.
     */
    public static function hyperLinkTypeKey(string $fieldUid, string $handle): string
    {
        return "$fieldUid/$handle";
    }

    /**
     * Builds the envelope's `schema` section.
     *
     * @param array<string, list<string>> $uidsByKind
     * @return array<string, array<string, array<string, string|null>>> kind => UID => description
     */
    public function describe(array $uidsByKind): array
    {
        $schema = [];

        foreach (self::KINDS as $kind => [$path]) {
            $schema[$kind] = [];
            foreach ($uidsByKind[$kind] ?? [] as $uid) {
                $config = $this->config($kind, $path, $uid) ?? [];
                $description = [
                    'handle' => is_string($config['handle'] ?? null) ? $config['handle'] : null,
                    'name' => is_string($config['name'] ?? null) ? $config['name'] : null,
                ];
                if ($kind === 'fields' || $kind === 'hyperLinkTypes') {
                    $description['type'] = is_string($config['type'] ?? null) ? $config['type'] : null;
                }
                if ($kind === 'hyperLinkTypes') {
                    $field = Craft::$app->getProjectConfig()->get('fields.' . explode('/', $uid, 2)[0]);
                    $description['field'] = is_string($field['name'] ?? null) ? $field['name'] : null;
                }
                $description['fingerprint'] = $this->fingerprint($config);
                $schema[$kind][$uid] = $description;
            }
        }

        return $schema;
    }

    /**
     * Classifies the Drift of every Schema reference described in a Payload.
     */
    public function classify(mixed $schema): DriftReport
    {
        $report = new DriftReport();

        foreach (self::KINDS as $kind => [$path, $label]) {
            $described = is_array($schema) && is_array($schema[$kind] ?? null) ? $schema[$kind] : [];
            foreach ($described as $uid => $description) {
                if (is_string($uid) && is_array($description)) {
                    $report->add($this->drift($kind, $path, $label, $uid, $description));
                }
            }
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $description
     */
    private function drift(string $kind, ?string $path, string $label, string $uid, array $description): Drift
    {
        $name = $description['name'] ?? $description['handle'] ?? null;
        $name = is_string($name) ? $name : $uid;
        $subject = "$label \"$name\"";
        if ($kind === 'hyperLinkTypes' && is_string($description['field'] ?? null)) {
            $subject .= " in the field \"{$description['field']}\"";
        }
        $config = $this->config($kind, $path, $uid);

        if ($config === null) {
            return new Drift($kind, $uid, $name, Drift::MISSING, match ($kind) {
                'sections' => "The $subject doesn't exist in this environment. Root elements in it can't be imported.",
                'sites' => "The $subject doesn't exist in this environment. Content for that site will be skipped.",
                'entryTypes' => "The $subject doesn't exist in this environment. Content of that type will be skipped.",
                'neoBlockTypes' => "The $subject doesn't exist in this environment. Blocks of that type, and the blocks nested under them, will be skipped.",
                'hyperLinkTypes' => "The $subject doesn't exist in this environment. Links of that type will be skipped.",
                'volumes' => "The $subject doesn't exist in this environment. Its assets can only be fetched into another volume.",
                default => "The $subject doesn't exist in this environment. Its values will be skipped.",
            });
        }

        if ($kind === 'hyperLinkTypes' && ($description['type'] ?? null) !== ($config['type'] ?? null)) {
            return new Drift($kind, $uid, $name, Drift::TYPE_CHANGED, sprintf(
                'The %s is a %s link type in this environment but was a %s link type where it was exported. Its links will be skipped.',
                $subject,
                $this->typeName($config['type'] ?? null),
                $this->typeName($description['type'] ?? null),
            ));
        }

        if ($kind === 'fields' && ($description['type'] ?? null) !== ($config['type'] ?? null)) {
            return new Drift($kind, $uid, $name, Drift::TYPE_CHANGED, sprintf(
                'The %s is a %s field in this environment but was a %s field where it was exported. Its values will be skipped.',
                $subject,
                $this->typeName($config['type'] ?? null),
                $this->typeName($description['type'] ?? null),
            ));
        }

        if (($description['fingerprint'] ?? null) !== $this->fingerprint($config)) {
            return new Drift($kind, $uid, $name, Drift::DRIFTED, match ($kind) {
                'fields' => "The $subject has different settings in this environment than where it was exported. Its values will be imported, but check them.",
                'hyperLinkTypes' => "The $subject has different settings in this environment than where it was exported. Its links will be imported, but check them.",
                default => "The $subject has different settings in this environment than where it was exported. Its content will be imported, but check it.",
            });
        }

        return new Drift($kind, $uid, $name, Drift::MATCHED, null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(string $kind, ?string $path, string $uid): ?array
    {
        if ($path === null) {
            return $this->lookup($kind, $uid);
        }

        $config = Craft::$app->getProjectConfig()->get("$path.$uid");

        return is_array($config) ? $config : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookup(string $kind, string $uid): ?array
    {
        return match ($kind) {
            'hyperLinkTypes' => $this->hyperLinkTypeConfig($uid),
            default => null,
        };
    }

    /**
     * A Hyper link type has no project config path of its own. It's part of its field's settings, or, on Hyper 3, of
     * the shared link type config the field uses. Its key is `hyperLinkTypeKey()`. Its label stands in for a name.
     *
     * @return array<string, mixed>|null
     */
    private function hyperLinkTypeConfig(string $key): ?array
    {
        [$fieldUid, $handle] = array_pad(explode('/', $key, 2), 2, null);
        $field = Craft::$app->getProjectConfig()->get("fields.$fieldUid");
        if (!is_array($field) || ($field['type'] ?? null) !== HyperField::class || $handle === null) {
            return null;
        }

        $settings = is_array($field['settings'] ?? null) ? ProjectConfigHelper::unpackAssociativeArrays($field['settings']) : [];
        $linkTypes = $this->usesSharedLinkTypeConfig($settings)
            ? $this->sharedLinkTypeConfig(is_string($settings['linkTypeConfig'] ?? null) ? $settings['linkTypeConfig'] : '')['linkTypes'] ?? []
            : $settings['linkTypes'] ?? [];

        foreach (is_array($linkTypes) ? ProjectConfigHelper::unpackAssociativeArrays($linkTypes) : [] as $linkType) {
            if (is_array($linkType) && ($linkType['handle'] ?? null) === $handle) {
                return $linkType + ['name' => $linkType['label'] ?? null];
            }
        }

        return null;
    }

    /**
     * Whether a Hyper field's settings use a shared link type config (Hyper 3) rather than link types of their own. A
     * field saved before Hyper 3 has no `linkTypeConfig`, and uses its own link types if it has any, as Hyper 3 reads it.
     *
     * @param array<mixed> $settings
     */
    private function usesSharedLinkTypeConfig(array $settings): bool
    {
        if (array_key_exists('linkTypeConfig', $settings)) {
            return $settings['linkTypeConfig'] !== 'custom';
        }

        return empty($settings['linkTypes']);
    }

    /**
     * Hyper 3's shared link type config that a field names by UID, or by handle. An empty reference, or `default`, is
     * the Default config.
     *
     * @return array<mixed>|null
     */
    private function sharedLinkTypeConfig(string $ref): ?array
    {
        $configs = Craft::$app->getProjectConfig()->get(self::HYPER_LINK_TYPE_CONFIGS);
        $configs = is_array($configs) ? $configs : [];
        if (StringHelper::isUUID($ref)) {
            return is_array($configs[$ref] ?? null) ? $configs[$ref] : null;
        }

        $handle = $ref !== '' ? $ref : 'default';
        foreach ($configs as $config) {
            if (is_array($config) && ($config['handle'] ?? null) === $handle) {
                return $config;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function fingerprint(array $config): string
    {
        return hash('sha256', json_encode($this->normalise($config), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach (self::VOLATILE_KEYS as $key) {
            unset($value[$key]);
        }
        ksort($value);

        return array_map(fn($item) => $this->normalise($item), $value);
    }

    /**
     * A field type's display name, without loading a class named by the Payload.
     */
    private function typeName(mixed $class): string
    {
        if (!is_string($class)) {
            return 'unknown';
        }

        foreach (Craft::$app->getFields()->getAllFieldTypes() as $type) {
            if ($type === $class && is_subclass_of($type, FieldInterface::class)) {
                return $type::displayName();
            }
        }

        $parts = explode('\\', $class);

        return end($parts);
    }
}
