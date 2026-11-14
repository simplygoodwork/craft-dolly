<?php

namespace simplygoodwork\craftportage\models;

use DateTime;

/**
 * One Import, as recorded in the Import log.
 */
final class ImportLogEntry
{
    /**
     * A queued Import the job hasn't started.
     */
    public const STATUS_QUEUED = 'queued';

    /**
     * A queued Import whose job is importing Root elements.
     */
    public const STATUS_IN_PROGRESS = 'in-progress';

    /**
     * An Import that ran: every Root element has a result, some of which may be failed.
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * An Import whose job couldn't run at all, so nothing more was imported.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $source the Payload's Source environment metadata
     * @param list<array<string, mixed>> $results the result for each Root element
     * @param list<array<string, mixed>> $choices the choice made for each Unresolved reference
     * @param string|null $error why a queued Import couldn't run, when its status is failed
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly DateTime $dateCreated,
        public readonly string $payloadIdentity,
        public readonly array $source,
        public readonly array $results,
        public readonly array $choices = [],
        public readonly string $status = self::STATUS_COMPLETED,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Whether the Import is still to be done by the queue.
     */
    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_QUEUED || $this->status === self::STATUS_IN_PROGRESS;
    }
}
