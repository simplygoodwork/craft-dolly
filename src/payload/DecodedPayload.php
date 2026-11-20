<?php

namespace simplygoodwork\craftdolly\payload;

/**
 * A Payload's envelope plus its identity (a hash of the decoded JSON).
 */
final class DecodedPayload
{
    /**
     * @param array<string, mixed> $envelope
     */
    public function __construct(
        public readonly array $envelope,
        public readonly string $identity,
    ) {
    }
}
