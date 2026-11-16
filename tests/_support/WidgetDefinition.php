<?php

namespace simplygoodwork\craftportage\tests\support;

use craft\base\ElementInterface;
use craft\helpers\Db;
use simplygoodwork\craftportage\references\ElementTypeDefinition;

/**
 * How a third-party plugin registers widgets with Portage: Natural key the slug, not limited to an element source, and
 * never Stubbed.
 */
final class WidgetDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return Widget::class;
    }

    public function naturalKey(ElementInterface $element): ?array
    {
        return ['slug' => $element->slug];
    }

    public function find(array $naturalKey): ?int
    {
        $slug = $naturalKey['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            return null;
        }

        $ids = Widget::find()->slug(Db::escapeParam($slug))->status(null)->limit(2)->ids();

        return count($ids) === 1 ? (int)$ids[0] : null;
    }
}
