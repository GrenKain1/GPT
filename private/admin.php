<?php
declare(strict_types=1);

/** The administration entry point is routed by index.php through the private config. */
function fd_admin_languages(): array
{
    return ['he' => 'Hebrew', 'ru' => 'Russian', 'en' => 'English'];
}

function fd_admin_flags(): array
{
    return ['hypoallergenic' => 'Hypoallergenic', 'breathable' => 'Breathable', 'washable_cover' => 'Washable cover', 'no_flip' => 'No flipping required', 'no_ironing' => 'Do not iron', 'orthopedic' => 'Orthopedic', 'warranty' => 'Warranty'];
}

function fd_admin_path(string $page = 'dashboard', string $id = ''): string
{
    $base = fd_url('/' . trim((string) fd_config()['admin_path'], '/') . '/');
    return $base . ($page === 'dashboard' ? '' : '?' . http_build_query(['page' => $page] + ($id !== '' ? ['id' => $id] : [])));
}

function fd_admin_redirect(string $page = 'dashboard', string $id = '', string $notice = ''): never
{
    if ($notice !== '') {
        $_SESSION['fd_admin_notice'] = $notice;
    }
    header('Location: ' . fd_admin_path($page, $id), true, 303);
    exit;
}

function fd_admin_text(mixed $value, int $limit = 500, bool $multiline = false): string
{
    if (!is_string($value) && !is_numeric($value) && $value !== null) {
        throw new InvalidArgumentException('A text field has an invalid format.');
    }
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    if (strlen($text) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
        throw new InvalidArgumentException('A text field is too long or contains unsupported characters.');
    }
    if (!$multiline && str_contains($text, "\n")) {
        throw new InvalidArgumentException('Use a single line for titles and labels.');
    }
    if (!preg_match('//u', $text)) {
        throw new InvalidArgumentException('Text must use UTF-8 encoding.');
    }
    return $text;
}

function fd_admin_product_index(array $data, string $id): ?int
{
    foreach (($data['products'] ?? []) as $index => $product) {
        if (is_array($product) && hash_equals((string) ($product['id'] ?? ''), $id)) {
            return $index;
        }
    }
    return null;
}

function fd_admin_product_new(): array
{
    $localized = [];
    foreach (fd_admin_languages() as $lang => $_label) {
        $localized[$lang] = ['name' => '', 'summary' => '', 'description' => '', 'features' => [], 'specs' => [], 'seo_title' => '', 'seo_description' => ''];
    }
    return ['id' => bin2hex(random_bytes(12)), 'slug' => '', 'visible' => false, 'order' => 0, 'images' => [], 'sizes' => [], 'flags' => [], 'content' => $localized];
}

/** Decode and re-encode raster images; a supplied filename is never used as a path. */
function fd_admin_upload(array $file): ?string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The image upload failed. Please try again with a smaller image.');
    }
    if (($file['size'] ?? 0) < 1 || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Each image must be smaller than 5 MB.');
    }
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('Secure image uploads require the PHP GD extension. Ask your host to enable GD.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new InvalidArgumentException('Upload a JPEG, PNG or WebP image. SVG and other file types are not accepted.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 16000000) {
        throw new InvalidArgumentException('Image dimensions must be at most 6,000 pixels per side and 16 megapixels.');
    }
    $memorySetting = trim((string) ini_get('memory_limit'));
    if (preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $memorySetting, $memoryParts)) {
        $memoryPower = match (strtoupper($memoryParts[2])) {'K' => 1, 'M' => 2, 'G' => 3, default => 0};
        $memoryLimit = (int) $memoryParts[1] * (1024 ** $memoryPower);
        $pixels = $info[0] * $info[1];
        $resizedPixels = $pixels * min(1.0, 1800 / max($info[0], $info[1])) ** 2;
        $estimatedMemory = memory_get_usage(true) + $pixels * 8 + $resizedPixels * 9 + (int) $file['size'] + 16 * 1024 * 1024;
        if ($estimatedMemory > $memoryLimit) {
            throw new InvalidArgumentException('This image needs more memory than your host provides. Resize it to 1,800 pixels on its longest side and upload it again.');
        }
    }
    $bytes = file_get_contents($file['tmp_name']);
    $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
    if ($source === false) {
        throw new InvalidArgumentException('The image could not be decoded. Export it again as JPEG, PNG or WebP.');
    }
    $width = imagesx($source);
    $height = imagesy($source);
    $ratio = min(1.0, 1800 / max($width, $height));
    $target = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
    if ($target === false) {
        imagedestroy($source);
        throw new RuntimeException('The image could not be processed.');
    }
    $preserveAlpha = $mime !== 'image/jpeg';
    if ($preserveAlpha) {
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefilledrectangle($target, 0, 0, imagesx($target), imagesy($target), imagecolorallocatealpha($target, 0, 0, 0, 127));
    }
    imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
    $directory = FD_PUBLIC_DIR . '/media/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        imagedestroy($source);
        imagedestroy($target);
        throw new RuntimeException('The uploads directory is not writable.');
    }
    $extension = function_exists('imagewebp') ? 'webp' : ($preserveAlpha ? 'png' : 'jpg');
    $name = bin2hex(random_bytes(20)) . '.' . $extension;
    $path = $directory . '/' . $name;
    $written = match ($extension) {
        'webp' => imagewebp($target, $path, 83),
        'png' => imagepng($target, $path, 7),
        default => imagejpeg($target, $path, 86),
    };
    $fallbackPath = null;
    if ($written && $extension === 'webp') {
        // Older iOS and Android versions need a JPEG alternative to WebP.
        $fallbackPath = $directory . '/' . substr($name, 0, -5) . '.jpg';
        $fallback = imagecreatetruecolor(imagesx($target), imagesy($target));
        if ($fallback !== false) {
            imagefilledrectangle($fallback, 0, 0, imagesx($fallback), imagesy($fallback), imagecolorallocate($fallback, 255, 255, 255));
            imagealphablending($fallback, true);
            imagecopy($fallback, $target, 0, 0, 0, 0, imagesx($target), imagesy($target));
            $written = imagejpeg($fallback, $fallbackPath, 86);
            imagedestroy($fallback);
        } else {
            $written = false;
        }
    }
    imagedestroy($source);
    imagedestroy($target);
    if (!$written) {
        @unlink($path);
        if ($fallbackPath !== null) {
            @unlink($fallbackPath);
        }
        throw new RuntimeException('The uploads directory is not writable.');
    }
    chmod($path, 0644);
    if ($fallbackPath !== null) {
        chmod($fallbackPath, 0644);
    }
    return '/media/uploads/' . $name;
}

function fd_admin_upload_files(mixed $files): array
{
    if (!is_array($files) || !is_array($files['error'] ?? null)) {
        return [];
    }
    if (count($files['error']) > 12) {
        throw new InvalidArgumentException('Upload no more than 12 images at once.');
    }
    $normalized = [];
    foreach ($files['error'] as $index => $error) {
        $normalized[] = ['error' => $error, 'tmp_name' => $files['tmp_name'][$index] ?? '', 'size' => $files['size'][$index] ?? 0];
    }
    return $normalized;
}

/** Remove only our randomly named uploads, and only when no content still refers to them. */
function fd_admin_cleanup_uploads(array $paths, array $data): void
{
    $references = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach (array_unique($paths) as $path) {
        if (!is_string($path) || !preg_match('~^/media/uploads/[a-f0-9]{40}\.(webp|png|jpg)$~D', $path)) {
            continue;
        }
        $candidates = [$path];
        if (str_ends_with($path, '.webp')) {
            $candidates[] = substr($path, 0, -5) . '.jpg';
        }
        foreach ($candidates as $candidate) {
            $webpSibling = str_ends_with($candidate, '.jpg') ? substr($candidate, 0, -4) . '.webp' : null;
            if (is_string($references) && (str_contains($references, $candidate) || ($webpSibling !== null && str_contains($references, $webpSibling)))) {
                continue;
            }
            $file = FD_PUBLIC_DIR . $candidate;
            if (is_file($file) && !is_link($file)) {
                @unlink($file);
            }
        }
    }
}

function fd_admin_update_product(array &$data, array $post, array &$newUploads, array &$oldUploads): string
{
    $id = fd_admin_text($post['id'] ?? '', 48);
    $index = fd_admin_product_index($data, $id);
    if ($id !== '' && $index === null) {
        throw new InvalidArgumentException('This product no longer exists. Reload the catalog.');
    }
    $product = $index === null ? fd_admin_product_new() : $data['products'][$index];
    $slug = fd_admin_text($post['slug'] ?? '', 100);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
        throw new InvalidArgumentException('The URL name must contain lowercase English letters, numbers and single hyphens.');
    }
    foreach ($data['products'] ?? [] as $other) {
        if (($other['id'] ?? '') !== $product['id'] && ($other['slug'] ?? '') === $slug) {
            throw new InvalidArgumentException('Another product already uses this URL name.');
        }
    }
    $product['slug'] = $slug;
    $product['visible'] = isset($post['visible']);
    $product['order'] = max(0, min(100000, (int) ($post['order'] ?? 0)));
    $product['flags'] = [];
    foreach (fd_admin_flags() as $flag => $_label) {
        if (in_array($flag, (array) ($post['flags'] ?? []), true)) {
            $product['flags'][] = $flag;
        }
    }
    foreach (fd_admin_languages() as $lang => $_label) {
        $localized = $post['content'][$lang] ?? [];
        if (!is_array($localized)) {
            throw new InvalidArgumentException('Product text has an invalid format.');
        }
        $content = is_array($product['content'][$lang] ?? null) ? $product['content'][$lang] : [];
        foreach (['name' => 250, 'summary' => 1600, 'description' => 24000, 'seo_title' => 250, 'seo_description' => 1000] as $field => $limit) {
            $content[$field] = fd_admin_text($localized[$field] ?? '', $limit, in_array($field, ['summary', 'description'], true));
        }
        $features = fd_admin_text($localized['features'] ?? '', 10000, true);
        $content['features'] = array_values(array_filter(array_map('trim', explode("\n", $features)), static fn ($v) => $v !== ''));
        if (count($content['features']) > 40) {
            throw new InvalidArgumentException('Use no more than 40 features per language.');
        }
        $content['specs'] = [];
        $specs = $localized['specs'] ?? [];
        if (!is_array($specs) || count($specs) > 60) {
            throw new InvalidArgumentException('Use no more than 60 specification rows per language.');
        }
        foreach ($specs as $row) {
            if (!is_array($row) || isset($row['remove'])) {
                continue;
            }
            $label = fd_admin_text($row['label'] ?? '', 250);
            $value = fd_admin_text($row['value'] ?? '', 1200, true);
            if ($label !== '' || $value !== '') {
                if ($label === '' || $value === '') {
                    throw new InvalidArgumentException('Fill both the label and value of every specification.');
                }
                $content['specs'][] = ['label' => $label, 'value' => $value];
            }
        }
        $product['content'][$lang] = $content;
    }
    $sizes = $post['sizes'] ?? [];
    if (!is_array($sizes) || count($sizes) > 100) {
        throw new InvalidArgumentException('Use no more than 100 size rows.');
    }
    $existingSizeIds = [];
    foreach ($product['sizes'] ?? [] as $size) {
        $existingSizeIds[(string) ($size['id'] ?? '')] = true;
    }
    $product['sizes'] = [];
    $usedSizeIds = [];
    foreach ($sizes as $row) {
        if (!is_array($row) || isset($row['remove'])) {
            continue;
        }
        $label = fd_admin_text($row['label'] ?? '', 160);
        $rawPrice = fd_admin_text($row['price'] ?? '', 20);
        if ($label === '' && $rawPrice === '') {
            continue;
        }
        if ($label === '' || !preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,2})?$/D', $rawPrice) || (float) $rawPrice <= 0) {
            throw new InvalidArgumentException('Each size needs a label and a positive ILS price with at most two decimal places.');
        }
        $sizeId = fd_admin_text($row['id'] ?? '', 48);
        if (!isset($existingSizeIds[$sizeId]) || isset($usedSizeIds[$sizeId])) {
            $sizeId = 's-' . bin2hex(random_bytes(8));
        }
        $usedSizeIds[$sizeId] = true;
        $product['sizes'][] = ['id' => $sizeId, 'label' => $label, 'price' => round((float) $rawPrice, 2)];
    }
    if ($product['content']['he']['name'] === '') {
        throw new InvalidArgumentException('Enter a Hebrew product name.');
    }
    if ($product['visible'] && $product['sizes'] === []) {
        throw new InvalidArgumentException('A published product needs at least one size and price.');
    }
    $images = [];
    foreach ($product['images'] ?? [] as $imageIndex => $original) {
        $row = $post['images'][$imageIndex] ?? [];
        if (!is_array($row)) {
            throw new InvalidArgumentException('Image details have an invalid format.');
        }
        if (isset($row['remove'])) {
            $oldUploads[] = $original['src'] ?? '';
            continue;
        }
        foreach (fd_admin_languages() as $lang => $_label) {
            $original['alt'][$lang] = fd_admin_text($row['alt'][$lang] ?? ($original['alt'][$lang] ?? ''), 500);
        }
        $original['_admin_order'] = max(0, min(10000, (int) ($row['order'] ?? $imageIndex)));
        $images[] = $original;
    }
    usort($images, static fn ($a, $b) => $a['_admin_order'] <=> $b['_admin_order']);
    foreach ($images as &$image) {
        unset($image['_admin_order']);
    }
    unset($image);
    $files = fd_admin_upload_files($_FILES['product_images'] ?? []);
    if (count($images) + count(array_filter($files, static fn ($f) => $f['error'] !== UPLOAD_ERR_NO_FILE)) > 12) {
        throw new InvalidArgumentException('A product can have no more than 12 gallery images.');
    }
    foreach ($files as $file) {
        $path = fd_admin_upload($file);
        if ($path !== null) {
            $newUploads[] = $path;
            $images[] = ['src' => $path, 'alt' => ['he' => $product['content']['he']['name'], 'ru' => $product['content']['ru']['name'], 'en' => $product['content']['en']['name']]];
        }
    }
    $product['images'] = $images;
    if ($index === null) {
        if (count($data['products'] ?? []) >= 2000) {
            throw new InvalidArgumentException('The catalog has reached its supported limit of 2,000 products.');
        }
        $data['products'][] = $product;
    } else {
        $data['products'][$index] = $product;
    }
    return $product['id'];
}

function fd_admin_save_page(array &$data, string $section, array $post, array &$newUploads, array &$oldUploads): void
{
    $fields = $section === 'home'
        ? ['eyebrow' => 300, 'title' => 500, 'intro' => 6000, 'about_title' => 500, 'about_body' => 18000, 'hero_alt' => 500, 'seo_title' => 250, 'seo_description' => 1000]
        : ['title' => 500, 'intro' => 12000, 'seo_title' => 250, 'seo_description' => 1000];
    foreach (fd_admin_languages() as $lang => $_label) {
        $localized = $post[$section][$lang] ?? [];
        if (!is_array($localized)) {
            throw new InvalidArgumentException('Page text has an invalid format.');
        }
        foreach ($fields as $field => $limit) {
            $data[$section][$lang][$field] = fd_admin_text($localized[$field] ?? '', $limit, in_array($field, ['intro', 'about_body'], true));
        }
        if ($section === 'home') {
            $file = $_FILES['hero_image_' . $lang] ?? [];
            if (isset($localized['remove_hero'])) {
                $oldUploads[] = $data['home'][$lang]['hero_image'] ?? '';
                $data['home'][$lang]['hero_image'] = '';
            }
            if ($file !== [] && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $path = fd_admin_upload($file);
                if ($path !== null) {
                    $oldUploads[] = $data['home'][$lang]['hero_image'] ?? '';
                    $newUploads[] = $path;
                    $data['home'][$lang]['hero_image'] = $path;
                }
            }
        }
    }
}

function fd_admin_post(array &$data): void
{
    if (!fd_origin_ok() || !fd_csrf_check($_POST['csrf'] ?? null)) {
        throw new InvalidArgumentException('Your form expired or its origin could not be verified. Reload this page and try again.');
    }
    $action = fd_admin_text($_POST['action'] ?? '', 48);
    if ($action === 'login') {
        if (!fd_request_secure()) {
            throw new RuntimeException('Sign in using HTTPS.');
        }
        $password = $_POST['password'] ?? '';
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Sign-in failed. Check your username and password, or wait before trying again.');
        }
        if (fd_login(fd_admin_text($_POST['username'] ?? '', 160), $password)) {
            fd_admin_redirect('dashboard', '', 'Welcome back.');
        }
        throw new InvalidArgumentException('Sign-in failed. Check your username and password, or wait before trying again.');
    }
    if (!fd_is_admin()) {
        throw new InvalidArgumentException('Please sign in again before making changes.');
    }
    if ($action === 'logout') {
        fd_logout();
        fd_admin_redirect();
    }
    if (in_array($action, ['save_mail_settings', 'save_hosting_settings', 'change_password', 'send_test_email'], true)) {
        fd_admin_config_post($action, $data);
        return;
    }
    $newUploads = [];
    $oldUploads = [];
    try {
        $page = 'dashboard';
        $id = '';
        switch ($action) {
            case 'save_product':
                $id = fd_admin_update_product($data, $_POST, $newUploads, $oldUploads);
                $page = 'product';
                break;
            case 'delete_product':
                $index = fd_admin_product_index($data, fd_admin_text($_POST['id'] ?? '', 48));
                if ($index === null) {
                    throw new InvalidArgumentException('The product was not found.');
                }
                foreach ($data['products'][$index]['images'] ?? [] as $image) {
                    $oldUploads[] = $image['src'] ?? '';
                }
                array_splice($data['products'], $index, 1);
                $page = 'products';
                break;
            case 'save_catalog_order':
                foreach ($data['products'] ?? [] as $index => $product) {
                    $row = $_POST['products'][$product['id']] ?? null;
                    if (is_array($row)) {
                        $data['products'][$index]['order'] = max(0, min(100000, (int) ($row['order'] ?? $product['order'] ?? 0)));
                        $data['products'][$index]['visible'] = isset($row['visible']);
                        if ($data['products'][$index]['visible'] && (($product['sizes'] ?? []) === [] || ($product['content']['he']['name'] ?? '') === '')) {
                            throw new InvalidArgumentException('Complete the Hebrew name and at least one size before publishing a product.');
                        }
                    }
                }
                $page = 'products';
                break;
            case 'save_home':
            case 'save_contact':
                $page = $action === 'save_home' ? 'home' : 'contact';
                fd_admin_save_page($data, $page, $_POST, $newUploads, $oldUploads);
                break;
            case 'save_settings':
                $settings = $_POST['settings'] ?? [];
                if (!is_array($settings)) {
                    throw new InvalidArgumentException('Business details have an invalid format.');
                }
                foreach (['brand' => 100, 'email' => 254, 'phone' => 60, 'phone_secondary' => 60, 'whatsapp' => 30, 'fax' => 60] as $field => $limit) {
                    $data['settings'][$field] = fd_admin_text($settings[$field] ?? '', $limit);
                }
                if ($data['settings']['brand'] === '') {
                    throw new InvalidArgumentException('Enter the business name.');
                }
                if ($data['settings']['email'] !== '' && !filter_var($data['settings']['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid email address.');
                }
                foreach (['phone', 'phone_secondary', 'fax'] as $field) {
                    if ($data['settings'][$field] !== '' && !preg_match('/^[+0-9().\s-]{5,60}$/D', $data['settings'][$field])) {
                        throw new InvalidArgumentException('Telephone and fax fields may contain digits, a plus sign, spaces, parentheses, dots or hyphens.');
                    }
                }
                $data['settings']['whatsapp'] = preg_replace('/[\s()+.-]/', '', $data['settings']['whatsapp']);
                if ($data['settings']['whatsapp'] !== '' && !preg_match('/^[1-9][0-9]{7,14}$/D', $data['settings']['whatsapp'])) {
                    throw new InvalidArgumentException('Use the international WhatsApp number, for example country code followed by the telephone number.');
                }
                $vatStatus = $settings['vat_status'] ?? 'unknown';
                if (!in_array($vatStatus, ['unknown', 'included', 'excluded'], true)) {
                    throw new InvalidArgumentException('Select the correct VAT status.');
                }
                $data['settings']['vat_included'] = match ($vatStatus) {'included' => true, 'excluded' => false, default => null};
                foreach (['address', 'hours', 'delivery'] as $field) {
                    foreach (fd_admin_languages() as $lang => $_label) {
                        $data['settings'][$field][$lang] = fd_admin_text($settings[$field][$lang] ?? '', 6000, true);
                    }
                }
                if (isset($settings['remove_logo'])) {
                    $oldUploads[] = $data['settings']['logo'] ?? '';
                    $data['settings']['logo'] = '';
                }
                $logoFile = $_FILES['logo_image'] ?? [];
                if ($logoFile !== [] && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $path = fd_admin_upload($logoFile);
                    if ($path !== null) {
                        $oldUploads[] = $data['settings']['logo'] ?? '';
                        $newUploads[] = $path;
                        $data['settings']['logo'] = $path;
                    }
                }
                $page = 'settings';
                break;
            case 'save_translations':
                foreach (fd_admin_languages() as $lang => $_label) {
                    foreach ($data['translations'][$lang] ?? [] as $key => $value) {
                        if (is_string($value) && array_key_exists($key, $_POST['translations'][$lang] ?? [])) {
                            $data['translations'][$lang][$key] = fd_admin_text($_POST['translations'][$lang][$key], 4000, true);
                        }
                    }
                }
                $page = 'translations';
                break;
            default:
                throw new InvalidArgumentException('This action is not available.');
        }
        fd_save_data($data);
    } catch (Throwable $error) {
        // Newly uploaded images have not been committed to the content document.
        fd_admin_cleanup_uploads($newUploads, fd_data());
        throw $error;
    }
    fd_admin_cleanup_uploads($oldUploads, $data);
    fd_admin_redirect($page, $id, 'Changes saved.');
}

function fd_admin_config_post(string $action, array $data): void
{
    $config = fd_config();
    if ($action === 'save_mail_settings') {
        $posted = $_POST['smtp'] ?? [];
        if (!is_array($posted)) {
            throw new InvalidArgumentException('Mail settings have an invalid format.');
        }
        $smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
        foreach (['host' => 253, 'username' => 256, 'from_email' => 254, 'from_name' => 150] as $field => $limit) {
            $smtp[$field] = fd_admin_text($posted[$field] ?? '', $limit);
        }
        $smtp['host'] = strtolower($smtp['host']);
        if ($smtp['host'] !== '' && !preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $smtp['host'])) {
            throw new InvalidArgumentException('Use the SMTP host name supplied by your email provider, without a URL scheme or path.');
        }
        $port = filter_var($posted['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new InvalidArgumentException('Enter the SMTP port supplied by your provider (usually 587 or 465).');
        }
        $smtp['port'] = $port;
        $encryption = $posted['encryption'] ?? '';
        if (!in_array($encryption, ['starttls', 'tls'], true)) {
            throw new InvalidArgumentException('Choose STARTTLS or implicit TLS for SMTP.');
        }
        $smtp['encryption'] = $encryption;
        $password = $posted['password'] ?? '';
        if (!is_string($password) || strlen($password) > 256 || preg_match('/[\x00\r\n]/', $password)) {
            throw new InvalidArgumentException('The SMTP password has an invalid format.');
        }
        if (isset($posted['clear_password'])) {
            $smtp['password'] = '';
        }
        if ($password !== '') {
            $smtp['password'] = $password;
        }
        if ($smtp['host'] !== '' && !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter the verified sender email provided by your mail service.');
        }
        if ($smtp['from_email'] !== '' && !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid sender email.');
        }
        if (($smtp['username'] === '') !== (($smtp['password'] ?? '') === '')) {
            throw new InvalidArgumentException('SMTP authentication needs both a username and password. To disable it, clear the username and select Remove saved SMTP password.');
        }
        $recipient = fd_admin_text($_POST['order_recipient'] ?? '', 254);
        if ($recipient !== '' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid order recipient email.');
        }
        $config['smtp'] = $smtp;
        $config['order_recipient'] = $recipient;
        fd_save_config($config);
        fd_admin_redirect('settings', '', 'Mail settings saved. Send a test email to verify delivery.');
    }
    if ($action === 'save_hosting_settings') {
        $url = fd_admin_text($_POST['site_url'] ?? '', 2048);
        if (!fd_validate_site_url($url)) {
            throw new InvalidArgumentException('Enter the full HTTPS website URL, including its subdirectory if applicable.');
        }
        $config['site_url'] = rtrim($url, '/');
        fd_save_config($config);
        fd_admin_redirect('settings', '', 'Website URL saved. Open the administrator address on your configured domain before making further changes.');
    }
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirmation = $_POST['confirm_password'] ?? '';
        if (!is_string($current) || !is_string($new) || !is_string($confirmation) || !fd_verify_password($current)) {
            throw new InvalidArgumentException('The current password could not be verified. Check it or wait before trying again.');
        }
        if (!hash_equals($new, $confirmation)) {
            throw new InvalidArgumentException('The new passwords do not match.');
        }
        $username = fd_admin_text($_POST['admin_username'] ?? '', 64);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/D', $username)) {
            throw new InvalidArgumentException('Use a username with 3–64 English letters, numbers, dots, underscores or hyphens.');
        }
        $config['admin_username'] = $username;
        $config['password_hash'] = fd_password_hash($new);
        fd_save_config($config);
        fd_logout();
        fd_admin_redirect('dashboard', '', 'Credentials updated. Sign in using your new credentials.');
    }
    if ($action === 'send_test_email') {
        if (!fd_rate_limit('admin-test-mail', 3, 900)) {
            throw new InvalidArgumentException('Wait 15 minutes before sending another test email.');
        }
        $recipient = (string) (($config['order_recipient'] ?? '') ?: ($data['settings']['email'] ?? ''));
        $from = (string) ($config['smtp']['from_email'] ?? '');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Save a sender and recipient email before sending a test.');
        }
        require_once FD_PRIVATE_DIR . '/mail.php';
        try {
            fd_send_mail($recipient, 'FantasyDream SMTP test', "This test confirms that FantasyDream can send order request email through your configured mail provider.\n\nNo customer order was created.\n", $from);
        } catch (Throwable $error) {
            error_log('FantasyDream SMTP test failed: ' . $error->getMessage());
            throw new InvalidArgumentException('The mail server did not accept the test email. Verify the host, port, encryption, credentials and sender address with your provider.');
        }
        fd_admin_redirect('settings', '', 'The mail server accepted the test email. Check the recipient inbox and spam folder.');
    }
}

function fd_admin_form_start(string $action, bool $upload = false, string $class = ''): void
{
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    echo '<form method="post" action="' . fd_e(fd_admin_path($page, $id)) . '"' . ($upload ? ' enctype="multipart/form-data"' : '') . ($class !== '' ? ' class="' . fd_e($class) . '"' : '') . '>';
    echo '<input type="hidden" name="csrf" value="' . fd_e(fd_csrf_token()) . '"><input type="hidden" name="action" value="' . fd_e($action) . '">';
}

function fd_admin_field(string $label, string $name, mixed $value = '', string $type = 'text', string $help = '', int $maxLength = 500, bool $required = false): void
{
    $id = 'field-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
    echo '<div class="field"><label lang="en" dir="ltr" for="' . fd_e($id) . '">' . fd_e($label) . '</label>';
    $attributes = ' id="' . fd_e($id) . '" name="' . fd_e($name) . '" maxlength="' . $maxLength . '"' . ($required ? ' required' : '') . ($help !== '' ? ' aria-describedby="' . fd_e($id . '-help') . '"' : '');
    if ($type === 'textarea') {
        echo '<textarea' . $attributes . ' rows="4">' . fd_e($value) . '</textarea>';
    } else {
        echo '<input' . $attributes . ' type="' . fd_e($type) . '" value="' . fd_e($value) . '"' . ($type === 'number' ? ' min="0" step="1"' : '') . '>';
    }
    if ($help !== '') {
        echo '<p class="field-help" lang="en" dir="ltr" id="' . fd_e($id . '-help') . '">' . fd_e($help) . '</p>';
    }
    echo '</div>';
}

function fd_admin_check(string $label, string $name, bool $checked = false, string $value = '1'): void
{
    echo '<label class="check" lang="en" dir="ltr"><input type="checkbox" name="' . fd_e($name) . '" value="' . fd_e($value) . '"' . ($checked ? ' checked' : '') . '><span>' . fd_e($label) . '</span></label>';
}

function fd_admin_submit(string $label = 'Save changes'): void
{
    echo '<div class="save-bar"><button class="button" type="submit">' . fd_e($label) . '</button><span>Changes appear on the site after saving.</span></div>';
}

function fd_admin_language_start(string $lang, string $label, bool $first): void
{
    echo '<details class="language-card"' . ($first ? ' open' : '') . '><summary><span class="language-code">' . fd_e(strtoupper($lang)) . '</span>' . fd_e($label) . '<span class="summary-hint">Edit text</span></summary><div class="language-body" lang="' . fd_e($lang) . '" dir="' . ($lang === 'he' ? 'rtl' : 'ltr') . '">';
}

function fd_admin_render_dashboard(array $data): void
{
    $products = $data['products'] ?? [];
    $visible = count(array_filter($products, static fn ($p) => !empty($p['visible'])));
    echo '<div class="page-heading"><div><p class="eyebrow">Your store, simply managed</p><h1>Welcome to FantasyDream</h1><p>Keep your catalog, images and business details up to date in one place.</p></div><a class="button" href="' . fd_e(fd_admin_path('product')) . '">Add a mattress</a></div>';
    echo '<div class="stat-grid"><div class="stat-card"><strong>' . count($products) . '</strong><span>Products in catalog</span></div><div class="stat-card"><strong>' . $visible . '</strong><span>Published products</span></div><div class="stat-card"><strong>3</strong><span>Storefront languages</span></div></div>';
    echo '<section class="panel"><h2>Quick start</h2><ol class="steps"><li><a href="' . fd_e(fd_admin_path('settings')) . '">Add your contact details</a> and delivery information.</li><li><a href="' . fd_e(fd_admin_path('products')) . '">Review your mattresses</a>, sizes, prices and photos.</li><li><a href="' . fd_e(fd_admin_path('home')) . '">Personalize the homepage</a> in Hebrew, Russian and English.</li></ol></section>';
    if (empty($data['settings']['email']) || empty($data['settings']['whatsapp'])) {
        echo '<aside class="notice">Complete the missing contact details in Business details. Configure SMTP there and send a test email before accepting email order requests.</aside>';
    }
    echo '<section class="panel"><h2>Content backup</h2><p>Download a copy before making large changes. This file contains store content only.</p><a class="button button-secondary" href="' . fd_e(fd_admin_path('export')) . '">Download content JSON</a></section>';
}

function fd_admin_render_products(array $data): void
{
    echo '<div class="page-heading"><div><h1>Mattress catalog</h1><p>A lower order number appears first. Unpublished products are hidden from the storefront.</p></div><a class="button" href="' . fd_e(fd_admin_path('product')) . '">Add product</a></div>';
    $products = $data['products'] ?? [];
    usort($products, static fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    if ($products === []) {
        echo '<div class="panel empty-state"><h2>Your catalog is ready for its first mattress.</h2><p>Add product information from your catalog and upload the original photos.</p><a class="button" href="' . fd_e(fd_admin_path('product')) . '">Add a mattress</a></div>';
        return;
    }
    fd_admin_form_start('save_catalog_order');
    echo '<div class="product-list">';
    foreach ($products as $product) {
        $id = (string) $product['id'];
        $name = ($product['content']['en']['name'] ?? '') ?: ($product['content']['he']['name'] ?? $product['slug']);
        echo '<article class="product-list-card">';
        if (!empty($product['images'][0]['src'])) {
            echo '<img class="product-thumb" src="' . fd_e(fd_url($product['images'][0]['src'])) . '" alt="" width="96" height="80" loading="lazy">';
        } else {
            echo '<span class="product-thumb image-placeholder" aria-hidden="true">No photo</span>';
        }
        echo '<div class="product-list-name"><a href="' . fd_e(fd_admin_path('product', $id)) . '">' . fd_e($name) . '</a><span>' . fd_e($product['slug']) . ' · ' . count($product['sizes'] ?? []) . ' sizes</span></div><div class="list-controls">';
        fd_admin_field('Order', 'products[' . $id . '][order]', $product['order'] ?? 0, 'number', '', 6);
        fd_admin_check('Published', 'products[' . $id . '][visible]', !empty($product['visible']));
        echo '</div><a class="button button-secondary" href="' . fd_e(fd_admin_path('product', $id)) . '">Edit</a></article>';
    }
    echo '</div>';
    fd_admin_submit('Save order and visibility');
    echo '</form>';
}

function fd_admin_size_row(array $size, string $index): void
{
    $prefix = 'sizes[' . $index . ']';
    echo '<div class="repeat-row"><input type="hidden" name="' . fd_e($prefix . '[id]') . '" value="' . fd_e($size['id'] ?? '') . '">';
    fd_admin_field('Size label (cm)', $prefix . '[label]', $size['label'] ?? '', 'text', '', 160);
    $id = 'price-' . $index;
    echo '<div class="field"><label for="' . fd_e($id) . '">Price (ILS)</label><input id="' . fd_e($id) . '" type="number" name="' . fd_e($prefix . '[price]') . '" value="' . fd_e($size['price'] ?? '') . '" min="0.01" max="9999999.99" step="0.01" inputmode="decimal"></div>';
    fd_admin_check('Remove', $prefix . '[remove]');
    echo '</div>';
}

function fd_admin_spec_row(array $spec, string $lang, string $index): void
{
    $prefix = 'content[' . $lang . '][specs][' . $index . ']';
    echo '<div class="repeat-row">';
    fd_admin_field('Specification label', $prefix . '[label]', $spec['label'] ?? '', 'text', '', 250);
    fd_admin_field('Value', $prefix . '[value]', $spec['value'] ?? '', 'text', '', 1200);
    fd_admin_check('Remove', $prefix . '[remove]');
    echo '</div>';
}

function fd_admin_render_product(array $data): void
{
    $requestedId = fd_admin_text($_GET['id'] ?? '', 48);
    $index = $requestedId === '' ? null : fd_admin_product_index($data, $requestedId);
    if ($requestedId !== '' && $index === null) {
        echo '<div class="notice notice-error">This product was not found. <a href="' . fd_e(fd_admin_path('products')) . '">Return to the catalog</a>.</div>';
        return;
    }
    $product = $index === null ? fd_admin_product_new() : $data['products'][$index];
    echo '<div class="page-heading"><div><a class="back-link" href="' . fd_e(fd_admin_path('products')) . '">← Catalog</a><h1>' . ($index === null ? 'Add a mattress' : 'Edit mattress') . '</h1><p>All descriptions are plain text. Add only verified product claims.</p></div></div>';
    fd_admin_form_start('save_product', true);
    echo '<input type="hidden" name="id" value="' . fd_e($requestedId) . '"><section class="panel"><h2>Product settings</h2><div class="field-grid">';
    fd_admin_field('URL name', 'slug', $product['slug'], 'text', 'A stable, unique name such as cloud-comfort. Changing it also changes the product URL.', 100, true);
    fd_admin_field('Display order', 'order', $product['order'] ?? 0, 'number', 'Lower numbers appear first.', 6);
    echo '</div>';
    fd_admin_check('Published on the website', 'visible', !empty($product['visible']));
    echo '</section><section class="panel"><h2>Sizes and prices</h2><p>Prices are in Israeli shekels. Use a size label such as 160 × 200 cm.</p><div data-repeat-list="sizes">';
    $sizes = $product['sizes'] ?: [['label' => '', 'price' => '']];
    foreach ($sizes as $rowIndex => $size) {
        fd_admin_size_row($size, (string) $rowIndex);
    }
    echo '</div><template id="size-row-template">';
    fd_admin_size_row([], '__INDEX__');
    echo '</template><button class="button button-secondary" type="button" data-add-row="sizes" data-template="size-row-template">Add size</button></section>';
    echo '<section class="panel"><h2>Product badges</h2><p>Select only characteristics confirmed by the manufacturer.</p><div class="check-grid">';
    foreach (fd_admin_flags() as $flag => $label) {
        fd_admin_check($label, 'flags[]', in_array($flag, $product['flags'] ?? [], true), $flag);
    }
    echo '</div></section><section class="panel"><h2>Product photos</h2><p>JPEG, PNG or WebP; up to 5 MB per image. Images are securely re-encoded and resized. The lowest order number is the cover photo.</p>';
    if (($product['images'] ?? []) !== []) {
        echo '<div class="gallery-editor">';
        foreach ($product['images'] as $imageIndex => $image) {
            echo '<article class="image-card"><img src="' . fd_e(fd_url($image['src'])) . '" alt="" width="320" height="220" loading="lazy"><div class="image-card-body">';
            fd_admin_field('Photo order', 'images[' . $imageIndex . '][order]', $imageIndex, 'number', '', 5);
            foreach (fd_admin_languages() as $lang => $label) {
                fd_admin_field($label . ' alternative text', 'images[' . $imageIndex . '][alt][' . $lang . ']', $image['alt'][$lang] ?? '', 'text', '', 500);
            }
            fd_admin_check('Remove photo', 'images[' . $imageIndex . '][remove]');
            echo '</div></article>';
        }
        echo '</div>';
    }
    echo '<div class="field"><label for="product-images">Add photos</label><input id="product-images" type="file" name="product_images[]" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="photo-help"><p class="field-help" id="photo-help">Up to 12 photos per product. One photo displays as a still image; more photos create a gallery.</p></div></section>';
    $first = true;
    foreach (fd_admin_languages() as $lang => $label) {
        $content = $product['content'][$lang] ?? [];
        fd_admin_language_start($lang, $label, $first);
        $first = false;
        $prefix = 'content[' . $lang . ']';
        fd_admin_field('Product name', $prefix . '[name]', $content['name'] ?? '', 'text', '', 250, $lang === 'he');
        fd_admin_field('Short summary', $prefix . '[summary]', $content['summary'] ?? '', 'textarea', 'Shown on catalog cards.', 1600);
        fd_admin_field('Description', $prefix . '[description]', $content['description'] ?? '', 'textarea', 'Plain text; separate paragraphs with a blank line.', 24000);
        fd_admin_field('Features', $prefix . '[features]', implode("\n", $content['features'] ?? []), 'textarea', 'One feature per line.', 10000);
        echo '<h3>Specifications</h3><div data-repeat-list="specs-' . fd_e($lang) . '">';
        foreach (($content['specs'] ?? []) ?: [[]] as $rowIndex => $spec) {
            fd_admin_spec_row($spec, $lang, (string) $rowIndex);
        }
        echo '</div><template id="spec-row-template-' . fd_e($lang) . '">';
        fd_admin_spec_row([], $lang, '__INDEX__');
        echo '</template><button type="button" class="button button-secondary" data-add-row="specs-' . fd_e($lang) . '" data-template="spec-row-template-' . fd_e($lang) . '">Add specification</button><div class="seo-fields"><h3>Search appearance</h3>';
        fd_admin_field('SEO title', $prefix . '[seo_title]', $content['seo_title'] ?? '', 'text', 'Optional. Leave blank to use the product name.', 250);
        fd_admin_field('SEO description', $prefix . '[seo_description]', $content['seo_description'] ?? '', 'text', 'A concise and accurate summary for search results.', 1000);
        echo '</div></div></details>';
    }
    fd_admin_submit($index === null ? 'Create product' : 'Save product');
    echo '</form>';
    if ($index !== null) {
        echo '<section class="panel danger-zone"><h2>Remove product</h2><p>Removing a product also removes its content from the catalog. Download a content backup first if you may need it later.</p>';
        fd_admin_form_start('delete_product', false, 'delete-form');
        echo '<input type="hidden" name="id" value="' . fd_e($product['id']) . '"><button type="submit" class="button button-danger" data-confirm="Remove this product? This cannot be undone in the admin panel.">Remove product</button></form></section>';
    }
}

function fd_admin_render_page(array $data, string $section): void
{
    echo '<div class="page-heading"><div><h1>' . ($section === 'home' ? 'Homepage' : 'Contact page') . '</h1><p>Edit the page in each storefront language. Blank optional fields use the standard fallback.</p></div></div>';
    fd_admin_form_start('save_' . $section, $section === 'home');
    $first = true;
    foreach (fd_admin_languages() as $lang => $label) {
        $content = $data[$section][$lang] ?? [];
        $prefix = $section . '[' . $lang . ']';
        fd_admin_language_start($lang, $label, $first);
        $first = false;
        if ($section === 'home') {
            fd_admin_field('Small heading', $prefix . '[eyebrow]', $content['eyebrow'] ?? '', 'text', '', 300);
        }
        fd_admin_field('Main heading', $prefix . '[title]', $content['title'] ?? '', 'text', '', 500);
        fd_admin_field('Introduction', $prefix . '[intro]', $content['intro'] ?? '', 'textarea', 'Plain text; separate paragraphs with a blank line.', $section === 'home' ? 6000 : 12000);
        if ($section === 'home') {
            fd_admin_field('About heading', $prefix . '[about_title]', $content['about_title'] ?? '', 'text', '', 500);
            fd_admin_field('About text', $prefix . '[about_body]', $content['about_body'] ?? '', 'textarea', '', 18000);
            if (!empty($content['hero_image'])) {
                echo '<img class="hero-preview" src="' . fd_e(fd_url($content['hero_image'])) . '" alt="" width="480" height="300" loading="lazy">';
                fd_admin_check('Remove current homepage photo', $prefix . '[remove_hero]');
            }
            echo '<div class="field"><label for="hero-' . fd_e($lang) . '">Homepage photo</label><input type="file" id="hero-' . fd_e($lang) . '" name="hero_image_' . fd_e($lang) . '" accept="image/jpeg,image/png,image/webp"><p class="field-help">JPEG, PNG or WebP, up to 5 MB. Leave empty to keep the current image.</p></div>';
            fd_admin_field('Photo alternative text', $prefix . '[hero_alt]', $content['hero_alt'] ?? '', 'text', 'Describe the photo for visitors who cannot see it.', 500);
        }
        echo '<div class="seo-fields"><h3>Search appearance</h3>';
        fd_admin_field('SEO title', $prefix . '[seo_title]', $content['seo_title'] ?? '', 'text', '', 250);
        fd_admin_field('SEO description', $prefix . '[seo_description]', $content['seo_description'] ?? '', 'text', '', 1000);
        echo '</div></div></details>';
    }
    fd_admin_submit();
    echo '</form>';
}

function fd_admin_render_settings(array $data): void
{
    $settings = $data['settings'] ?? [];
    echo '<div class="page-heading"><div><h1>Business details</h1><p>These details appear in the storefront, contact page and order links.</p></div></div>';
    fd_admin_form_start('save_settings', true);
    echo '<section class="panel"><h2>Contact information</h2><div class="field-grid">';
    fd_admin_field('Business name', 'settings[brand]', $settings['brand'] ?? 'FantasyDream', 'text', '', 100, true);
    fd_admin_field('Order email', 'settings[email]', $settings['email'] ?? '', 'email', 'Order requests are sent here when server email is configured.', 254);
    fd_admin_field('Telephone', 'settings[phone]', $settings['phone'] ?? '', 'tel', '', 60);
    fd_admin_field('Additional telephone', 'settings[phone_secondary]', $settings['phone_secondary'] ?? '', 'tel', 'Leave blank to hide the additional number.', 60);
    fd_admin_field('WhatsApp', 'settings[whatsapp]', $settings['whatsapp'] ?? '', 'tel', 'International number with country code. Leave blank to hide WhatsApp.', 30);
    fd_admin_field('Fax', 'settings[fax]', $settings['fax'] ?? '', 'tel', 'Leave blank if your business does not use fax.', 60);
    echo '</div>';
    $vatStatus = !isset($settings['vat_included']) ? 'unknown' : ($settings['vat_included'] ? 'included' : 'excluded');
    echo '<div class="field"><label for="vat-status">VAT policy</label><select id="vat-status" name="settings[vat_status]">';
    foreach (['unknown' => 'Not specified — confirm when ordering', 'included' => 'Prices include VAT', 'excluded' => 'Prices exclude VAT'] as $value => $label) {
        echo '<option value="' . fd_e($value) . '"' . ($value === $vatStatus ? ' selected' : '') . '>' . fd_e($label) . '</option>';
    }
    echo '</select></div>';
    echo '<p class="field-help">Confirm tax and delivery wording against your actual pricing policy.</p></section>';
    echo '<section class="panel"><h2>Brand logo</h2>';
    if (!empty($settings['logo'])) {
        echo '<img class="logo-preview" src="' . fd_e(fd_url($settings['logo'])) . '" alt="Current brand logo" width="300" height="120">';
        fd_admin_check('Remove current logo', 'settings[remove_logo]');
    }
    echo '<div class="field"><label for="logo-image">Upload a new logo</label><input id="logo-image" type="file" name="logo_image" accept="image/jpeg,image/png,image/webp"><p class="field-help">JPEG, PNG or WebP, up to 5 MB. A transparent PNG or WebP works well on the light background. SVG uploads are not accepted.</p></div></section>';
    $first = true;
    foreach (fd_admin_languages() as $lang => $label) {
        fd_admin_language_start($lang, $label, $first);
        $first = false;
        fd_admin_field('Address', 'settings[address][' . $lang . ']', $settings['address'][$lang] ?? '', 'textarea', '', 6000);
        fd_admin_field('Opening hours', 'settings[hours][' . $lang . ']', $settings['hours'][$lang] ?? '', 'textarea', '', 6000);
        fd_admin_field('Delivery information', 'settings[delivery][' . $lang . ']', $settings['delivery'][$lang] ?? '', 'textarea', 'Explain delivery fees, areas and timing according to your pricing policy.', 6000);
        echo '</div></details>';
    }
    fd_admin_submit();
    echo '</form>';
    fd_admin_render_hosting_settings($data);
}

function fd_admin_render_hosting_settings(array $data): void
{
    $config = fd_config();
    $smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
    $configured = !empty($smtp['host']) && !empty($smtp['from_email']) && (($smtp['username'] ?? '') === '' || !empty($smtp['password']));
    echo '<section class="panel"><h2>Order email delivery</h2><p class="mail-status"><span class="status-dot' . ($configured ? ' status-ready' : '') . '"></span>' . ($configured ? 'SMTP configured — send a test to verify delivery.' : 'SMTP is not fully configured. Email order submission will remain unavailable.') . '</p>';
    fd_admin_form_start('save_mail_settings');
    echo '<div class="field-grid">';
    fd_admin_field('SMTP host', 'smtp[host]', $smtp['host'] ?? '', 'text', 'Use the host name from your mail provider.', 253);
    fd_admin_field('SMTP port', 'smtp[port]', $smtp['port'] ?? 587, 'number', 'Usually 587 for STARTTLS or 465 for implicit TLS.', 5, true);
    echo '<div class="field"><label for="smtp-encryption">Encryption</label><select id="smtp-encryption" name="smtp[encryption]">';
    foreach (['starttls' => 'STARTTLS (usually port 587)', 'tls' => 'Implicit TLS (usually port 465)'] as $value => $label) {
        echo '<option value="' . fd_e($value) . '"' . (($smtp['encryption'] ?? 'starttls') === $value ? ' selected' : '') . '>' . fd_e($label) . '</option>';
    }
    echo '</select></div>';
    fd_admin_field('SMTP username', 'smtp[username]', $smtp['username'] ?? '', 'text', 'Often your full mailbox address.', 256);
    echo '<div class="field"><label for="smtp-password">SMTP password</label><input type="password" id="smtp-password" name="smtp[password]" maxlength="256" autocomplete="new-password" aria-describedby="smtp-password-help"><p class="field-help" id="smtp-password-help">' . (!empty($smtp['password']) ? 'A password is saved. Leave this field empty to keep it.' : 'No SMTP password is stored yet.') . ' The saved value is never displayed or exported.</p></div>';
    fd_admin_field('Verified sender email', 'smtp[from_email]', $smtp['from_email'] ?? '', 'email', 'This address must be accepted by your mail provider.', 254);
    fd_admin_field('Sender name', 'smtp[from_name]', $smtp['from_name'] ?? 'FantasyDream', 'text', '', 150);
    fd_admin_field('Order recipient override', 'order_recipient', $config['order_recipient'] ?? '', 'email', 'Leave empty to send orders to the business email above.', 254);
    echo '</div>';
    fd_admin_check('Remove saved SMTP password', 'smtp[clear_password]');
    echo '<p class="field-help">Use a mail provider app password when required. Credentials are saved in the private configuration outside the web root.</p><div class="form-actions"><button class="button" type="submit">Save mail settings</button></div></form>';
    fd_admin_form_start('send_test_email');
    echo '<div class="form-actions"><button class="button button-secondary" type="submit">Send test email</button><span class="field-help">Sends one test message to the configured order recipient.</span></div></form></section>';
    echo '<section class="panel"><h2>Website address</h2><p>Use the actual HTTPS address where this site is installed. Include its folder when the site lives in a subdirectory.</p>';
    fd_admin_form_start('save_hosting_settings');
    fd_admin_field('Website URL', 'site_url', $config['site_url'] ?? '', 'url', 'Example: https://your-domain.example. After changing domains, open the admin page on the new domain before editing again.', 2048, true);
    echo '<button class="button button-secondary" type="submit">Save website address</button></form></section>';
    echo '<section class="panel"><h2>Administrator credentials</h2><p>Changing credentials signs out this session. Other sessions are also invalidated. Use a unique password of 12–72 bytes.</p>';
    fd_admin_form_start('change_password');
    fd_admin_field('Administrator username', 'admin_username', $config['admin_username'] ?? '', 'text', '3–64 English letters, numbers, dots, underscores or hyphens.', 64, true);
    foreach (['current_password' => ['Current password', 'current-password'], 'new_password' => ['New password', 'new-password'], 'confirm_password' => ['Confirm new password', 'new-password']] as $field => [$label, $autocomplete]) {
        echo '<div class="field"><label for="' . fd_e($field) . '">' . fd_e($label) . '</label><input id="' . fd_e($field) . '" type="password" name="' . fd_e($field) . '" maxlength="72"' . ($field !== 'current_password' ? ' minlength="12"' : '') . ' autocomplete="' . fd_e($autocomplete) . '" required></div>';
    }
    echo '<button class="button button-secondary" type="submit">Change credentials and sign out</button></form></section>';
}

function fd_admin_render_translations(array $data): void
{
    echo '<div class="page-heading"><div><h1>Storefront labels</h1><p>Edit navigation, buttons and system messages. Keep placeholders such as {name} exactly as written.</p></div></div>';
    fd_admin_form_start('save_translations');
    $first = true;
    foreach (fd_admin_languages() as $lang => $label) {
        fd_admin_language_start($lang, $label, $first);
        $first = false;
        foreach ($data['translations'][$lang] ?? [] as $key => $value) {
            if (is_string($value)) {
                fd_admin_field(ucfirst(str_replace('_', ' ', (string) $key)), 'translations[' . $lang . '][' . $key . ']', $value, 'textarea', '', 4000);
            }
        }
        echo '</div></details>';
    }
    fd_admin_submit();
    echo '</form>';
}

function fd_admin_render(): void
{
    fd_security_headers();
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    fd_session();
    $data = fd_data();
    $error = '';
    if (!fd_request_secure()) {
        http_response_code(403);
        $error = 'Administration requires HTTPS. Open this address using https:// after enabling HTTPS on your hosting.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            fd_admin_post($data);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
            $data = fd_data();
        } catch (Throwable $exception) {
            error_log('FantasyDream admin: ' . $exception->getMessage());
            $error = 'The change could not be saved. Check file permissions, image support and available disk space, then try again.';
            $data = fd_data();
        }
    }
    $authenticated = fd_is_admin() && fd_request_secure();
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
    $pages = ['dashboard' => 'Overview', 'products' => 'Products', 'home' => 'Homepage', 'contact' => 'Contact page', 'settings' => 'Business details', 'translations' => 'Storefront labels'];
    if (!array_key_exists($page, $pages) && !in_array($page, ['product', 'export'], true)) {
        $page = 'dashboard';
    }
    if ($authenticated && $page === 'export') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="fantasydream-content-' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="same-origin"><title>FantasyDream · Administration</title><link rel="stylesheet" href="' . fd_e(fd_url('/assets/admin.css')) . '"><script src="' . fd_e(fd_url('/assets/admin.js')) . '" defer></script></head><body class="' . ($authenticated ? 'admin-shell' : 'login-shell') . '"><a class="skip-link" href="#main">Skip to content</a>';
    if (!$authenticated) {
        echo '<main id="main" class="login-card"><a class="brand" href="' . fd_e(fd_url('/he/')) . '">Fantasy<span>Dream</span></a><p class="eyebrow">Store administration</p><h1>Welcome back</h1><p class="login-intro">Sign in to manage your catalog and website.</p>';
        if ($error !== '') {
            echo '<div class="notice notice-error" role="alert">' . fd_e($error) . '</div>';
        }
        if (isset($_SESSION['fd_admin_notice'])) {
            echo '<div class="notice notice-success" role="status">' . fd_e($_SESSION['fd_admin_notice']) . '</div>';
            unset($_SESSION['fd_admin_notice']);
        }
        if (fd_request_secure()) {
            fd_admin_form_start('login');
            echo '<div class="field"><label for="username">Username</label><input id="username" type="text" name="username" maxlength="160" autocomplete="username" required autofocus></div><div class="field"><label for="password">Password</label><input id="password" type="password" name="password" maxlength="1024" autocomplete="current-password" required></div><button class="button button-full" type="submit">Sign in</button></form><p class="field-help">Use the administrator credentials created during installation.</p>';
        }
        echo '</main></body></html>';
        return;
    }
    echo '<aside class="sidebar"><a class="brand" href="' . fd_e(fd_admin_path()) . '">Fantasy<span>Dream</span></a><p class="sidebar-caption">Administration</p><nav aria-label="Administration">';
    foreach ($pages as $key => $label) {
        echo '<a href="' . fd_e(fd_admin_path($key)) . '"' . ($page === $key || ($page === 'product' && $key === 'products') ? ' aria-current="page"' : '') . '>' . fd_e($label) . '</a>';
    }
    echo '</nav><div class="sidebar-bottom"><a href="' . fd_e(fd_url('/he/')) . '" target="_blank" rel="noopener">View website ↗</a>';
    fd_admin_form_start('logout');
    echo '<button type="submit" class="logout-button">Sign out</button></form></div></aside><div class="workspace"><header class="admin-topbar"><span>FantasyDream CMS</span><a href="' . fd_e(fd_url('/he/')) . '" target="_blank" rel="noopener">Open website ↗</a></header><main id="main" class="main-content">';
    if ($error !== '') {
        echo '<div class="notice notice-error" role="alert">' . fd_e($error) . ' Your previously saved content has been kept.</div>';
    }
    if (isset($_SESSION['fd_admin_notice'])) {
        echo '<div class="notice notice-success" role="status">' . fd_e($_SESSION['fd_admin_notice']) . '</div>';
        unset($_SESSION['fd_admin_notice']);
    }
    try {
        match ($page) {
            'products' => fd_admin_render_products($data),
            'product' => fd_admin_render_product($data),
            'home', 'contact' => fd_admin_render_page($data, $page),
            'settings' => fd_admin_render_settings($data),
            'translations' => fd_admin_render_translations($data),
            default => fd_admin_render_dashboard($data),
        };
    } catch (Throwable $exception) {
        error_log('FantasyDream admin render: ' . $exception->getMessage());
        echo '<div class="notice notice-error" role="alert">This page could not be loaded. Return to the overview and check the content file format.</div>';
    }
    echo '</main><footer class="admin-footer">FantasyDream · Private administration · No online payments</footer></div></body></html>';
}
