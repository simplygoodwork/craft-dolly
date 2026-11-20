<?php

namespace simplygoodwork\craftdolly\import;

use Craft;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\UploadedFile;

/**
 * Keeps the files a user uploads for unresolved assets between requests, since a file input doesn't survive the Preview
 * being rendered again. Each user has their own directory in the runtime temp path, which the web can't reach, and a
 * file is referred to by a token that is its name in that directory and nothing else.
 */
final class StagedUploads
{
    private const TOKEN_LENGTH = 16;
    private const SEPARATOR = '--';
    private const TTL = 86400;

    public function __construct(private readonly int $userId)
    {
    }

    /**
     * Keeps an uploaded file.
     *
     * @return array{token: string, path: string, filename: string}|null null if the file can't be kept
     */
    public function stage(UploadedFile $file): ?array
    {
        if ($file->getHasError() || $file->tempName === '' || !is_file($file->tempName)) {
            return null;
        }

        $directory = $this->directory();
        $this->purge($directory);
        $token = StringHelper::randomString(self::TOKEN_LENGTH) . self::SEPARATOR . AssetsHelper::prepareAssetName(basename($file->name));

        return $this->isToken($token) && @copy($file->tempName, "$directory/$token") ? $this->find($token) : null;
    }

    /**
     * Finds a kept file by its token.
     *
     * @return array{token: string, path: string, filename: string}|null null if the token isn't one, or its file is gone
     */
    public function find(?string $token): ?array
    {
        if ($token === null || !$this->isToken($token)) {
            return null;
        }

        $path = $this->directory() . "/$token";

        return is_file($path)
            ? ['token' => $token, 'path' => $path, 'filename' => substr($token, self::TOKEN_LENGTH + strlen(self::SEPARATOR))]
            : null;
    }

    /**
     * Deletes kept files. A path that isn't a file in this user's directory is left alone, wherever it points.
     *
     * @param list<string> $paths
     */
    public function discard(array $paths): void
    {
        $directory = realpath($this->directory());
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($directory !== false && $real !== false && dirname($real) === $directory) {
                FileHelper::unlink($real);
            }
        }
    }

    private function isToken(string $token): bool
    {
        return (bool)preg_match('~^[A-Za-z0-9]{' . self::TOKEN_LENGTH . '}' . preg_quote(self::SEPARATOR, '~') . '[A-Za-z0-9._ -]+$~', $token);
    }

    private function directory(): string
    {
        $directory = Craft::$app->getPath()->getTempPath() . "/dolly-uploads/$this->userId";
        FileHelper::createDirectory($directory);

        return $directory;
    }

    private function purge(string $directory): void
    {
        foreach (glob("$directory/*") ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - self::TTL) {
                FileHelper::unlink($path);
            }
        }
    }
}
