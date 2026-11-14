<?php

namespace simplygoodwork\craftportage\import;

use craft\base\ElementInterface;
use craft\helpers\StringHelper;

/**
 * A reference tag whose element (a Stub or a fetched asset) has no ID until the Importer saves it.
 *
 * Planning writes a token in its place, which survives Craft's normalisation of text values. The Importer replaces
 * the token as each element is saved, after the Stubs and fetched assets.
 */
final class PendingRefTag
{
    private const TOKEN_PREFIX = '{portage-pending:';

    public readonly string $token;

    /**
     * @param string $before the tag up to the element ID, e.g. `{entry:`
     * @param string $after the tag after the element ID, e.g. `:url}`
     * @param string $unsaved what replaces the tag if the element isn't saved (its fetch failed): its fallback text
     */
    public function __construct(
        private readonly ElementInterface $target,
        private readonly string $before,
        private readonly string $after,
        private readonly string $unsaved,
    ) {
        $this->token = self::TOKEN_PREFIX . StringHelper::randomString(16) . '}';
    }

    /**
     * A token for a bare element ID (a Hyper link's `linkValue`) rather than a tag, which renders as the ID, or as
     * nothing if the element isn't saved.
     */
    public static function bareId(ElementInterface $target): self
    {
        return new self($target, '', '', '');
    }

    public function render(): string
    {
        return $this->target->id !== null ? $this->before . $this->target->id . $this->after : $this->unsaved;
    }

    /**
     * Replaces the tokens in a text with their tags.
     *
     * @param array<string, PendingRefTag> $pending by token
     */
    public static function renderIn(string $text, array $pending): string
    {
        if (!str_contains($text, self::TOKEN_PREFIX)) {
            return $text;
        }

        return strtr($text, array_map(fn(PendingRefTag $tag) => $tag->render(), $pending));
    }
}
