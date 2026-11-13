<?php

namespace simplygoodwork\craftportage\payload;

use Craft;
use craft\helpers\Json;
use JsonException;
use simplygoodwork\craftportage\export\Exporter;
use simplygoodwork\craftportage\references\ElementTypes;

/**
 * Converts between Payload text and the envelope array: `portage:v1:<base64(gzip(JSON))>`, or with a Shared key,
 * `portage:v1e:<base64(encryptByKey(gzip(JSON)))>`. Decoding is strict: it refuses Payloads that don't match the
 * environment's Shared key, are too large (gzip bombs included), have a malformed envelope, name an element type
 * outside the whitelist, or come from a different Craft major.
 */
final class PayloadCodec
{
    public const PREFIX = 'portage:v1:';

    public const ENCRYPTED_PREFIX = 'portage:v1e:';

    /**
     * @param string $craftVersion the Target environment's Craft version; Payloads from another major are refused (ADR-0001)
     * @param string|null $sharedKey the Shared key's secret: when set, Payloads are encrypted and authenticated with it,
     * and unencrypted Payloads are refused
     * @param int $maxSize the largest Payload, in bytes, that's decoded: both its text and its decompressed JSON
     */
    public function __construct(
        private readonly string $craftVersion,
        #[\SensitiveParameter] private readonly ?string $sharedKey,
        private readonly int $maxSize,
    ) {
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function encode(array $envelope): string
    {
        $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $gzipped = gzencode($json, 9);

        if ($this->sharedKey !== null) {
            return self::ENCRYPTED_PREFIX . base64_encode(Craft::$app->getSecurity()->encryptByKey($gzipped, $this->sharedKey));
        }

        return self::PREFIX . base64_encode($gzipped);
    }

    /**
     * @throws InvalidPayloadException
     */
    public function decode(string $text): DecodedPayload
    {
        $text = trim($text);
        if (strlen($text) > $this->maxSize) {
            throw $this->tooLarge();
        }

        if (str_starts_with($text, self::ENCRYPTED_PREFIX)) {
            if ($this->sharedKey === null) {
                throw new InvalidPayloadException("This Payload is encrypted, but this environment has no Shared key configured, so it can't be decrypted. Configure the same Shared key as the Source environment to import it.");
            }
            $gzipped = Craft::$app->getSecurity()->decryptByKey($this->body($text, self::ENCRYPTED_PREFIX), $this->sharedKey);
            if ($gzipped === false) {
                throw new InvalidPayloadException("This Payload can't be decrypted: it was encrypted with a different Shared key, or it was changed after Export. Make sure this environment has the same Shared key as the Source environment, and that the Payload was copied in full.");
            }
        } elseif (str_starts_with($text, self::PREFIX)) {
            if ($this->sharedKey !== null) {
                throw new InvalidPayloadException("This Payload isn't encrypted, but this environment has a Shared key configured, so it only accepts Payloads encrypted with that key. Export it again from an environment with the same Shared key.");
            }
            $gzipped = $this->body($text, self::PREFIX);
        } else {
            throw new InvalidPayloadException('This is not a Portage Payload: it should start with "' . self::PREFIX . '".');
        }

        $json = $this->decompress($gzipped);

        try {
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidPayloadException('The Payload is damaged: its content is not valid JSON.');
        }

        if (!is_array($envelope) || !isset($envelope['roots']) || !is_array($envelope['roots'])) {
            throw new InvalidPayloadException('The Payload is damaged: it has no Root elements.');
        }

        $this->checkFormatVersion($envelope['formatVersion'] ?? null);
        $this->checkCraftMajor($envelope['craftVersion'] ?? null);
        $this->checkShape($envelope);
        $this->checkElementTypes($envelope);

        return new DecodedPayload($envelope, hash('sha256', $json));
    }

    /**
     * @throws InvalidPayloadException
     */
    private function checkFormatVersion(mixed $formatVersion): void
    {
        if (!is_int($formatVersion)) {
            throw new InvalidPayloadException('The Payload is damaged: it doesn\'t say which format version it uses.');
        }
        if ($formatVersion !== Exporter::FORMAT_VERSION) {
            throw new InvalidPayloadException("This Payload uses format version $formatVersion, which this version of Portage can't read. Update Portage in this environment to import it.");
        }
    }

    /**
     * Refuses an envelope whose Root elements aren't a list of objects, or whose other sections aren't objects, so
     * the rest of the Import can rely on their shape.
     *
     * @param array<mixed> $envelope
     * @throws InvalidPayloadException
     */
    private function checkShape(array $envelope): void
    {
        $roots = $envelope['roots'];
        if (!array_is_list($roots) || array_filter($roots, fn(mixed $root) => !is_array($root))) {
            throw new InvalidPayloadException('The Payload is damaged: its Root elements are malformed.');
        }

        foreach (['source', 'schema'] as $key) {
            if (isset($envelope[$key]) && !is_array($envelope[$key])) {
                throw new InvalidPayloadException("The Payload is damaged: its \"$key\" is malformed.");
            }
        }

        $references = $envelope['references'] ?? [];
        if (!is_array($references) || array_filter($references, fn(mixed $reference) => !is_array($reference))) {
            throw new InvalidPayloadException('The Payload is damaged: its "references" are malformed.');
        }
    }

    /**
     * Refuses an envelope whose Root elements or References name an element type outside the whitelist. Runs after
     * `checkShape()`, so every Root element and Reference is an array.
     *
     * @param array<mixed> $envelope
     * @throws InvalidPayloadException
     */
    private function checkElementTypes(array $envelope): void
    {
        foreach ([...$envelope['roots'], ...array_values($envelope['references'] ?? [])] as $element) {
            $elementType = $element['elementType'] ?? null;
            if (ElementTypes::classFor($elementType) === null) {
                $name = is_string($elementType) ? mb_strimwidth($elementType, 0, 80, '…') : Json::encode($elementType);
                throw new InvalidPayloadException("The Payload names an element type Portage doesn't support: \"$name\". It can't be imported.");
            }
        }
    }

    /**
     * Gunzips a Payload's body a little at a time, giving up as soon as it grows past the size limit, so a small
     * Payload that decompresses to gigabytes (a gzip bomb) never exhausts memory.
     *
     * @throws InvalidPayloadException
     */
    private function decompress(string $gzipped): string
    {
        $damaged = new InvalidPayloadException('The Payload is damaged: its body could not be decompressed. Make sure it was copied in full.');
        $inflate = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            throw $damaged;
        }

        $json = '';
        // Deflate expands 1 KB of input to at most about 1 MB of output.
        foreach (str_split($gzipped, 1024) as $chunk) {
            $output = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
            if ($output === false) {
                throw $damaged;
            }
            $json .= $output;
            if (strlen($json) > $this->maxSize) {
                throw $this->tooLarge();
            }
            if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                break;
            }
        }

        // A truncated stream, or data after its end, is refused too.
        if (inflate_get_status($inflate) !== ZLIB_STREAM_END || inflate_get_read_len($inflate) !== strlen($gzipped)) {
            throw $damaged;
        }

        return $json;
    }

    private function tooLarge(): InvalidPayloadException
    {
        return new InvalidPayloadException("This Payload is too large: this environment accepts Payloads of up to $this->maxSize bytes. Raise the \"maxPayloadSize\" setting in config/portage.php to import it.");
    }

    /**
     * @throws InvalidPayloadException
     */
    private function body(string $text, string $prefix): string
    {
        $body = base64_decode(substr($text, strlen($prefix)), true);
        if ($body === false || $body === '') {
            throw new InvalidPayloadException('The Payload is damaged: its body is not valid base64. Make sure it was copied in full.');
        }

        return $body;
    }

    /**
     * @throws InvalidPayloadException
     */
    private function checkCraftMajor(mixed $sourceVersion): void
    {
        if (!is_string($sourceVersion) || !preg_match('/^(\d+)\./', $sourceVersion, $match)) {
            throw new InvalidPayloadException('The Payload is damaged: it doesn\'t say which Craft version it was exported from.');
        }

        $sourceMajor = (int)$match[1];
        $targetMajor = (int)explode('.', $this->craftVersion)[0];
        if ($sourceMajor !== $targetMajor) {
            throw new InvalidPayloadException("This Payload was exported from Craft $sourceMajor, but this environment runs Craft $targetMajor. Payloads can't move between Craft major versions.");
        }
    }
}
