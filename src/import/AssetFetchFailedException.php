<?php

namespace simplygoodwork\craftportage\import;

use RuntimeException;

/**
 * An asset's file couldn't be fetched from its source URL. The message is the reason, in words for the Import's
 * result, like "the file wasn't found at the source (HTTP 404 Not Found)".
 */
final class AssetFetchFailedException extends RuntimeException
{
}
