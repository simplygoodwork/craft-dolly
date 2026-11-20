<?php

namespace simplygoodwork\craftdolly\references\definitions;

use craft\base\ElementInterface;
use craft\elements\User;
use craft\helpers\Db;
use simplygoodwork\craftdolly\references\ElementTypeDefinition;

/**
 * Users. Natural key: email, then username. Not limited to a source, and never Stubbed: Dolly never creates users.
 */
final class UserDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return User::class;
    }

    public function naturalKey(ElementInterface $element): ?array
    {
        return $element instanceof User ? ['email' => $element->email, 'username' => $element->username] : null;
    }

    public function find(array $naturalKey): ?int
    {
        foreach (['email', 'username'] as $attribute) {
            $value = $naturalKey[$attribute] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            $id = User::find()
                ->$attribute(Db::escapeParam($value))
                ->status(null)
                ->ids()[0] ?? null;

            if ($id) {
                return (int)$id;
            }
        }

        return null;
    }
}
