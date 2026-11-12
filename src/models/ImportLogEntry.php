<?php

namespace simplygoodwork\craftportage\models;

use DateTime;

/**
 * One Import, as recorded in the Import log.
 */
final class ImportLogEntry
{
    /**
     * @param array<string, mixed> $source the Payload's Source environment metadata
     * @param list<array<string, mixed>> $results the result for each Root element
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly DateTime $dateCreated,
        public readonly string $payloadIdentity,
        public readonly array $source,
        public readonly array $results,
    ) {
    }
}
