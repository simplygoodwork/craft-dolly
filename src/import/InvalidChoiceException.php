<?php

namespace simplygoodwork\craftportage\import;

use Exception;

/**
 * A choice for an Unresolved reference or a Root element that the Import can't carry out. The Preview and the Import
 * are refused.
 */
final class InvalidChoiceException extends Exception
{
    /**
     * @param string|null $uid the Reference or Root element UID the choice is for, when known
     */
    public function __construct(string $message, public ?string $uid = null)
    {
        parent::__construct($message);
    }
}
