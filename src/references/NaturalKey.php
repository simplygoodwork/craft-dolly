<?php

namespace simplygoodwork\craftdolly\references;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\models\Volume;
use craft\models\VolumeFolder;

/**
 * The Natural key of an element: built on Export, and matched in the Target environment on Import when a Reference's
 * UID doesn't match. Each registered element type defines its own (see `ElementTypeDefinition`); the core types' are:
 *
 * - entry: section UID + slug, plus the parent slug path in Structure sections
 * - category: group UID + slug, plus the parent slug path
 * - tag: group UID + title
 * - user: email, then username
 * - asset: volume UID + folder path + filename
 *
 * A Natural key that matches more than one element matches none, so a Reference is never related to the wrong one.
 * The helpers here are shared by the definitions.
 */
final class NaturalKey
{
    /**
     * Returns the Natural key of an element of a registered type, or null if its type isn't registered or it has none.
     *
     * @return array<string, mixed>|null
     */
    public static function of(ElementInterface $element): ?array
    {
        return ElementTypes::definitionOf($element)?->naturalKey($element);
    }

    /**
     * Returns a volume's folder by its path ("" for the root folder, "blog/2026/" for a subfolder), or null if there's
     * no such folder.
     */
    public static function folder(Volume $volume, string $path): ?VolumeFolder
    {
        $assets = Craft::$app->getAssets();
        $path = trim($path, '/');

        return $path === ''
            ? $assets->getRootFolderByVolumeId($volume->id)
            : $assets->findFolder(['volumeId' => $volume->id, 'path' => "$path/"]);
    }

    /**
     * The slugs of an element's ancestors, from the top of the structure down.
     *
     * @return list<string>
     */
    public static function path(ElementInterface $element): array
    {
        $ancestors = $element->getAncestors();
        $ancestors = $ancestors instanceof ElementQueryInterface ? $ancestors->status(null)->all() : $ancestors->all();

        return array_values(array_map(fn(ElementInterface $ancestor) => (string)$ancestor->slug, $ancestors));
    }

    /**
     * Returns the ID of the only candidate whose ancestors' slugs match the path (any candidate when the elements
     * aren't in a structure), or null if none or several do.
     *
     * @param ElementInterface[] $candidates
     * @param mixed $path list of ancestor slugs, or null when the elements aren't in a structure
     */
    public static function onlyOnPath(array $candidates, mixed $path): ?int
    {
        $matches = array_filter($candidates, fn(ElementInterface $candidate) => $path === null || self::path($candidate) === $path);

        return count($matches) === 1 ? reset($matches)->id : null;
    }
}
