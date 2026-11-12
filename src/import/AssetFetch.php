<?php

namespace simplygoodwork\craftportage\import;

use craft\elements\Asset;
use craft\models\Volume;

/**
 * How an Import will fetch an unresolved asset: from its source URL into a volume and folder, as an unsaved asset
 * that already holds the metadata the Reference carries (title, alt text, focal point and custom field values).
 *
 * The Importer downloads the file and saves the asset inside the transaction of the first Root element that relates
 * to it. A failed fetch is remembered, so it's reported for each Root element without being retried.
 */
final class AssetFetch
{
    /**
     * @var RootPlan the References the asset's own field values relate to (Stubs and other fetched assets, saved before
     * the asset) and what they skip, merged into each Root element that relates to the asset
     */
    public RootPlan $fieldPlan;

    /**
     * @var string|null why the fetch failed, once it has
     */
    public ?string $failure = null;

    /**
     * @param string $folderPath the folder the file goes into, created if it doesn't exist: "" for the volume's root
     * folder, "blog/2026/" for a subfolder
     */
    public function __construct(
        public readonly string $url,
        public readonly Volume $volume,
        public readonly string $folderPath,
        public readonly Asset $asset,
    ) {
        $this->fieldPlan = new RootPlan(null);
    }

    /**
     * Names the folder and volume in a message, like `the folder "blog/2026/" of the "Photos" volume`.
     */
    public function location(): string
    {
        return $this->folderPath === ''
            ? "the \"{$this->volume->name}\" volume"
            : "the folder \"$this->folderPath\" of the \"{$this->volume->name}\" volume";
    }
}
