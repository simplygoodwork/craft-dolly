<?php

namespace simplygoodwork\craftportage\import;

use Exception;

/**
 * A choice for an Unresolved reference that the Import can't carry out. The Preview and the Import are refused.
 */
final class InvalidChoiceException extends Exception
{
}
