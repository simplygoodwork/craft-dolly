<?php

namespace simplygoodwork\craftportage\schema;

use Craft;
use craft\base\FieldInterface;

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
     * Schema reference kinds => [project config path, label for messages]
     */
    private const KINDS = [
        'sites' => ['sites', 'site'],
        'sections' => ['sections', 'section'],
        'entryTypes' => ['entryTypes', 'entry type'],
        'fields' => ['fields', 'field'],
        'volumes' => ['volumes', 'volume'],
    ];

    /**
     * Keys that can differ between environments with the same schema.
     */
    private const VOLATILE_KEYS = ['dateAdded', 'dateModified'];

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
                $config = $this->config($path, $uid) ?? [];
                $description = [
                    'handle' => is_string($config['handle'] ?? null) ? $config['handle'] : null,
                    'name' => is_string($config['name'] ?? null) ? $config['name'] : null,
                ];
                if ($kind === 'fields') {
                    $description['type'] = is_string($config['type'] ?? null) ? $config['type'] : null;
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
    private function drift(string $kind, string $path, string $label, string $uid, array $description): Drift
    {
        $name = $description['name'] ?? $description['handle'] ?? null;
        $name = is_string($name) ? $name : $uid;
        $subject = "$label \"$name\"";
        $config = $this->config($path, $uid);

        if ($config === null) {
            return new Drift($kind, $uid, $name, Drift::MISSING, match ($kind) {
                'sections' => "The $subject doesn't exist in this environment. Root elements in it can't be imported.",
                'sites' => "The $subject doesn't exist in this environment. Content for that site will be skipped.",
                'entryTypes' => "The $subject doesn't exist in this environment. Content of that type will be skipped.",
                'volumes' => "The $subject doesn't exist in this environment. Its assets can only be fetched into another volume.",
                default => "The $subject doesn't exist in this environment. Its values will be skipped.",
            });
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
                default => "The $subject has different settings in this environment than where it was exported. Its content will be imported, but check it.",
            });
        }

        return new Drift($kind, $uid, $name, Drift::MATCHED, null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(string $path, string $uid): ?array
    {
        $config = Craft::$app->getProjectConfig()->get("$path.$uid");

        return is_array($config) ? $config : null;
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
