<?php

namespace simplygoodwork\craftportage\payload;

use JsonException;

/**
 * Converts between Payload text (`portage:v1:<base64(gzip(JSON))>`) and the envelope array, refusing Payloads
 * from a different Craft major.
 */
final class PayloadCodec
{
    public const PREFIX = 'portage:v1:';

    /**
     * @param string $craftVersion the Target environment's Craft version; Payloads from another major are refused (ADR-0001)
     */
    public function __construct(private readonly string $craftVersion)
    {
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function encode(array $envelope): string
    {
        $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::PREFIX . base64_encode(gzencode($json, 9));
    }

    /**
     * @throws InvalidPayloadException
     */
    public function decode(string $text): DecodedPayload
    {
        $text = trim($text);

        if (!str_starts_with($text, self::PREFIX)) {
            throw new InvalidPayloadException('This is not a Portage Payload: it should start with "' . self::PREFIX . '".');
        }

        $gzipped = base64_decode(substr($text, strlen(self::PREFIX)), true);
        if ($gzipped === false || $gzipped === '') {
            throw new InvalidPayloadException('The Payload is damaged: its body is not valid base64. Make sure it was copied in full.');
        }

        $json = @gzdecode($gzipped);
        if ($json === false) {
            throw new InvalidPayloadException('The Payload is damaged: its body could not be decompressed. Make sure it was copied in full.');
        }

        try {
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidPayloadException('The Payload is damaged: its content is not valid JSON.');
        }

        if (!is_array($envelope) || !isset($envelope['roots']) || !is_array($envelope['roots'])) {
            throw new InvalidPayloadException('The Payload is damaged: it has no Root elements.');
        }

        $this->checkCraftMajor($envelope['craftVersion'] ?? null);

        return new DecodedPayload($envelope, hash('sha256', $json));
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
