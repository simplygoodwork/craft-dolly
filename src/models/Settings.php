<?php

namespace simplygoodwork\craftportage\models;

use craft\base\Model;
use craft\helpers\App;
use yii\base\InvalidConfigException;

/**
 * Portage's plugin settings.
 */
class Settings extends Model
{
    /**
     * @var int how long, in seconds, an Import waits for an asset's file to download from its source URL. The file's
     * size is limited by Craft's `maxUploadFileSize`.
     */
    public int $fetchTimeout = 30;

    /**
     * @var int the largest Payload, in bytes, an Import accepts: both its text and its decompressed content. Larger
     * Payloads are refused before they're decompressed further, so a Payload can't exhaust memory.
     */
    public int $maxPayloadSize = 8 * 1024 * 1024;

    /**
     * @var string|null the Shared key, as an env var reference such as `$PORTAGE_SHARED_KEY`, so the secret itself is
     * never written to project config. When set, every Export is encrypted and every Import refuses unencrypted
     * Payloads.
     */
    public ?string $sharedKey = null;

    /**
     * Whether a Shared key is configured, even if its env var isn't set in this environment.
     */
    public function hasSharedKey(): bool
    {
        return $this->sharedKey !== null && $this->sharedKey !== '';
    }

    /**
     * The Shared key's secret, with its env var resolved, or null if there is no Shared key.
     *
     * @throws InvalidConfigException if the Shared key's env var isn't set in this environment, so an Export is never
     * left unencrypted, nor an Import accepts unencrypted Payloads, by accident
     */
    public function resolveSharedKey(): ?string
    {
        if (!$this->hasSharedKey()) {
            return null;
        }

        $key = App::parseEnv($this->sharedKey);
        if (!is_string($key) || $key === '') {
            $name = trim((string)$this->sharedKey, '${}');
            throw new InvalidConfigException("The Shared key setting refers to the env var \"$name\", which isn't set in this environment. Set it to the same Shared key as every other environment.");
        }

        return $key;
    }

    protected function defineRules(): array
    {
        return [
            [['fetchTimeout', 'maxPayloadSize'], 'integer', 'min' => 1],
            [
                ['sharedKey'],
                'match',
                'pattern' => '/^\$(\w+|\{\w+\})$/',
                'message' => 'Set the Shared key to an env var, such as $PORTAGE_SHARED_KEY, so the key itself is never saved to project config.',
            ],
        ];
    }
}
