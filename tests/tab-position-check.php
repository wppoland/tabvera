<?php

/**
 * Tab positions among the native WooCommerce tabs, and stable tab ids.
 *
 * Runs without WordPress; the few core helpers used are stubbed below.
 *
 * Run: php tests/tab-position-check.php
 */

declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__);
    define('TABBY_DIR', dirname(__DIR__) . '/');

    function __(string $s, string $domain = ''): string
    {
        return $s;
    }
    function sanitize_key(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
    }
    function sanitize_text_field(string $s): string
    {
        return trim(strip_tags($s));
    }
    function wp_kses_post(string $s): string
    {
        return $s;
    }

    require dirname(__DIR__) . '/src/Contract/HasHooks.php';
    require dirname(__DIR__) . '/src/Domain/Tab.php';
    require dirname(__DIR__) . '/src/Service/TabsRenderer.php';
    require dirname(__DIR__) . '/src/Admin/Settings.php';
}

namespace Tabby\Tests {

    use Tabby\Admin\Settings;
    use Tabby\Domain\Tab;
    use Tabby\Service\TabsRenderer;

    $fail = 0;
    $check = static function (string $name, bool $ok) use (&$fail): void {
        echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
        $fail += $ok ? 0 : 1;
    };

    /**
     * Strip order after merging our priorities and sorting the way WooCommerce does.
     *
     * @param array<string, array<string, mixed>> $core
     * @param array<string, string>               $placed
     * @return array<int, string>
     */
    $order = static function (array $core, array $placed): array {
        $all = $core;
        foreach (TabsRenderer::priorities($core, $placed) as $key => $priority) {
            $all[$key] = ['priority' => $priority];
        }
        uasort($all, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_keys($all);
    };

    $core = [
        'description'            => ['priority' => 10],
        'additional_information' => ['priority' => 20],
        'reviews'                => ['priority' => 30],
    ];

    // Default: after reviews, numbered 100+ exactly as before 1.2.0.
    $check('default keeps 100, 101', TabsRenderer::priorities($core, ['tabby_a' => 'after_reviews', 'tabby_b' => 'x']) === ['tabby_a' => 100, 'tabby_b' => 101]);

    // The 3tarasy strip: Opis | Specyfikacja | Montaz | Dokumenty | Opinie.
    $check('target order', $order($core, ['tabby_montaz' => 'before_reviews', 'tabby_pro_documents' => 'before_reviews'])
        === ['description', 'additional_information', 'tabby_montaz', 'tabby_pro_documents', 'reviews']);

    $check('every position, stable within a group', $order($core, [
        'tabby_last'   => 'after_reviews',
        'tabby_br1'    => 'before_reviews',
        'tabby_first1' => 'first',
        'tabby_ad1'    => 'after_description',
        'tabby_br2'    => 'before_reviews',
        'tabby_first2' => 'first',
        'tabby_ad2'    => 'after_description',
    ]) === ['tabby_first1', 'tabby_first2', 'description', 'tabby_ad1', 'tabby_ad2', 'additional_information', 'tabby_br1', 'tabby_br2', 'reviews', 'tabby_last']);

    // No attributes, so no additional information tab: both groups share the gap.
    $noInfo = ['description' => ['priority' => 10], 'reviews' => ['priority' => 30]];
    $check('shared gap keeps group order', $order($noInfo, ['tabby_br' => 'before_reviews', 'tabby_ad' => 'after_description'])
        === ['description', 'tabby_ad', 'tabby_br', 'reviews']);

    // Reviews off and an empty description: the core numbers stand in.
    $check('anchors missing', $order([], ['tabby_br' => 'before_reviews', 'tabby_ad' => 'after_description', 'tabby_f' => 'first', 'tabby_l' => 'after_reviews'])
        === ['tabby_f', 'tabby_ad', 'tabby_br', 'tabby_l']);
    $check('only reviews, first stays first', $order(['reviews' => ['priority' => 30]], ['tabby_ad' => 'after_description', 'tabby_f' => 'first'])
        === ['tabby_f', 'tabby_ad', 'reviews']);

    // A theme that moved the native tabs.
    $moved = ['description' => ['priority' => 5], 'additional_information' => ['priority' => 50], 'reviews' => ['priority' => 60], 'brand' => ['priority' => 55]];
    $check('moved anchors', $order($moved, ['tabby_br' => 'before_reviews', 'tabby_ad' => 'after_description', 'tabby_f' => 'first'])
        === ['tabby_f', 'description', 'tabby_ad', 'additional_information', 'brand', 'tabby_br', 'reviews']);

    $many = [];
    for ($i = 0; $i < 25; ++$i) {
        $many['tabby_' . $i] = 'before_reviews';
    }
    $check('25 tabs fit one gap in order', array_slice($order($core, $many), 2, 25) === array_keys($many));

    // Tab value object.
    $check('fromArray without position defaults to after reviews', Tab::fromArray(['id' => 'a', 'title' => 'A'])?->position === 'after_reviews');
    $check('fromArray keeps a known position', Tab::fromArray(['id' => 'a', 'title' => 'A', 'position' => 'first'])?->position === 'first');
    $check('fromArray rejects an unknown position', Tab::fromArray(['id' => 'a', 'title' => 'A', 'position' => '<x>'])?->position === 'after_reviews');

    // Stable ids.
    $ids = static fn (array $rows): array => array_column(Settings::assignIds($rows), 'id');
    $check('rename keeps the id', $ids([['id' => 'montaz', 'title' => 'Instalacja']]) === ['montaz']);
    $check('new tab with an old title gets a suffix', $ids([['id' => 'spec', 'title' => 'Other'], ['id' => '', 'title' => 'Spec']]) === ['spec', 'spec_1']);
    $check('kept id wins over an earlier new row', $ids([['id' => '', 'title' => 'Spec'], ['id' => 'spec', 'title' => 'Renamed']]) === ['spec_1', 'spec']);
    $check('fallback id survives removing a row above it', $ids([['id' => 'tab_1', 'title' => '!!!']]) === ['tab_1']);
    $check('duplicate posted ids are split', $ids([['id' => 'a', 'title' => 'A'], ['id' => 'a', 'title' => 'A']]) === ['a', 'a_1']);
    $check('id is built once from the title', $ids([['id' => '', 'title' => 'Shipping & Returns']]) === ['shippingreturns']);

    $s = (new Settings())->sanitize(['global_tabs' => [
        ['id' => 'spec', 'title' => 'Specyfikacja', 'content' => 'x', 'enabled' => '1', 'position' => 'after_description'],
        ['title' => 'Nowa', 'content' => 'y', 'position' => 'evil'],
        ['title' => ''],
        ['id' => ['x'], 'title' => ['y']],
    ]]);
    $check('sanitize keeps id and position', $s['global_tabs'][0]['id'] === 'spec' && $s['global_tabs'][0]['position'] === 'after_description');
    $check('sanitize defaults new rows', $s['global_tabs'][1]['id'] === 'nowa' && $s['global_tabs'][1]['position'] === 'after_reviews');
    $check('sanitize drops untitled and malformed rows', 2 === count($s['global_tabs']));

    echo 0 === $fail ? "tab-position-check: OK\n" : "tab-position-check: {$fail} failed\n";
    exit(0 === $fail ? 0 : 1);
}
