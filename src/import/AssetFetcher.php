<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use simplygoodwork\craftportage\Plugin;

/**
 * Downloads an unresolved asset's file from its source URL into a temp file, for the Importer to save as an asset.
 *
 * Only http and https URLs are fetched. The download is limited to the plugin's `fetchTimeout` and to Craft's
 * `maxUploadFileSize`, the same limit as a file uploaded through the control panel.
 */
final class AssetFetcher
{
    /**
     * Returns the path of a temp file holding the downloaded file.
     *
     * @throws AssetFetchFailedException with the reason, in words for the Import's result
     */
    public function download(string $url, string $filename): string
    {
        if (!self::isFetchableUrl($url)) {
            throw new AssetFetchFailedException('only http and https URLs can be fetched');
        }

        $timeout = Plugin::getInstance()->getSettings()->fetchTimeout;
        $maxBytes = (int)Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;
        $path = Craft::$app->getPath()->getTempPath() . '/portage-' . StringHelper::randomString(12) . '-' . $filename;
        $tooLarge = fn() => new AssetFetchFailedException(sprintf('the file is larger than the %s limit', $this->size($maxBytes)));

        try {
            Craft::createGuzzleClient()->get($url, [
                RequestOptions::SINK => $path,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::CONNECT_TIMEOUT => min(10, $timeout),
                RequestOptions::ALLOW_REDIRECTS => ['max' => 5, 'protocols' => ['http', 'https']],
                RequestOptions::ON_HEADERS => function(ResponseInterface $response) use ($maxBytes, $tooLarge) {
                    if ((int)$response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw $tooLarge();
                    }
                },
                RequestOptions::PROGRESS => function(int $total, int $downloaded) use ($maxBytes, $tooLarge) {
                    if ($downloaded > $maxBytes) {
                        throw $tooLarge();
                    }
                },
            ]);
        } catch (GuzzleException $e) {
            FileHelper::unlink($path);
            throw new AssetFetchFailedException($this->reason($e, $timeout), previous: $e);
        } catch (AssetFetchFailedException $e) {
            FileHelper::unlink($path);
            throw $e;
        }

        return $path;
    }

    /**
     * Returns whether an asset's source URL is one Portage will fetch: an absolute http or https URL.
     */
    public static function isFetchableUrl(mixed $url): bool
    {
        return is_string($url)
            && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url($url, PHP_URL_HOST) !== null;
    }

    private function reason(GuzzleException $e, int $timeout): string
    {
        // Guzzle wraps an exception thrown by the ON_HEADERS or PROGRESS callbacks.
        for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof AssetFetchFailedException) {
                return $previous->getMessage();
            }
        }

        $response = $e instanceof RequestException ? $e->getResponse() : null;
        if ($response !== null) {
            $status = sprintf('HTTP %d %s', $response->getStatusCode(), $response->getReasonPhrase());

            return match ($response->getStatusCode()) {
                401, 403 => "the source refused access ($status). It may be behind basic auth, or the volume may be private",
                404 => "the file wasn't found at the source ($status)",
                default => "the source responded with $status",
            };
        }

        if ($e instanceof ConnectException && str_contains(strtolower($e->getMessage()), 'timed out')) {
            return "the source didn't respond within $timeout seconds";
        }
        if (str_contains(strtolower($e->getMessage()), 'timed out')) {
            return "the download didn't finish within $timeout seconds";
        }

        return 'the source couldn\'t be reached: ' . $e->getMessage();
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / (1024 * 1024), 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : "$bytes bytes");
    }
}
