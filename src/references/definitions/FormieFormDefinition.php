<?php

namespace simplygoodwork\craftdolly\references\definitions;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use simplygoodwork\craftdolly\references\ElementTypeDefinition;
use verbb\formie\elements\db\FormQuery;
use verbb\formie\elements\Form;

/**
 * Formie forms, registered only when Formie is installed and enabled. Forms aren't in project config, so their UIDs
 * differ between environments. Natural key: the form handle, which Formie keeps unique. Forms aren't localized.
 *
 * Not limited to an element source: Formie 3's sources are form templates and Formie 4's are form groups, which say
 * how a form looks or where it's filed in the control panel, not what it is. A form whose template or group differs
 * between environments is still the same form. Never Stubbed: an empty form would render on the page as a form with
 * no fields.
 */
final class FormieFormDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return Form::class;
    }

    public function naturalKey(ElementInterface $element): ?array
    {
        if (!$element instanceof Form || !is_string($element->handle) || $element->handle === '') {
            return null;
        }

        return ['handle' => $element->handle];
    }

    public function find(array $naturalKey): ?int
    {
        $handle = $naturalKey['handle'] ?? null;
        if (!is_string($handle) || $handle === '') {
            return null;
        }

        /** @var FormQuery $query */
        $query = $this->query();
        $ids = $query->handle(Db::escapeParam($handle))->status(null)->site('*')->unique()->limit(2)->ids();

        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /**
     * Formie 4 limits form queries in control panel requests to the forms the current user can view in its index. A
     * relation isn't that index, and Formie's own Forms field lifts the limit too, so an Import from the control panel
     * relates a form its user can't view in Formie, as editing the field there can. Formie 3 has no limit.
     */
    public function query(): ElementQueryInterface
    {
        $query = parent::query();
        if (method_exists($query, 'withoutCpIndexScope')) {
            $query->withoutCpIndexScope();
        }

        return $query;
    }
}
