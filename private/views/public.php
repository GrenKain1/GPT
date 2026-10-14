<?php
declare(strict_types=1);

function fd_public_icon(string $name, string $class = ''): string
{
    $paths = [
        'arrow' => '<path d="M4 12h15m-6-6 6 6-6 6"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/><path d="m17 3 .6 2.4L20 6l-2.4.6L17 9l-.6-2.4L14 6l2.4-.6L17 3Z"/>',
        'size' => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 5v4m4-4v6m4-6v4M8 15h8m-8 0 2-2m-2 2 2 2m6-2-2-2m2 2-2 2"/>',
        'chat' => '<path d="M20 11.5a8 8 0 0 1-8 8c-1.3 0-2.5-.3-3.6-.8L4 20l1.3-4.4A8 8 0 1 1 20 11.5Z"/><path d="M8 11h8m-8 4h5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'phone' => '<path d="m5 3 4 1 1 4-3 2c1.5 3 4 5.5 7 7l2-3 4 1 1 4c-1 3-5 2-8 0C8 16 4 12 3 7c-.5-2 .5-3 2-4Z"/>',
        'pin' => '<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'truck' => '<path d="M3 5h11v12H3V5Zm11 4h4l3 4v4h-7V9Z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'accessibility' => '<circle cx="12" cy="4" r="2"/><path d="m4 8 8 2 8-2M12 10v5m0 0-5 6m5-6 5 6"/>',
        'hypoallergenic' => '<path d="M5 18C3 8 10 3 20 4c1 10-4 17-14 14Zm1-1 9-9"/>',
        'breathable' => '<path d="M3 8h13c5 0 5-5 1-5M3 12h16c4 0 4 5 0 5M3 16h8c4 0 4 5 1 5"/>',
        'washable_cover' => '<path d="M3 7h18l-2 13H5L3 7ZM6 3v2m6-2v2m6-2v2M5 13c3-3 5 3 8 0s4 2 6 0"/>',
        'no_ironing' => '<path d="M4 17h16l-2-8h-7L8 5H4m0 0 16 16M9 13H5l-1 4"/>',
        'no_flip' => '<rect x="5" y="9" width="14" height="6" rx="2"/><path d="M5 5h11l-2-2m2 2-2 2M19 19H8l2-2m-2 2 2 2M3 3l18 18"/>',
        'orthopedic' => '<path d="M7 4h10M7 20h10M12 4v16M8 7h8M8 10h8M8 13h8M8 16h8"/>',
        'warranty' => '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/>',
        'fax' => '<path d="M7 8V3h10v5M7 17v4h10v-4M5 17H3V8h18v9h-2M7 13h10v4H7v-4ZM17 10h1"/>',
    ];
    return '<svg class="icon ' . fd_e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['check']) . '</svg>';
}

function fd_public_localized(mixed $value, string $lang): string
{
    return is_array($value) ? (string)($value[$lang] ?? $value['he'] ?? '') : (string)($value ?? '');
}

function fd_public_money(float|int|string $value): string
{
    $number = (float)$value;
    return '₪' . number_format($number, floor($number) === $number ? 0 : 2, '.', ',');
}

function fd_public_size_label(array $data, string $lang, string $label): string
{
    return preg_replace_callback('/\bcm\b/u', static fn(array $match): string => fd_t($data, $lang, 'centimeter_unit'), $label) ?? $label;
}

function fd_public_product_content(array $product, string $lang): array
{
    return $product['content'][$lang] ?? $product['content']['he'] ?? [];
}

function fd_public_asset_image(string $src, string $alt, string $class = '', bool $eager = false): string
{
    $fallback = $src;
    if (str_ends_with(strtolower($src), '.webp')) {
        foreach (['jpg', 'png'] as $extension) {
            $candidate = substr($src, 0, -4) . $extension;
            if (is_file(FD_PUBLIC_DIR . '/' . ltrim($candidate, '/'))) { $fallback = $candidate; break; }
        }
    }
    static $imageSizes = [];
    if (!isset($imageSizes[$fallback])) {
        $file = realpath(FD_PUBLIC_DIR . '/' . ltrim($fallback, '/'));
        $root = realpath(FD_PUBLIC_DIR);
        $size = $file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? @getimagesize($file) : false;
        $imageSizes[$fallback] = $size ? [(int)$size[0], (int)$size[1]] : [1000, 1000];
    }
    [$width, $height] = $imageSizes[$fallback];
    $dimensions = ' width="' . $width . '" height="' . $height . '"';
    $img = '<img class="' . fd_e($class) . '" src="' . fd_e(fd_url($fallback)) . '" alt="' . fd_e($alt) . '" loading="' . ($eager ? 'eager' : 'lazy') . '" decoding="async"' . ($eager && $class !== 'brand-logo' ? ' fetchpriority="high"' : '') . $dimensions . '>';
    return $src !== $fallback ? '<picture><source type="image/webp" srcset="' . fd_e(fd_url($src)) . '">' . $img . '</picture>' : $img;
}

function fd_public_logo(string $src, string $brand, bool $eager = false): string
{
    $image = fd_public_asset_image($src, $brand, 'brand-logo', $eager);
    return in_array($src, ['media/fantasydream-logo.webp', 'media/fantasydream-logo.png'], true)
        ? '<span class="brand-logo-crop">' . $image . '</span>' : $image;
}

function fd_public_image(array $image, string $lang, string $fallback, bool $eager = false): string
{
    $alt = fd_public_localized($image['alt'] ?? '', $lang);
    return fd_public_asset_image((string)($image['src'] ?? ''), $alt !== '' ? $alt : $fallback, '', $eager);
}

function fd_public_product_card(array $data, string $lang, array $product, int $index): void
{
    $c = fd_public_product_content($product, $lang);
    $name = (string)($c['name'] ?? '');
    $images = $product['images'] ?? [];
    $sizes = $product['sizes'] ?? [];
    $prices = array_map(static fn(array $size): float => (float)($size['price'] ?? 0), $sizes);
    $url = fd_url($lang . '/product/' . rawurlencode((string)$product['slug']) . '/');
    $sizeLabels = array_map(static fn(array $size): string => (string)($size['label'] ?? ''), $sizes);
    $sizeCount = count($sizes);
    $countKey = $sizeCount === 1 ? 'size_count_one' : 'size_count';
    if ($lang === 'ru') {
        if ($sizeCount % 10 === 1 && $sizeCount % 100 !== 11) $countKey = 'size_count_one';
        elseif ($sizeCount % 10 >= 2 && $sizeCount % 10 <= 4 && ($sizeCount % 100 < 12 || $sizeCount % 100 > 14)) $countKey = 'size_count_few';
    }
    ?>
    <article class="product-card" data-product-card data-search="<?= fd_e($name . ' ' . (string)($c['summary'] ?? '')) ?>" data-price="<?= fd_e($prices ? (string)min($prices) : '999999999') ?>" data-sizes="<?= fd_e(json_encode($sizeLabels, JSON_UNESCAPED_UNICODE)) ?>" data-order="<?= $index ?>">
        <a class="product-card__visual" href="<?= fd_e($url) ?>" aria-label="<?= fd_e($name) ?>">
            <?php if ($images): ?><?= fd_public_image($images[0], $lang, $name) ?><?php else: ?>
                <div class="photo-placeholder"><?= fd_public_icon('moon') ?><span><?= fd_e(fd_t($data, $lang, 'photo_pending')) ?></span></div>
            <?php endif; ?>
            <span class="product-card__index" aria-hidden="true"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
            <span class="card-arrow"><?= fd_public_icon('arrow', 'directional') ?></span>
        </a>
        <div class="product-card__body">
            <h3><a href="<?= fd_e($url) ?>"><?= fd_e($name) ?></a></h3>
            <?php if (!empty($c['summary'])): ?><p class="product-card__summary"><?= fd_e($c['summary']) ?></p><?php endif; ?>
            <div class="product-card__bottom">
                <?php if ($prices): ?><p class="card-price"><span><?= fd_e(fd_t($data, $lang, 'from_price')) ?></span> <bdi><?= fd_e(fd_public_money(min($prices))) ?></bdi></p><?php else: ?><p class="muted small"><?= fd_e(fd_t($data, $lang, 'price_pending')) ?></p><?php endif; ?>
                <?php if ($sizes): ?><span class="card-size-count"><?= count($sizes) ?> <?= fd_e(fd_t($data, $lang, $countKey)) ?></span><?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}

function fd_public_empty(array $data, string $lang): void
{
    ?>
    <div class="empty-catalog">
        <span class="empty-catalog__icon"><?= fd_public_icon('moon') ?></span>
        <h3><?= fd_e(fd_t($data, $lang, 'empty_title')) ?></h3>
        <p><?= fd_e(fd_t($data, $lang, 'empty_body')) ?></p>
        <a class="text-link" href="<?= fd_e(fd_url($lang . '/contact/')) ?>"><?= fd_e(fd_t($data, $lang, 'nav_contact')) ?> <?= fd_public_icon('arrow', 'directional') ?></a>
    </div>
    <?php
}

function fd_render_public(array $data, string $lang, string $page, ?array $product): void
{
    $settings = $data['settings'] ?? [];
    $brand = (string)($settings['brand'] ?? 'FantasyDream');
    $home = $data['home'][$lang] ?? [];
    $contact = $data['contact'][$lang] ?? [];
    $products = array_values(array_filter($data['products'] ?? [], static fn(array $p): bool => (bool)($p['visible'] ?? false)));
    usort($products, static fn(array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
    $t = static fn(string $key): string => fd_t($data, $lang, $key);
    $homeText = static fn(string $key, string $fallback): string => (string)($home[$key] ?? '') !== '' ? (string)$home[$key] : $t($fallback);
    $email = (string)($settings['email'] ?? '');
    $phone = (string)($settings['phone'] ?? '');
    $whatsapp = preg_replace('/\D/', '', (string)($settings['whatsapp'] ?? ''));
    $contactUrl = fd_url($lang . '/contact/');
    $catalogUrl = fd_url($lang . '/catalog/');
    ?>
    <a class="skip-link" href="#main-content"><?= fd_e($t('skip')) ?></a>
    <div class="announcement"><span><?= fd_e($t('brand_line')) ?></span><span class="announcement__brand" dir="ltr">FANTASYDREAM</span></div>
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="<?= fd_e(fd_url($lang . '/')) ?>" aria-label="<?= fd_e($brand) ?>">
                <?php if (!empty($settings['logo'])): ?><?= fd_public_logo($settings['logo'], $brand, true) ?><?php else: ?><span class="brand-mark"><?= fd_public_icon('moon') ?></span><span class="brand-wordmark" dir="ltr">Fantasy<span>Dream</span><small><?= fd_e($t('brand_motto')) ?></small></span><?php endif; ?>
            </a>
            <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" data-menu-toggle aria-label="<?= fd_e($t('menu_open')) ?>" data-open-label="<?= fd_e($t('menu_open')) ?>" data-close-label="<?= fd_e($t('menu_close')) ?>"><?= fd_public_icon('menu') ?></button>
            <nav class="site-nav" id="site-navigation" aria-label="<?= fd_e($t('footer_navigation')) ?>">
                <a <?= $page === 'home' ? 'aria-current="page"' : '' ?> href="<?= fd_e(fd_url($lang . '/')) ?>"><?= fd_e($t('nav_home')) ?></a>
                <a <?= in_array($page, ['catalog', 'product'], true) ? 'aria-current="page"' : '' ?> href="<?= fd_e($catalogUrl) ?>"><?= fd_e($t('nav_catalog')) ?></a>
                <a <?= $page === 'contact' ? 'aria-current="page"' : '' ?> href="<?= fd_e($contactUrl) ?>"><?= fd_e($t('nav_contact')) ?></a>
            </nav>
            <nav class="language-nav" aria-label="<?= fd_e($t('language')) ?>">
                <?php foreach (['he' => 'עב', 'ru' => 'RU', 'en' => 'EN'] as $locale => $label):
                    $route = $locale . '/';
                    if ($page === 'product' && $product) $route .= 'product/' . rawurlencode((string)$product['slug']) . '/';
                    elseif (in_array($page, ['catalog', 'contact', 'privacy', 'accessibility'], true)) $route .= $page . '/';
                ?><a href="<?= fd_e(fd_url($route)) ?>" lang="<?= $locale ?>" hreflang="<?= $locale ?>" <?= $lang === $locale ? 'aria-current="true"' : '' ?> aria-label="<?= fd_e(['he'=>'עברית','ru'=>'Русский','en'=>'English'][$locale]) ?>"><?= $label ?></a><?php endforeach; ?>
            </nav>
        </div>
    </header>
    <main id="main-content" tabindex="-1">
    <?php if ($page === 'home'): ?>
        <section class="hero container">
            <div class="hero-copy">
                <p class="eyebrow"><span class="eyebrow-line"></span><?= fd_e($homeText('eyebrow', 'hero_eyebrow')) ?></p>
                <h1><?= nl2br(fd_e(str_replace('\\n', "\n", $homeText('title', 'hero_title')))) ?></h1>
                <p class="hero-intro"><?= fd_e($homeText('intro', 'hero_intro')) ?></p>
                <div class="hero-actions"><a class="button button-dark" href="<?= fd_e($catalogUrl) ?>"><?= fd_e($t('hero_catalog')) ?><?= fd_public_icon('arrow', 'directional') ?></a><a class="text-link" href="<?= fd_e($contactUrl) ?>"><?= fd_e($t('hero_contact')) ?></a></div>
                <p class="hero-note"><?= fd_public_icon('check') ?><?= fd_e($t('hero_note')) ?></p>
            </div>
            <figure class="hero-visual">
                <?php if (!empty($home['hero_image'])): ?><?= fd_public_asset_image($home['hero_image'], (string)($home['hero_alt'] ?? ''), 'hero-img', true) ?><?php else: ?><img src="<?= fd_e(fd_url('assets/bedroom.svg')) ?>" alt="" width="800" height="720" fetchpriority="high" decoding="async"><?php endif; ?>
                <figcaption><span class="visual-caption-line"></span><?= fd_e($t('hero_art_caption')) ?></figcaption>
                <span class="hero-visual__badge" aria-hidden="true"><span dir="ltr">FANTASY<br>DREAM</span><?= fd_public_icon('moon') ?></span>
            </figure>
        </section>
        <section class="guide-bar container" aria-label="<?= fd_e($t('hero_note')) ?>">
            <?php foreach ([['size','guide_size_title','guide_size_body'],['chat','guide_advice_title','guide_advice_body'],['mail','guide_order_title','guide_order_body']] as $guide): ?><div class="guide-item"><span class="guide-icon"><?= fd_public_icon($guide[0]) ?></span><div><h2><?= fd_e($t($guide[1])) ?></h2><p><?= fd_e($t($guide[2])) ?></p></div></div><?php endforeach; ?>
        </section>
        <section class="collection-section container">
            <div class="section-heading"><div><p class="eyebrow"><?= fd_e($t('collection_eyebrow')) ?></p><h2><?= fd_e($t('collection_title')) ?></h2><p class="section-intro"><?= fd_e($t('collection_intro')) ?></p></div><a class="text-link" href="<?= fd_e($catalogUrl) ?>"><?= fd_e($t('view_all')) ?><?= fd_public_icon('arrow', 'directional') ?></a></div>
            <?php if ($products): ?><div class="product-grid"><?php foreach (array_slice($products, 0, 3) as $index => $item) fd_public_product_card($data, $lang, $item, $index); ?></div><?php else: fd_public_empty($data, $lang); endif; ?>
        </section>
        <section class="about-section"><div class="container about-grid"><div class="about-art" aria-hidden="true"><div class="about-orbit orbit-one"></div><div class="about-orbit orbit-two"></div><div class="about-orbit orbit-three"></div><span class="about-art__mark"><?= fd_public_icon('moon') ?></span><span class="about-art__word" dir="ltr"><?= fd_e($t('rest_word')) ?></span><span class="about-art__caption"><?= fd_e($t('about_caption')) ?></span></div><div class="about-copy"><p class="eyebrow"><?= fd_e($t('about_eyebrow')) ?></p><h2><?= nl2br(fd_e(str_replace('\\n', "\n", $homeText('about_title', 'about_title')))) ?></h2><p><?= nl2br(fd_e($homeText('about_body', 'about_body'))) ?></p><a class="text-link" href="<?= fd_e($contactUrl) ?>"><?= fd_e($t('about_link')) ?><?= fd_public_icon('arrow', 'directional') ?></a></div></div></section>
    <?php elseif ($page === 'catalog'): ?>
        <section class="page-heading container"><p class="eyebrow"><?= fd_e($t('collection_eyebrow')) ?></p><h1><?= fd_e($t('catalog_title')) ?></h1><p><?= fd_e($t('catalog_intro')) ?></p></section>
        <section class="catalog-section container" aria-label="<?= fd_e($t('collection_title')) ?>" data-catalog>
            <?php if ($products):
                $allSizes = []; foreach ($products as $item) foreach ($item['sizes'] ?? [] as $size) $allSizes[(string)$size['label']] = true;
            ?>
            <div class="catalog-controls" data-catalog-controls hidden>
                <div class="field search-field"><label for="catalog-search"><?= fd_e($t('search_label')) ?></label><div class="input-icon"><?= fd_public_icon('search') ?><input id="catalog-search" type="search" placeholder="<?= fd_e($t('search_placeholder')) ?>" autocomplete="off" data-catalog-search></div></div>
                <div class="field"><label for="catalog-size"><?= fd_e($t('size_filter')) ?></label><select id="catalog-size" data-catalog-size><option value=""><?= fd_e($t('all_sizes')) ?></option><?php foreach (array_keys($allSizes) as $size): ?><option value="<?= fd_e($size) ?>" dir="ltr"><?= fd_e(fd_public_size_label($data, $lang, $size)) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="catalog-sort"><?= fd_e($t('sort_label')) ?></label><select id="catalog-sort" data-catalog-sort><option value="default"><?= fd_e($t('sort_default')) ?></option><option value="low"><?= fd_e($t('sort_low')) ?></option><option value="high"><?= fd_e($t('sort_high')) ?></option></select></div>
            </div>
            <p class="catalog-count" aria-live="polite"><span data-catalog-count><?= count($products) ?></span> <?= fd_e($t('product_count')) ?></p>
            <div class="product-grid" data-catalog-grid><?php foreach ($products as $index => $item) fd_public_product_card($data, $lang, $item, $index); ?></div>
            <div class="no-results" data-catalog-empty hidden><p><?= fd_e($t('no_results')) ?></p><button class="text-link" type="button" data-catalog-reset><?= fd_e($t('reset_filters')) ?></button></div>
            <?php else: fd_public_empty($data, $lang); endif; ?>
        </section>
    <?php elseif ($page === 'product' && $product):
        $c = fd_public_product_content($product, $lang); $name = (string)($c['name'] ?? ''); $images = $product['images'] ?? []; $sizes = $product['sizes'] ?? [];
    ?>
        <div class="container product-breadcrumb"><a href="<?= fd_e($catalogUrl) ?>"><?= fd_public_icon('arrow', 'back-arrow directional') ?><?= fd_e($t('catalog_back')) ?></a></div>
        <section class="container product-detail">
            <div class="product-media">
                <div class="product-gallery" data-gallery data-image-label="<?= fd_e($t('gallery_image')) ?>" data-of-label="<?= fd_e($t('gallery_of')) ?>">
                    <?php if ($images): ?><div class="gallery-track" data-gallery-track><?php foreach ($images as $index => $image): ?><figure class="gallery-slide" data-gallery-slide ><?= fd_public_image($image, $lang, $name, $index === 0) ?></figure><?php endforeach; ?></div><?php else: ?><div class="photo-placeholder product-photo-placeholder"><?= fd_public_icon('moon') ?><span><?= fd_e($t('photo_pending')) ?></span></div><?php endif; ?>
                    <?php if (count($images) > 1): ?><div class="gallery-controls" data-gallery-controls hidden><button type="button" data-gallery-prev aria-label="<?= fd_e($t('gallery_prev')) ?>"><?= fd_public_icon('chevron', 'gallery-prev-icon') ?></button><span class="gallery-status" data-gallery-status aria-live="polite">1 / <?= count($images) ?></span><button type="button" data-gallery-next aria-label="<?= fd_e($t('gallery_next')) ?>"><?= fd_public_icon('chevron') ?></button></div><div class="gallery-thumbs" data-gallery-thumbs hidden><?php foreach ($images as $index => $image): ?><button type="button" data-gallery-index="<?= $index ?>" aria-label="<?= fd_e($t('gallery_image') . ' ' . ($index + 1)) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"><?= fd_public_asset_image($image['src'], '', 'gallery-thumbnail') ?></button><?php endforeach; ?></div><?php endif; ?>
                </div>
                <?php if (!empty($c['description'])): ?><section class="product-description"><p class="eyebrow"><?= fd_e($t('description_title')) ?></p><h2><?= fd_e($name) ?></h2><div class="prose"><?= nl2br(fd_e($c['description'])) ?></div></section><?php endif; ?>
                <?php if (!empty($c['specs'])): ?><section class="product-specs"><h2><?= fd_e($t('specs_title')) ?></h2><dl><?php foreach ($c['specs'] as $spec): ?><div><dt><?= fd_e($spec['label'] ?? '') ?></dt><dd><?= fd_e($spec['value'] ?? '') ?></dd></div><?php endforeach; ?></dl></section><?php endif; ?>
            </div>
            <div class="product-information"><p class="eyebrow" dir="ltr"><?= fd_e($brand) ?></p><h1><?= fd_e($name) ?></h1><?php if (!empty($c['summary'])): ?><p class="product-summary"><?= fd_e($c['summary']) ?></p><?php endif; ?>
                <?php if (!empty($product['flags'])): ?><ul class="product-flags" aria-label="<?= fd_e($t('features_title')) ?>"><?php foreach ($product['flags'] as $flag): ?><li><?= fd_public_icon((string)$flag) ?><span><?= fd_e($t('flag_' . $flag)) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if (!empty($c['features'])): ?><ul class="feature-list"><?php foreach ($c['features'] as $feature): ?><li><?= fd_public_icon('check') ?><?= fd_e($feature) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if ($sizes): ?>
                <form class="purchase-panel" action="<?= fd_e(fd_url('order.php')) ?>" method="post" data-order-form data-success="<?= fd_e($t('order_success')) ?>" data-error="<?= fd_e($t('order_error')) ?>" data-network-error="<?= fd_e($t('network_error')) ?>" data-sending="<?= fd_e($t('sending')) ?>" data-whatsapp-number="<?= fd_e($whatsapp) ?>" data-whatsapp-template="<?= fd_e($t('whatsapp_message')) ?>" data-product-name="<?= fd_e($name) ?>">
                    <input type="hidden" name="csrf" value="<?= fd_e(fd_csrf_token()) ?>"><input type="hidden" name="product_id" value="<?= fd_e($product['id']) ?>"><input type="hidden" name="lang" value="<?= fd_e($lang) ?>">
                    <div class="honeypot" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <fieldset class="size-fieldset"><legend><?= fd_e($t('choose_size')) ?></legend><div class="size-options"><?php foreach ($sizes as $index => $size): $displaySize = fd_public_size_label($data, $lang, $size['label']); ?><label class="size-option"><input type="radio" name="size_id" value="<?= fd_e($size['id']) ?>" data-price="<?= fd_e((string)$size['price']) ?>" data-size-label="<?= fd_e($lang === 'he' ? "\u{200E}" . $displaySize . "\u{200E}" : $displaySize) ?>" <?= $index === 0 ? 'checked' : '' ?> required><span class="size-option__label"><bdi dir="ltr"><?= fd_e($displaySize) ?></bdi><bdi><?= fd_e(fd_public_money($size['price'])) ?></bdi></span></label><?php endforeach; ?></div></fieldset>
                    <div class="purchase-total"><div class="field quantity-field"><label for="order-quantity"><?= fd_e($t('quantity')) ?></label><input id="order-quantity" name="quantity" type="number" min="1" max="20" step="1" value="1" inputmode="numeric" required></div><div class="total-price"><span><?= fd_e($t('total')) ?></span><strong data-order-total><bdi><?= fd_e(fd_public_money($sizes[0]['price'])) ?></bdi></strong></div></div>
                    <?php if (isset($settings['vat_included']) && is_bool($settings['vat_included'])): ?><p class="price-note"><?= fd_e($t($settings['vat_included'] ? 'vat_included' : 'vat_excluded')) ?></p><?php endif; ?>
                    <?php if (fd_public_localized($settings['delivery'] ?? '', $lang) !== ''): ?><p class="price-note"><?= fd_e(fd_public_localized($settings['delivery'], $lang)) ?></p><?php endif; ?>
                    <details class="order-details"><summary class="button button-dark"><?= fd_e($t('request_order')) ?><?= fd_public_icon('arrow', 'directional') ?></summary><div class="order-fields"><h2><?= fd_e($t('order_title')) ?></h2><p><?= fd_e($t('order_intro')) ?></p><div class="field"><label for="order-name"><?= fd_e($t('full_name')) ?> <span aria-hidden="true">*</span></label><input id="order-name" name="name" autocomplete="name" maxlength="100" required></div><div class="field"><label for="order-email"><?= fd_e($t('email')) ?> <span aria-hidden="true">*</span></label><input id="order-email" name="email" type="email" autocomplete="email" inputmode="email" dir="ltr" maxlength="254" required></div><div class="field"><label for="order-phone"><?= fd_e($t('phone')) ?></label><input id="order-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" dir="ltr" maxlength="40"></div><div class="field"><label for="order-message"><?= fd_e($t('message')) ?></label><textarea id="order-message" name="message" rows="3" maxlength="2000" aria-describedby="message-hint"></textarea><small id="message-hint"><?= fd_e($t('message_hint')) ?></small></div><label class="consent"><input type="checkbox" name="consent" value="1" required><span><?= fd_e($t('consent')) ?> <a href="<?= fd_e(fd_url($lang . '/privacy/')) ?>"><?= fd_e($t('privacy_link')) ?></a></span></label><button class="button button-dark order-submit" type="submit"><?= fd_e($t('send_order')) ?><?= fd_public_icon('arrow', 'directional') ?></button><p class="order-feedback" data-order-feedback role="status" aria-live="polite" hidden></p></div></details>
                    <?php if ($whatsapp !== ''): $waText = strtr($t('whatsapp_message'), ['{product}' => $name, '{size}' => (string)$sizes[0]['label'], '{quantity}' => '1']); ?><a class="button button-outline whatsapp-button" data-order-whatsapp href="<?= fd_e('https://wa.me/' . $whatsapp . '?text=' . rawurlencode($waText)) ?>" target="_blank" rel="noopener noreferrer"><?= fd_public_icon('chat') ?><?= fd_e($t('whatsapp_order')) ?></a><?php endif; ?>
                    <p class="order-note"><?= fd_e($t('order_note')) ?></p>
                </form>
                <?php else: ?><div class="purchase-panel"><p><?= fd_e($t('price_pending')) ?></p><a class="button button-dark" href="<?= fd_e($contactUrl) ?>"><?= fd_e($t('nav_contact')) ?><?= fd_public_icon('arrow', 'directional') ?></a></div><?php endif; ?>
            </div>
        </section>
    <?php elseif ($page === 'contact'): ?>
        <section class="page-heading container"><p class="eyebrow"><?= fd_e($t('contact_eyebrow')) ?></p><h1><?= fd_e((string)($contact['title'] ?? '') ?: $t('contact_title')) ?></h1><p><?= fd_e((string)($contact['intro'] ?? '') ?: $t('contact_intro')) ?></p></section>
        <section class="container contact-grid"><div class="contact-card"><h2><?= fd_e($t('contact_details')) ?></h2><div class="contact-methods">
            <?php if ($phone !== ''): ?><a class="contact-method" href="<?= fd_e('tel:' . preg_replace('/[^+\d]/', '', $phone)) ?>"><span class="contact-icon"><?= fd_public_icon('phone') ?></span><span><small><?= fd_e($t('contact_phone')) ?></small><bdi><?= fd_e($phone) ?></bdi></span><?= fd_public_icon('arrow', 'directional') ?></a><?php endif; ?>
            <?php if (!empty($settings['phone_secondary'])): ?><a class="contact-method" href="<?= fd_e('tel:' . preg_replace('/[^+\d]/', '', $settings['phone_secondary'])) ?>"><span class="contact-icon"><?= fd_public_icon('phone') ?></span><span><small><?= fd_e($t('contact_phone')) ?></small><bdi><?= fd_e($settings['phone_secondary']) ?></bdi></span><?= fd_public_icon('arrow', 'directional') ?></a><?php endif; ?>
            <?php if ($email !== ''): ?><a class="contact-method" href="<?= fd_e('mailto:' . $email) ?>"><span class="contact-icon"><?= fd_public_icon('mail') ?></span><span><small><?= fd_e($t('contact_email')) ?></small><bdi><?= fd_e($email) ?></bdi></span><?= fd_public_icon('arrow', 'directional') ?></a><?php endif; ?>
            <?php if ($whatsapp !== ''): ?><a class="contact-method" href="<?= fd_e('https://wa.me/' . $whatsapp) ?>" target="_blank" rel="noopener noreferrer"><span class="contact-icon"><?= fd_public_icon('chat') ?></span><span><small><?= fd_e($t('contact_whatsapp')) ?></small><bdi>WhatsApp</bdi></span><?= fd_public_icon('arrow', 'directional') ?></a><?php endif; ?>
            <?php if (!empty($settings['fax'])): ?><div class="contact-method"><span class="contact-icon"><?= fd_public_icon('fax') ?></span><span><small><?= fd_e($t('contact_fax')) ?></small><bdi><?= fd_e($settings['fax']) ?></bdi></span></div><?php endif; ?>
            <?php if ($phone === '' && $email === '' && $whatsapp === '' && empty($settings['fax'])): ?><p class="muted"><?= fd_e($t('contact_pending')) ?></p><?php endif; ?>
        </div></div><div class="contact-information">
            <?php foreach ([['address','pin','contact_address'],['hours','clock','contact_hours'],['delivery','truck','contact_delivery']] as $info): $value = fd_public_localized($settings[$info[0]] ?? '', $lang); if ($value === '') continue; ?><div class="contact-info-row"><?= fd_public_icon($info[1]) ?><div><h2><?= fd_e($t($info[2])) ?></h2><p><?= nl2br(fd_e($value)) ?></p></div></div><?php endforeach; ?>
            <div class="contact-explore"><span class="contact-explore__moon"><?= fd_public_icon('moon') ?></span><h2><?= fd_e($t('contact_catalog')) ?></h2><p><?= fd_e($t('contact_catalog_body')) ?></p><a class="text-link" href="<?= fd_e($catalogUrl) ?>"><?= fd_e($t('hero_catalog')) ?><?= fd_public_icon('arrow', 'directional') ?></a></div>
        </div></section>
    <?php elseif (in_array($page, ['privacy', 'accessibility'], true)): $legalTitle = $page === 'privacy' ? 'privacy_title' : 'accessibility_page_title'; $legalBody = $page === 'privacy' ? 'privacy_body' : 'accessibility_page_body'; ?>
        <section class="container legal-page"><p class="eyebrow"><?= fd_e($t($page === 'privacy' ? 'privacy_intro' : 'accessibility')) ?></p><h1><?= fd_e($t($legalTitle)) ?></h1><p><?= nl2br(fd_e($t($legalBody))) ?></p><?php if ($email !== ''): ?><a class="text-link" href="<?= fd_e('mailto:' . $email) ?>"><?= fd_e($t('contact_email')) ?><?= fd_public_icon('arrow', 'directional') ?></a><?php endif; ?></section>
    <?php else: ?>
        <section class="container legal-page"><p class="eyebrow" dir="ltr">404</p><h1><?= fd_e($t('not_found_title')) ?></h1><p><?= fd_e($t('not_found_body')) ?></p><a class="button button-dark" href="<?= fd_e($catalogUrl) ?>"><?= fd_e($t('hero_catalog')) ?><?= fd_public_icon('arrow', 'directional') ?></a></section>
    <?php endif; ?>
    </main>
    <footer class="site-footer"><div class="container footer-main"><div class="footer-brand"><a class="brand" href="<?= fd_e(fd_url($lang . '/')) ?>"><span class="brand-mark"><?= fd_public_icon('moon') ?></span><span class="brand-wordmark" dir="ltr">Fantasy<span>Dream</span></span></a><p><?= fd_e($t('footer_tagline')) ?></p></div><div><h2><?= fd_e($t('footer_navigation')) ?></h2><nav class="footer-nav"><a href="<?= fd_e(fd_url($lang . '/')) ?>"><?= fd_e($t('nav_home')) ?></a><a href="<?= fd_e($catalogUrl) ?>"><?= fd_e($t('nav_catalog')) ?></a><a href="<?= fd_e($contactUrl) ?>"><?= fd_e($t('nav_contact')) ?></a></nav></div><div><h2><?= fd_e($t('footer_contact')) ?></h2><div class="footer-nav"><?php if ($phone !== ''): ?><a href="<?= fd_e('tel:' . preg_replace('/[^+\d]/', '', $phone)) ?>"><bdi><?= fd_e($phone) ?></bdi></a><?php endif; ?><?php if ($email !== ''): ?><a href="<?= fd_e('mailto:' . $email) ?>"><bdi><?= fd_e($email) ?></bdi></a><?php endif; ?><?php if ($phone === '' && $email === ''): ?><a href="<?= fd_e($contactUrl) ?>"><?= fd_e($t('nav_contact')) ?></a><?php endif; ?></div></div></div><div class="container footer-bottom"><p><bdi>© <?= date('Y') ?> <?= fd_e($brand) ?>.</bdi> <?= fd_e($t('footer_copyright')) ?></p><div><a href="<?= fd_e(fd_url($lang . '/privacy/')) ?>"><?= fd_e($t('privacy_link')) ?></a><a href="<?= fd_e(fd_url($lang . '/accessibility/')) ?>"><?= fd_e($t('accessibility_page_title')) ?></a><button type="button" class="footer-accessibility" data-accessibility-open aria-controls="accessibility-panel" aria-expanded="false"><?= fd_public_icon('accessibility') ?><?= fd_e($t('accessibility')) ?></button></div></div></footer>
    <div class="accessibility-panel" id="accessibility-panel" role="dialog" aria-modal="true" aria-labelledby="accessibility-title" tabindex="-1" hidden><div class="accessibility-panel__header"><h2 id="accessibility-title"><?= fd_e($t('accessibility_title')) ?></h2><button type="button" data-accessibility-close aria-label="<?= fd_e($t('close')) ?>"><?= fd_public_icon('close') ?></button></div><p><?= fd_e($t('accessibility_intro')) ?></p><label class="accessibility-toggle"><span><?= fd_e($t('a11y_contrast')) ?></span><input type="checkbox" data-accessibility-setting="contrast"></label><label class="accessibility-toggle"><span><?= fd_e($t('a11y_large')) ?></span><input type="checkbox" data-accessibility-setting="large"></label><label class="accessibility-toggle"><span><?= fd_e($t('a11y_motion')) ?></span><input type="checkbox" data-accessibility-setting="motion"></label><button type="button" class="text-link" data-accessibility-reset><?= fd_e($t('a11y_reset')) ?></button></div>
    <div class="panel-backdrop" data-panel-backdrop hidden></div>
    <?php
}
