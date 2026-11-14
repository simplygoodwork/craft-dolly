<?php

namespace simplygoodwork\craftportage\import;

/**
 * Where the file of an unresolved asset comes from: the source URL its Reference carries (a Fetch), or a file the user
 * uploaded (an Upload).
 */
final class AssetSource
{
    private function __construct(
        public readonly ?string $url,
        public readonly ?string $uploadPath,
    ) {
    }

    public static function fetchFrom(string $url): self
    {
        return new self($url, null);
    }

    public static function uploaded(string $path): self
    {
        return new self(null, $path);
    }

    public function isUpload(): bool
    {
        return $this->uploadPath !== null;
    }

    /**
     * Names the source in a message: its URL, or "the uploaded file".
     */
    public function describe(): string
    {
        return $this->isUpload() ? 'the uploaded file' : (string)$this->url;
    }
}
