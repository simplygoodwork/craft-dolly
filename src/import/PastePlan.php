<?php

namespace simplygoodwork\craftdolly\import;

/**
 * What an Import will do for a pasted block Root element: add its blocks to the field of the target draft.
 */
final class PastePlan
{
    public function __construct(
        public readonly BlockTarget $target,
        public readonly PasteSite $site,
    ) {
    }
}
