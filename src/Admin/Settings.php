<?php

declare(strict_types=1);

namespace Tabby\Admin;

use Tabby\Contract\HasHooks;
use Tabby\Domain\Tab;
use Tabby\Domain\TabRepository;

defined('ABSPATH') || exit;

/**
 * Tabvera settings screen, registered as a WooCommerce submenu.
 *
 * Manages the reusable global tabs (title + safe HTML content + enabled) stored
 * in the `tabby_settings` option. All output escaped; all input sanitised on
 * save; the save capability is aligned to manage_woocommerce.
 */
final class Settings implements HasHooks
{
    private const OPTION = 'tabby_settings';
    private const PAGE   = 'tabby-settings';

    private ?ProUpsell $proUpsell = null;

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        $this->proUpsell()->registerHooks();
    }

    private function proUpsell(): ProUpsell
    {
        return $this->proUpsell ??= new ProUpsell();
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ('woocommerce_page_' . self::PAGE !== $hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'tabby-admin',
            \Tabby\Plugin::instance()->url('assets/css/admin.css'),
            [],
            \Tabby\VERSION,
        );

        wp_enqueue_script(
            'tabby-repeater',
            \Tabby\Plugin::instance()->url('assets/js/repeater.js'),
            [],
            \Tabby\VERSION,
            ['in_footer' => true, 'strategy' => 'defer'],
        );
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Tabvera - Product Tabs', 'tabvera'),
            __('Tabvera Tabs', 'tabvera'),
            'manage_woocommerce',
            self::PAGE,
            [$this, 'renderPage'],
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::PAGE,
            self::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
            ],
        );

        add_filter(
            'option_page_capability_' . self::PAGE,
            static fn (): string => 'manage_woocommerce',
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $repo         = new TabRepository();
        $settings     = $repo->settings();
        $globalTabs   = $repo->globalTabs();
        $richContent  = (bool) ($settings['rich_content'] ?? false);
        ?>
        <div class="wrap tabby-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php
            // WordPress prints this by itself only under Settings; a WooCommerce
            // submenu posting to options.php gets no "Settings saved" otherwise.
            settings_errors();
            ?>

            <?php $this->proUpsell()->banner(); ?>

            <div class="tabby-admin__intro">
                <span class="tabby-admin__intro-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
                        <path fill="currentColor" d="M3 5a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5zm2 4v8h14V9H5z"/>
                    </svg>
                </span>
                <div class="tabby-admin__intro-text">
                    <h2><?php esc_html_e('Reusable tabs for every product page', 'tabvera'); ?></h2>
                    <p><?php esc_html_e('Define tabs once and they appear on all single product pages, at the position you pick for each tab. Basic HTML is allowed in tab content.', 'tabvera'); ?></p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE); ?>

                <div class="tabby-admin__section">
                    <h2><?php esc_html_e('General', 'tabvera'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable Tabvera', 'tabvera'); ?></th>
                                <td>
                                    <label for="tabby_enabled">
                                        <input
                                            type="checkbox"
                                            id="tabby_enabled"
                                            name="<?php echo esc_attr(self::OPTION); ?>[enabled]"
                                            value="1"
                                            aria-describedby="tabby_enabled_help"
                                            <?php checked((bool) ($settings['enabled'] ?? false), true); ?>
                                        />
                                        <?php esc_html_e('Render custom tabs on single product pages.', 'tabvera'); ?>
                                    </label>
                                    <p class="description" id="tabby_enabled_help">
                                        <?php esc_html_e('Master switch. Turn this off to hide every tab below at once without deleting them, your tabs stay saved and reappear when you turn it back on.', 'tabvera'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Shortcodes and blocks in tabs', 'tabvera'); ?></th>
                                <td>
                                    <label for="tabby_rich_content">
                                        <input
                                            type="checkbox"
                                            id="tabby_rich_content"
                                            name="<?php echo esc_attr(self::OPTION); ?>[rich_content]"
                                            value="1"
                                            aria-describedby="tabby_rich_content_help"
                                            data-tabby-rich-toggle
                                            <?php checked((bool) ($settings['rich_content'] ?? false), true); ?>
                                        />
                                        <?php esc_html_e('Run tab content through the normal WordPress content filters.', 'tabvera'); ?>
                                    </label>
                                    <p class="description" id="tabby_rich_content_help">
                                        <?php esc_html_e('Lets a tab body use shortcodes and blocks. Off by default, because it is a wider surface than the safe-HTML pass used otherwise. Only the tabs you wrote yourself are affected.', 'tabvera'); ?>
                                        <?php esc_html_e('It applies to every tab below, not to one of them, so each tab says which pass it gets.', 'tabvera'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="tabby-admin__section">
                    <h2><?php esc_html_e('Tabs', 'tabvera'); ?></h2>
                    <p class="tabby-admin__section-intro">
                        <?php esc_html_e('Each tab shows on every product page at its position (after reviews unless you pick another). Tabs with the same position keep the order listed here. A row with no title is dropped when you save, so leave a blank row to discard it.', 'tabvera'); ?>
                        <?php // Say it here, because a title-only row is saved and then never seen: the shopper only gets a tab when there is something in it. ?>
                        <?php esc_html_e('A tab with an empty content box is kept for later but stays off the storefront, since an empty tab has nothing to show.', 'tabvera'); ?>
                    </p>

                    <div class="tabby-repeater" data-tabby-repeater>
                        <div class="tabby-repeater__rows" data-tabby-rows>
                            <?php
                            if ([] === $globalTabs) {
                                $this->renderGlobalRow(0, '', '', true, $richContent);
                            } else {
                                foreach (array_values($globalTabs) as $index => $tab) {
                                    $this->renderGlobalRow((int) $index, $tab->title, $tab->content, $tab->enabled, $richContent, false, $tab->id, $tab->position);
                                }
                            }
                            ?>
                        </div>

                        <template data-tabby-template>
                            <?php $this->renderGlobalRow(0, '', '', true, $richContent, true); ?>
                        </template>

                        <p>
                            <button type="button" class="button button-secondary" data-tabby-add>
                                <?php esc_html_e('Add tab', 'tabvera'); ?>
                            </button>
                        </p>
                    </div>
                </div>

                <div class="tabby-admin__section" style="margin-top:24px;">
                    <h2><?php esc_html_e('Ecosystem integrations', 'tabvera'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Respondo (Product FAQs)', 'tabvera'); ?></th>
                                <td>
                                    <?php if (class_exists('\\Answers\\Plugin')) : ?>
                                        <p class="description" style="color:#007017;font-weight:600;">
                                            <?php esc_html_e('Respondo is active. Product FAQs appear in an accessible accordion tab alongside your custom tabs.', 'tabvera'); ?>
                                        </p>
                                    <?php else : ?>
                                        <p class="description">
                                            <?php
                                            printf(
                                                /* translators: %s: partner plugin name, linked to its search on the Add Plugins screen. */
                                                esc_html__('Install %s to add per-product FAQs as an accessible accordion tab to reduce pre-sale customer questions.', 'tabvera'),
                                                '<a href="' . esc_url(admin_url('plugin-install.php?s=' . rawurlencode('Respondo - Product Q&A for WooCommerce') . '&tab=search&type=term')) . '">' . esc_html('Respondo - Product Q&A for WooCommerce') . '</a>'
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>

            <?php $this->proUpsell()->cards(); ?>
        </div>
        <?php
    }

    /**
     * Render a single global-tab repeater row.
     */
    private function renderGlobalRow(int $index, string $title, string $content, bool $enabled, bool $richContent = false, bool $isTemplate = false, string $id = '', string $position = Tab::POSITION_AFTER_REVIEWS): void
    {
        $i = $isTemplate ? '__index__' : (string) $index;
        $base = self::OPTION . '[global_tabs][' . $i . ']';
        ?>
        <div class="tabby-repeater__row" data-tabby-row>
            <?php // The id is generated once and kept, so renaming a tab keeps whatever is keyed by it. ?>
            <input type="hidden" name="<?php echo esc_attr($base . '[id]'); ?>" value="<?php echo esc_attr($id); ?>" />
            <div class="tabby-repeater__head">
                <label class="tabby-repeater__field tabby-repeater__field--title">
                    <span class="tabby-repeater__label"><?php esc_html_e('Tab title', 'tabvera'); ?></span>
                    <input
                        type="text"
                        name="<?php echo esc_attr($base . '[title]'); ?>"
                        value="<?php echo esc_attr($title); ?>"
                        class="widefat"
                        placeholder="<?php esc_attr_e('e.g. Shipping & Returns', 'tabvera'); ?>"
                    />
                </label>
                <label class="tabby-repeater__field tabby-repeater__field--position">
                    <span class="tabby-repeater__label"><?php esc_html_e('Position', 'tabvera'); ?></span>
                    <select name="<?php echo esc_attr($base . '[position]'); ?>">
                        <?php foreach (Tab::positions() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($position, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tabby-repeater__toggle">
                    <input
                        type="checkbox"
                        name="<?php echo esc_attr($base . '[enabled]'); ?>"
                        value="1"
                        <?php checked($enabled, true); ?>
                    />
                    <?php esc_html_e('Enabled', 'tabvera'); ?>
                    <span class="tabby-repeater__hint"><?php esc_html_e('shows this tab on the storefront; uncheck to hide just this one', 'tabvera'); ?></span>
                </label>
                <button type="button" class="button-link tabby-repeater__remove" data-tabby-remove>
                    <span aria-hidden="true">&times;</span>
                    <span class="screen-reader-text"><?php esc_html_e('Remove this tab', 'tabvera'); ?></span>
                </button>
            </div>
            <label class="tabby-repeater__field">
                <span class="tabby-repeater__label">
                    <?php esc_html_e('Tab content', 'tabvera'); ?>
                    <?php
                    /*
                     * "Shortcodes and blocks in tabs" is one switch that changes how
                     * every one of these boxes is rendered on the storefront. That is
                     * invisible from down here, so each box carries the current pass.
                     * Server-rendered from the saved option, so it is right without
                     * JavaScript; the script only keeps it in step before you save.
                     */
                    ?>
                    <span class="tabby-repeater__pass" data-tabby-rich-note <?php echo $richContent ? '' : 'hidden'; ?>>
                        <?php esc_html_e('shortcodes and blocks run here', 'tabvera'); ?>
                    </span>
                </span>
                <textarea
                    name="<?php echo esc_attr($base . '[content]'); ?>"
                    rows="4"
                    class="widefat"
                    placeholder="<?php esc_attr_e('Basic HTML is allowed (links, lists, bold, etc.).', 'tabvera'); ?>"
                ><?php echo esc_textarea($content); ?></textarea>
                <span class="tabby-repeater__hint">
                    <?php
                    printf(
                        /* translators: %s: an example HTML snippet shown as inline guidance. */
                        esc_html__('Same HTML as a post, e.g. %s. Scripts and unsafe tags are stripped on save.', 'tabvera'),
                        '<code>&lt;strong&gt;Ships in 24h&lt;/strong&gt; &lt;a href="…"&gt;Size guide&lt;/a&gt;</code>'
                    );
                    ?>
                </span>
            </label>
        </div>
        <?php
    }

    /**
     * Sanitise submitted settings before save.
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public function sanitize(mixed $raw): array
    {
        if (! is_array($raw)) {
            $raw = [];
        }

        /** @var array<string, mixed> $defaults */
        $defaults = require TABBY_DIR . 'config/defaults.php';

        $globalTabs = [];
        if (isset($raw['global_tabs']) && is_array($raw['global_tabs'])) {
            foreach ($raw['global_tabs'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $title = isset($entry['title']) && is_scalar($entry['title']) ? sanitize_text_field((string) $entry['title']) : '';
                if ('' === $title) {
                    continue;
                }
                $globalTabs[] = [
                    'id'       => isset($entry['id']) && is_scalar($entry['id']) ? sanitize_key((string) $entry['id']) : '',
                    'title'    => $title,
                    'content'  => isset($entry['content']) && is_scalar($entry['content']) ? wp_kses_post((string) $entry['content']) : '',
                    'enabled'  => ! empty($entry['enabled']),
                    'position' => Tab::normalisePosition($entry['position'] ?? null),
                ];
            }
        }

        return array_merge($defaults, [
            'enabled'     => ! empty($raw['enabled']),
            'rich_content' => ! empty($raw['rich_content']),
            'global_tabs' => self::assignIds($globalTabs),
        ]);
    }

    /**
     * Give every tab a stable id. An id the row already carries is kept, so a
     * rename no longer moves the tab to a new id and orphans what add-ons key
     * by it (category rules, per-product content). Before 1.2.0 the id was
     * rebuilt from the title on every save; the form now posts the stored id
     * back, so existing tabs keep the id they have. Only a new row, or a row
     * whose id another row already claimed, gets one built from its title.
     *
     * @param array<int, array<string, mixed>> $tabs
     * @return array<int, array<string, mixed>>
     */
    public static function assignIds(array $tabs): array
    {
        $used = [];
        foreach ($tabs as $i => $tab) {
            $id = (string) ($tab['id'] ?? '');
            if ('' !== $id && ! isset($used[$id])) {
                $used[$id] = true;
            } else {
                $tabs[$i]['id'] = '';
            }
        }

        foreach ($tabs as $i => $tab) {
            if ('' !== $tab['id']) {
                continue;
            }
            $base = sanitize_key((string) ($tab['title'] ?? ''));
            if ('' === $base) {
                $base = 'tab_' . $i;
            }
            $id = $base;
            $n  = 1;
            while (isset($used[$id])) {
                $id = $base . '_' . $n;
                ++$n;
            }
            $used[$id]      = true;
            $tabs[$i]['id'] = $id;
        }

        return $tabs;
    }
}
