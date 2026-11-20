<?php

namespace simplygoodwork\craftdolly\events;

use craft\base\FieldInterface;
use yii\base\Event;

/**
 * Triggered by `FieldAdapterRegistry` when it's built, so a plugin can register a Field adapter for its own field class.
 */
final class RegisterFieldAdaptersEvent extends Event
{
    /**
     * @var array<class-string<FieldInterface>, mixed> the Field adapters (each a `FieldAdapter`, which the registry
     * checks) by the exact class of the field they handle. An adapter registered for a class replaces Dolly's own, and is used instead of the fallback.
     */
    public array $adapters = [];
}
