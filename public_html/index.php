<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function fd_t(array $data, string $lang, string $key): string
{
    return (string) ($data['translations'][$lang][$key] ?? $data['translations']['en'][$key] ?? $key);
}

function fd_absolute(string $path): string
{
    $base = rtrim((string) (fd_config()['site_url'] ?? ''), '/');
    return $base === '' ? '' : $base . '/' . ltrim($path, '/');
}

function fd_public_products(array $data): array
{
    $products = array_values(array_filter($data['products'] ?? [], static fn(array $p): bool => !empty($p['visible'])));
    usort($products, static fn(array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
    return $products;
}

function fd_public_routes(array $data): array
{
    $routes = ['' => 'home', 'catalog/' => 'catalog', 'contact/' => 'contact', 'privacy/' => 'privacy', 'accessibility/' => 'accessibility'];
    foreach (fd_public_products($data) as $product) $routes['product/' . $product['slug'] . '/'] = 'product';
    return $routes;
}

fd_security_headers();
$config = fd_config();
$rawPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if (str_starts_with((string) ($config['site_url'] ?? ''), 'https://') && !fd_request_secure()) {
    $origin = fd_origin_of((string) $config['site_url']);
    header('Location: ' . $origin . $rawPath, true, 301); exit;
}
$base = fd_base_path();
if ($base !== '' && ($rawPath === $base || str_starts_with($rawPath, $base . '/'))) $rawPath = substr($rawPath, strlen($base));
$path = trim($rawPath, '/');
if (strlen($path) > 500 || preg_match('/[\\\\\x00-\x1f]/', $path) || str_contains($path, '..')) {
    http_response_code(400); exit('Invalid request.');
}
$adminPath = (string) ($config['admin_path'] ?? '');
if ($adminPath !== '' && $path === $adminPath) {
    fd_session(); fd_security_headers(true);
    require FD_PRIVATE_DIR . '/admin.php';
    fd_admin_render(); exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405); header('Allow: GET, HEAD'); exit('Method not allowed.');
}
$data = fd_data();
$data['products'] = fd_public_products($data);
if ($path === 'robots.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "User-agent: *\nDisallow: /setup.php\nDisallow: /order.php\n";
    if (fd_absolute('sitemap.xml') !== '') echo 'Sitemap: ' . fd_absolute('sitemap.xml') . "\n";
    exit;
}
if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
    if (fd_absolute('') !== '') foreach (array_keys(fd_public_routes($data)) as $route) foreach (['he', 'ru', 'en'] as $lang) {
        echo '<url><loc>' . fd_e(fd_absolute($lang . '/' . $route)) . '</loc>';
        foreach (['he', 'ru', 'en'] as $alt) echo '<xhtml:link rel="alternate" hreflang="' . $alt . '" href="' . fd_e(fd_absolute($alt . '/' . $route)) . '"/>';
        echo "</url>\n";
    }
    echo '</urlset>'; exit;
}
if ($path === 'llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# FantasyDream\n\n> Mattress catalogue in Hebrew (primary), Russian and English. Prices are in ILS. No online payment. Requests are confirmed by the business.\n\n## Catalogue\n";
    foreach (['he', 'ru', 'en'] as $lang) echo '- [' . $lang . ' catalogue](' . fd_absolute($lang . '/catalog/') . ")\n";
    foreach ($data['products'] as $p) echo '- [' . str_replace(["\r", "\n", '[', ']'], '', (string) $p['content']['en']['name']) . '](' . fd_absolute('en/product/' . $p['slug'] . '/') . "): model specifications and size-specific prices.\n";
    echo "\n## Contact\n" . (string) ($data['settings']['email'] ?? '') . "\n" . (string) ($data['settings']['phone'] ?? '') . "\n\nAvailability, delivery and tax treatment must be confirmed directly. Manufacturer statements are not independent medical certification.\n";
    exit;
}
if ($path === '') { header('Location: ' . fd_url('he/'), true, 301); exit; }
$parts = explode('/', $path);
$lang = in_array($parts[0] ?? '', ['he', 'ru', 'en'], true) ? array_shift($parts) : 'he';
$route = implode('/', $parts);
$page = '404'; $product = null;
if ($route === '') $page = 'home';
elseif ($route === 'catalog') $page = 'catalog';
elseif ($route === 'contact') $page = 'contact';
elseif ($route === 'privacy') $page = 'privacy';
elseif ($route === 'accessibility') $page = 'accessibility';
elseif (count($parts) === 2 && $parts[0] === 'product') {
    foreach ($data['products'] as $p) if ($p['slug'] === $parts[1]) { $product = $p; $page = 'product'; break; }
}
// Only explicit language routes exist; never mirror unknown URLs into indexable pages.
if (!preg_match('#^(he|ru|en)(/|$)#', $path)) $page = '404';
if ($page === '404') { http_response_code(404); header('X-Robots-Tag: noindex'); }
elseif (!str_ends_with($rawPath, '/')) {
    header('Location: ' . fd_url($path . '/'), true, 301); exit;
}
fd_session();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store'); // Forms contain a per-session CSRF token.
$brand = (string) ($data['settings']['brand'] ?? 'FantasyDream');
$title = match ($page) {
    'home' => ($data['home'][$lang]['seo_title'] ?? '') ?: fd_t($data, $lang, 'seo_home_title'),
    'catalog' => fd_t($data, $lang, 'seo_catalog_title'),
    'contact' => ($data['contact'][$lang]['seo_title'] ?? '') ?: fd_t($data, $lang, 'seo_contact_title'),
    'product' => ($product['content'][$lang]['seo_title'] ?? '') ?: $product['content'][$lang]['name'] . ' | ' . $brand,
    'privacy' => fd_t($data, $lang, 'privacy_title') . ' | ' . $brand,
    'accessibility' => fd_t($data, $lang, 'accessibility_page_title') . ' | ' . $brand,
    default => fd_t($data, $lang, 'not_found_title') . ' | ' . $brand,
};
$description = match ($page) {
    'home' => ($data['home'][$lang]['seo_description'] ?? '') ?: fd_t($data, $lang, 'seo_home_description'),
    'catalog' => fd_t($data, $lang, 'seo_catalog_description'),
    'contact' => ($data['contact'][$lang]['seo_description'] ?? '') ?: fd_t($data, $lang, 'seo_contact_description'),
    'product' => ($product['content'][$lang]['seo_description'] ?? '') ?: ($product['content'][$lang]['summary'] ?? ''),
    default => '',
};
$suffix = match ($page) { 'home' => '', 'product' => 'product/' . $product['slug'] . '/', '404' => '', default => $page . '/' };
$canonical = fd_absolute($lang . '/' . $suffix);
$imagePath = $product['images'][0]['src'] ?? $data['settings']['logo'] ?? 'media/fantasydream-logo.webp';
$socialImage = fd_absolute((string) $imagePath);
$schema = [];
if ($canonical !== '' && $page !== '404') {
    $organization = ['@type' => 'Organization', '@id' => fd_absolute('') . '#organization', 'name' => $brand, 'url' => fd_absolute(''), 'logo' => fd_absolute($data['settings']['logo'] ?? 'media/fantasydream-logo.webp')];
    foreach (['email' => 'email', 'phone' => 'telephone', 'fax' => 'faxNumber'] as $field => $property) if (!empty($data['settings'][$field])) $organization[$property] = $data['settings'][$field];
    if (!empty($data['settings']['address'][$lang])) $organization['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $data['settings']['address'][$lang]];
    $schema[] = $organization;
    $schema[] = ['@type' => 'WebSite', '@id' => fd_absolute('') . '#website', 'name' => $brand, 'url' => fd_absolute(''), 'inLanguage' => ['he', 'ru', 'en'], 'publisher' => ['@id' => $organization['@id']]];
    if ($page === 'product') {
        $item = ['@type' => 'Product', '@id' => $canonical . '#product', 'url' => $canonical, 'name' => $product['content'][$lang]['name'], 'description' => $product['content'][$lang]['description'], 'brand' => ['@type' => 'Brand', 'name' => $brand], 'sku' => $product['id']];
        if (!empty($product['images'])) $item['image'] = array_map(static fn(array $i): string => fd_absolute((string) $i['src']), $product['images']);
        if (!empty($product['content'][$lang]['specs'])) $item['additionalProperty'] = array_map(static fn(array $s): array => ['@type' => 'PropertyValue', 'name' => $s['label'], 'value' => $s['value']], $product['content'][$lang]['specs']);
        if (!empty($product['sizes'])) {
            $prices = array_column($product['sizes'], 'price');
            $item['offers'] = ['@type' => 'AggregateOffer', 'priceCurrency' => 'ILS', 'lowPrice' => min($prices), 'highPrice' => max($prices), 'offerCount' => count($prices), 'url' => $canonical, 'offers' => array_map(static fn(array $s): array => ['@type' => 'Offer', 'name' => $s['label'], 'price' => $s['price'], 'priceCurrency' => 'ILS', 'url' => $canonical, 'seller' => ['@id' => $organization['@id']]], $product['sizes'])];
        }
        $schema[] = $item;
        $schema[] = ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => fd_t($data, $lang, 'breadcrumb_home'), 'item' => fd_absolute($lang . '/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => fd_t($data, $lang, 'breadcrumb_catalog'), 'item' => fd_absolute($lang . '/catalog/')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $product['content'][$lang]['name'], 'item' => $canonical],
        ]];
    } elseif ($page === 'catalog') {
        $schema[] = ['@type' => 'ItemList', 'itemListElement' => array_map(static fn(int $index, array $p): array => ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $p['content'][$lang]['name'], 'url' => fd_absolute($lang . '/product/' . $p['slug'] . '/')], array_keys($data['products']), $data['products'])];
    }
}
?><!doctype html>
<html lang="<?= fd_e($lang) ?>" dir="<?= $lang === 'he' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f5f1e8">
<title><?= fd_e($title) ?></title>
<?php if ($description !== ''): ?><meta name="description" content="<?= fd_e($description) ?>"><?php endif ?>
<?php if ($page !== '404' && $canonical !== ''): ?>
<link rel="canonical" href="<?= fd_e($canonical) ?>">
<?php foreach (['he', 'ru', 'en'] as $alternate): ?><link rel="alternate" hreflang="<?= $alternate ?>" href="<?= fd_e(fd_absolute($alternate . '/' . $suffix)) ?>"><?php endforeach ?>
<link rel="alternate" hreflang="x-default" href="<?= fd_e(fd_absolute('he/' . $suffix)) ?>">
<meta property="og:url" content="<?= fd_e($canonical) ?>">
<?php endif ?>
<meta property="og:type" content="<?= $page === 'product' ? 'product' : 'website' ?>">
<meta property="og:site_name" content="<?= fd_e($brand) ?>">
<meta property="og:title" content="<?= fd_e($title) ?>">
<meta property="og:description" content="<?= fd_e($description) ?>">
<meta property="og:locale" content="<?= ['he' => 'he_IL', 'ru' => 'ru_RU', 'en' => 'en_US'][$lang] ?>">
<?php if ($socialImage !== ''): ?><meta property="og:image" content="<?= fd_e($socialImage) ?>"><?php endif ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= fd_e(fd_url('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= fd_e(fd_url('assets/site.css')) ?>?v=1">
<script src="<?= fd_e(fd_url('assets/site.js')) ?>?v=1" defer></script>
<?php if ($schema): ?><script type="application/ld+json" nonce="<?= fd_e(fd_csp_nonce()) ?>"><?= json_encode(['@context' => 'https://schema.org', '@graph' => $schema], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script><?php endif ?>
</head><body>
<?php require FD_PRIVATE_DIR . '/views/public.php'; fd_render_public($data, $lang, $page, $product); ?>
</body></html>
