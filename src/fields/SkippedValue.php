<?php

namespace simplygoodwork\craftdolly\fields;

/**
 * What a Field adapter's `import()` returns to skip a value: the field is left out of the values set on the element,
 * so a new element has no value, and an existing one (the draft of an overwritten entry) keeps the value it has.
 */
enum SkippedValue
{
    case Skipped;
}
