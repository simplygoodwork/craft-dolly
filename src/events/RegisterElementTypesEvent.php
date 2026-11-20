<?php

namespace simplygoodwork\craftdolly\events;

use yii\base\Event;

/**
 * Triggered by `ElementTypeRegistry` when it's built, so a plugin can register an element type whose elements a
 * Payload may carry as References.
 */
final class RegisterElementTypesEvent extends Event
{
    /**
     * @var array<string, mixed> the element type definitions (each an `ElementTypeDefinition`, which the registry
     * checks) by short name, the name a Payload carries. The core types are already here: entry, category, tag, user
     * and asset. A definition registered under one of their names replaces Dolly's own.
     */
    public array $types = [];
}
