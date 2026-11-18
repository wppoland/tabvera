<?php

declare(strict_types=1);

namespace Tabby\Domain;

defined('ABSPATH') || exit;

/**
 * Immutable value object describing a single product tab.
 *
 * `content` holds limited, pre-sanitised HTML (wp_kses_post).
 */
final class Tab
{
    /**
     * A reusable tab defined on the settings screen and shown on every product.
     */
    public const SOURCE_GLOBAL = 'global';

    /**
     * A tab attached to a single product. Reserved for add-ons that store
     * per-product tabs; the free plugin only produces global tabs.
     */
    public const SOURCE_PRODUCT = 'product';

    /**
     * Where a tab sits among the native WooCommerce tabs. After reviews is the
     * default and the only placement before 1.2.0, so stored tabs without a
     * position render exactly where they always did.
     */
    public const POSITION_FIRST             = 'first';
    public const POSITION_AFTER_DESCRIPTION = 'after_description';
    public const POSITION_BEFORE_REVIEWS    = 'before_reviews';
    public const POSITION_AFTER_REVIEWS     = 'after_reviews';

    /**
     * @param string $source One of self::SOURCE_* describing where the tab
     *                       originates. Add-ons (e.g. category rules) branch on
     *                       this to decide whether a tab is subject to their
     *                       scoping rules.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $content,
        public readonly bool $enabled = true,
        public readonly string $source = self::SOURCE_GLOBAL,
        public readonly string $position = self::POSITION_AFTER_REVIEWS,
    ) {
    }

    /**
     * Position choices with their labels, in strip order.
     *
     * @return array<string, string>
     */
    public static function positions(): array
    {
        return [
            self::POSITION_FIRST             => __('First', 'tabvera'),
            self::POSITION_AFTER_DESCRIPTION => __('After description', 'tabvera'),
            self::POSITION_BEFORE_REVIEWS    => __('Before reviews', 'tabvera'),
            self::POSITION_AFTER_REVIEWS     => __('After reviews', 'tabvera'),
        ];
    }

    /**
     * A known position, or the default for anything else.
     */
    public static function normalisePosition(mixed $position): string
    {
        $position = is_string($position) ? $position : '';

        return in_array($position, [self::POSITION_FIRST, self::POSITION_AFTER_DESCRIPTION, self::POSITION_BEFORE_REVIEWS], true)
            ? $position
            : self::POSITION_AFTER_REVIEWS;
    }

    /**
     * Build a Tab from a loosely-typed array, sanitising every field. Returns
     * null when the row has no usable id or title.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $id    = isset($raw['id']) ? sanitize_key((string) $raw['id']) : '';
        $title = isset($raw['title']) ? sanitize_text_field((string) $raw['title']) : '';

        if ('' === $id || '' === $title) {
            return null;
        }

        $content = isset($raw['content']) ? wp_kses_post((string) $raw['content']) : '';
        $enabled = ! empty($raw['enabled']);

        $source = isset($raw['source']) ? sanitize_key((string) $raw['source']) : self::SOURCE_GLOBAL;
        if (self::SOURCE_PRODUCT !== $source) {
            $source = self::SOURCE_GLOBAL;
        }

        return new self($id, $title, $content, $enabled, $source, self::normalisePosition($raw['position'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'title'    => $this->title,
            'content'  => $this->content,
            'enabled'  => $this->enabled,
            'source'   => $this->source,
            'position' => $this->position,
        ];
    }
}
