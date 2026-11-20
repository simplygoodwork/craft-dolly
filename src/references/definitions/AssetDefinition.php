<?php

namespace simplygoodwork\craftdolly\references\definitions;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\models\Volume;
use simplygoodwork\craftdolly\references\ElementTypeDefinition;
use simplygoodwork\craftdolly\references\NaturalKey;

/**
 * Assets. Natural key: volume UID + folder path + filename. Limited to their volume. Never Stubbed: an unresolved
 * asset is fetched or uploaded instead.
 */
final class AssetDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return Asset::class;
    }

    /**
     * @return array{volume: string, folder: string, filename: string}|null
     */
    public function naturalKey(ElementInterface $element): ?array
    {
        if (!$element instanceof Asset) {
            return null;
        }

        return [
            'volume' => $element->getVolume()->uid,
            // The root folder's path is empty; every other folder's ends in a slash, like "blog/2026/".
            'folder' => (string)$element->getFolder()->path,
            'filename' => $element->getFilename(),
        ];
    }

    public function find(array $naturalKey): ?int
    {
        $volume = self::volume($naturalKey);
        $folder = $naturalKey['folder'] ?? null;
        $filename = $naturalKey['filename'] ?? null;
        if (!$volume || !is_string($folder) || !is_string($filename) || $filename === '') {
            return null;
        }

        $folder = NaturalKey::folder($volume, $folder);
        if (!$folder) {
            return null;
        }

        $ids = Asset::find()
            ->volumeId($volume->id)
            ->folderId($folder->id)
            ->filename(Db::escapeParam($filename))
            ->status(null)
            ->site('*')
            ->unique()
            ->limit(2)
            ->ids();

        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    public function sourceFor(array $naturalKey): ?string
    {
        $volume = self::volume($naturalKey);

        return $volume ? "volume:$volume->uid" : null;
    }

    public function isInSource(ElementInterface $element, string $source): bool
    {
        return $element instanceof Asset && $source === 'volume:' . $element->getVolume()->uid;
    }

    public function sourceName(): string
    {
        return 'volume';
    }

    /**
     * @param array<mixed> $naturalKey
     */
    private static function volume(array $naturalKey): ?Volume
    {
        $uid = $naturalKey['volume'] ?? null;

        return is_string($uid) ? Craft::$app->getVolumes()->getVolumeByUid($uid) : null;
    }
}
