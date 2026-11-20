<?php

namespace simplygoodwork\craftdolly\jobs;

use Craft;
use craft\elements\User;
use craft\queue\BaseJob;
use simplygoodwork\craftdolly\import\StagedUploads;
use simplygoodwork\craftdolly\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Runs a large Import from the control panel. It carries the Payload text and the choices of the Preview exactly as they
 * were when the user imported, with the fingerprint of the plan they were previewed as, and runs them through the core
 * service as the user who queued it. The files the user uploaded for unresolved assets stay where they were staged
 * until the job is done with them.
 */
class ImportJob extends BaseJob implements RetryableJobInterface
{
    /**
     * @var int the seconds a job may run for. An Import in progress that has been quiet for longer has stalled.
     */
    public const TTR = 3600;

    public int $logId = 0;

    public int $userId = 0;

    public string $payload = '';

    /**
     * @var array<string, string|int|array<string, string>>
     */
    public array $choices = [];

    /**
     * @var array<string, array{mode?: string, section?: string}>
     */
    public array $rootChoices = [];

    public string $fingerprint = '';

    public function execute($queue): void
    {
        $log = Plugin::getInstance()->getImportLog();
        $session = Craft::$app->getUser();
        $previous = $session->getIdentity();

        try {
            $user = User::find()->id($this->userId)->status(User::STATUS_ACTIVE)->one();
            $refusal = $user?->can(Plugin::PERMISSION_IMPORT) === true ? null : (
                $user === null
                    ? 'The user who queued the Import is no longer an active user. Nothing was imported.'
                    : 'The user who queued the Import can no longer import content. Nothing was imported.'
            );
            if ($user === null || $refusal !== null) {
                if ($log->start($this->logId)) {
                    $log->fail($this->logId, (string)$refusal);
                }
                return;
            }

            $session->setIdentity($user);
            $this->setProgress($queue, 0, Craft::t('dolly', 'Importing'));
            Plugin::getInstance()->getDolly()->runQueuedImport(
                $this->logId,
                $this->payload,
                $this->choices,
                $this->rootChoices,
                $this->fingerprint,
                fn(int $done, int $total) => $this->setProgress($queue, $total ? $done / $total : 1, Craft::t('dolly', '{done} of {total} Root elements', ['done' => $done, 'total' => $total])),
            );
        } finally {
            $session->setIdentity($previous);
            (new StagedUploads($this->userId))->discard($this->stagedPaths());
        }
    }

    public function getTtr(): int
    {
        return self::TTR;
    }

    public function canRetry($attempt, $error): bool
    {
        // An Import that was started isn't started again: its Root elements may already be imported.
        return false;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('dolly', 'Importing a Payload (Import #{id})', ['id' => $this->logId]);
    }

    /**
     * @return list<string> the paths of the uploaded files the choices create assets from
     */
    private function stagedPaths(): array
    {
        $paths = [];
        foreach ($this->choices as $choice) {
            if (is_array($choice) && ($choice['choice'] ?? null) === 'upload' && is_string($choice['path'] ?? null)) {
                $paths[] = $choice['path'];
            }
        }

        return $paths;
    }
}
