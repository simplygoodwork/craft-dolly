<?php

namespace simplygoodwork\craftportage\fields;

/**
 * Marks a Field adapter whose field holds Owned content (Matrix, Neo, CKEditor nested entries), which needs an owner
 * element to be saved with. A value that has no element to own it, such as a Hyper link's custom field, can't use it.
 */
interface RequiresOwner extends FieldAdapter
{
}
