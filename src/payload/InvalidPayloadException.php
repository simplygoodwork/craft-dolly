<?php

namespace simplygoodwork\craftportage\payload;

use RuntimeException;

/**
 * Thrown when Payload text can't be decoded. The message is safe to show to the user.
 */
final class InvalidPayloadException extends RuntimeException
{
}
