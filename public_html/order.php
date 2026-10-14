<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once FD_PRIVATE_DIR . '/mail.php';

fd_security_headers(true);
$lang = 'he';
$messages = [
    'he' => [
        'sent' => 'בקשת ההזמנה נשלחה. ניצור איתך קשר כדי לאשר את הפרטים.',
        'invalid' => 'נא לבדוק את פרטי ההזמנה ולנסות שוב.',
        'security' => 'הבקשה לא אושרה. נא לרענן את עמוד המוצר ולנסות שוב.',
        'rate' => 'נשלחו בקשות רבות מדי. נא להמתין ולנסות שוב מאוחר יותר.',
        'unavailable' => 'לא ניתן לשלוח בקשת הזמנה כעת. אפשר ליצור קשר באמצעות WhatsApp או הטלפון.',
        'back' => 'חזרה לקטלוג', 'title' => 'בקשת הזמנה',
    ],
    'ru' => [
        'sent' => 'Заявка отправлена. Мы свяжемся с вами, чтобы подтвердить детали.',
        'invalid' => 'Проверьте данные заявки и попробуйте ещё раз.',
        'security' => 'Запрос не прошёл проверку. Обновите страницу товара и попробуйте ещё раз.',
        'rate' => 'Слишком много заявок. Подождите и попробуйте позднее.',
        'unavailable' => 'Сейчас отправить заявку не удалось. Свяжитесь с нами через WhatsApp или по телефону.',
        'back' => 'Вернуться в каталог', 'title' => 'Заявка на заказ',
    ],
    'en' => [
        'sent' => 'Your order request has been sent. We will contact you to confirm the details.',
        'invalid' => 'Please check your order details and try again.',
        'security' => 'The request could not be verified. Refresh the product page and try again.',
        'rate' => 'Too many requests. Please wait and try again later.',
        'unavailable' => 'Your order request could not be sent right now. Please contact us by WhatsApp or phone.',
        'back' => 'Back to the catalog', 'title' => 'Order request',
    ],
];

function fd_order_response(bool $ok, string $message, int $status, string $lang, array $messages): never
{
    http_response_code($status);
    $wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    $direction = $lang === 'he' ? 'rtl' : 'ltr';
    echo '<!doctype html><html lang="' . fd_e($lang) . '" dir="' . $direction . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . fd_e($messages[$lang]['title']) . ' · FantasyDream</title><link rel="stylesheet" href="' . fd_e(fd_url('assets/site.css')) . '"></head><body><main class="container"><h1>' . fd_e($messages[$lang]['title']) . '</h1><p role="status">' . fd_e($message) . '</p><p><a href="' . fd_e(fd_url($lang . '/catalog/')) . '">' . fd_e($messages[$lang]['back']) . '</a></p></main></body></html>';
    exit;
}

function fd_order_text(mixed $value, int $maximum, bool $multiline = false): ?string
{
    if (!is_string($value) || strlen($value) > $maximum || preg_match('//u', $value) !== 1
        || preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value)) {
        return null;
    }
    return trim($value);
}

function fd_order_money_minor(mixed $price): ?int
{
    if (is_float($price)) {
        if (!is_finite($price) || abs($price * 100 - round($price * 100)) > 0.0001) {
            return null;
        }
        $price = number_format($price, 2, '.', '');
    } elseif (is_int($price)) {
        $price = (string) $price;
    }
    if (!is_string($price) || !preg_match('/\A([0-9]{1,7})(?:\.([0-9]{1,2}))?\z/D', $price, $matches)) {
        return null;
    }
    $minor = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    return $minor > 0 ? $minor : null;
}

function fd_order_money(int $minor): string
{
    return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT) . ' ILS';
}

try {
    $data = fd_data();
    $translationKeys = [
        'sent' => 'order_success', 'unavailable' => 'order_error', 'title' => 'order_title',
        'back' => 'nav_catalog', 'invalid' => 'order_validation_error',
        'security' => 'order_security_error', 'rate' => 'order_rate_error',
    ];
    foreach ($messages as $messageLang => $localized) {
        foreach ($translationKeys as $messageKey => $translationKey) {
            $custom = $data['translations'][$messageLang][$translationKey] ?? null;
            if (is_string($custom) && $custom !== '' && strlen($custom) <= 4000 && preg_match('//u', $custom) === 1) {
                $messages[$messageLang][$messageKey] = $custom;
            }
        }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        fd_order_response(false, $messages[$lang]['invalid'], 405, $lang, $messages);
    }
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 32768) {
        fd_order_response(false, $messages[$lang]['invalid'], 413, $lang, $messages);
    }
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType === 'application/json') {
        $raw = file_get_contents('php://input', false, null, 0, 32769);
        if ($raw === false || strlen($raw) > 32768) {
            fd_order_response(false, $messages[$lang]['invalid'], 413, $lang, $messages);
        }
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } elseif ($contentType === 'application/x-www-form-urlencoded') {
        $input = $_POST;
    } else {
        fd_order_response(false, $messages[$lang]['invalid'], 415, $lang, $messages);
    }
    if (!is_array($input)) {
        fd_order_response(false, $messages[$lang]['invalid'], 400, $lang, $messages);
    }
    $requestedLang = $input['lang'] ?? $input['locale'] ?? 'he';
    if (is_string($requestedLang) && isset($messages[$requestedLang])) {
        $lang = $requestedLang;
    }
    if (!fd_request_secure() || !fd_is_installed() || !fd_origin_ok() || !fd_csrf_check($input['csrf'] ?? null)) {
        fd_order_response(false, $messages[$lang]['security'], 403, $lang, $messages);
    }
    if (!fd_rate_limit('orders', 5, 900)) {
        header('Retry-After: 900');
        fd_order_response(false, $messages[$lang]['rate'], 429, $lang, $messages);
    }
    $productId = fd_order_text($input['product_id'] ?? null, 100);
    $sizeId = fd_order_text($input['size_id'] ?? null, 100);
    $name = fd_order_text($input['name'] ?? null, 120);
    $email = fd_order_text($input['email'] ?? null, 254);
    $phone = fd_order_text($input['phone'] ?? '', 40);
    $message = fd_order_text($input['message'] ?? '', 3000, true);
    $honeypot = fd_order_text($input['website'] ?? '', 200);
    $quantityRaw = $input['quantity'] ?? null;
    $quantity = (is_string($quantityRaw) || is_int($quantityRaw)) && preg_match('/\A[0-9]{1,2}\z/D', (string) $quantityRaw)
        ? (int) $quantityRaw : 0;
    if ($productId === null || $sizeId === null || $name === null || strlen($name) < 2
        || $email === null || !fd_mail_address($email) || $phone === null || $message === null
        || $honeypot !== '' || $quantity < 1 || $quantity > 20
        || !in_array($input['consent'] ?? null, ['1', 1, true], true)
        || ($phone !== '' && (!preg_match('/\A[0-9+(). \-]{7,40}\z/D', $phone) || strlen(preg_replace('/\D/', '', $phone) ?? '') < 6))) {
        fd_order_response(false, $messages[$lang]['invalid'], 422, $lang, $messages);
    }
    $product = null;
    foreach ($data['products'] ?? [] as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $productId && ($candidate['visible'] ?? false) === true) {
            $product = $candidate;
            break;
        }
    }
    $size = null;
    foreach ($product['sizes'] ?? [] as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $sizeId) {
            $size = $candidate;
            break;
        }
    }
    $priceMinor = $size === null ? null : fd_order_money_minor($size['price'] ?? null);
    if ($product === null || $size === null || $priceMinor === null) {
        fd_order_response(false, $messages[$lang]['invalid'], 422, $lang, $messages);
    }
    $config = fd_config();
    $recipient = (string) ($config['order_recipient'] ?? '');
    if ($recipient === '') {
        $recipient = (string) ($data['settings']['email'] ?? '');
    }
    if (!fd_mail_address($recipient)) {
        fd_order_response(false, $messages[$lang]['unavailable'], 503, $lang, $messages);
    }
    $productName = (string) ($product['content'][$lang]['name'] ?? $product['content']['he']['name'] ?? $productId);
    $sizeLabel = (string) ($size['label'] ?? $sizeId);
    $vat = match ($data['settings']['vat_included'] ?? null) {
        true => 'Prices include VAT.',
        false => 'Prices exclude VAT.',
        default => 'VAT status: not confirmed in site settings.',
    };
    $body = implode("\n", [
        'FantasyDream — order request',
        '',
        'This is a request to confirm an order; no online payment has been taken.',
        'Product: ' . $productName,
        'Product ID: ' . $productId,
        'Size: ' . $sizeLabel,
        'Quantity: ' . $quantity,
        'Unit catalog price: ' . fd_order_money($priceMinor),
        'Catalog subtotal: ' . fd_order_money($priceMinor * $quantity),
        $vat,
        'Delivery charges and availability must be confirmed separately.',
        '',
        'Customer name: ' . $name,
        'Customer email: ' . $email,
        'Customer phone: ' . ($phone !== '' ? $phone : '(not supplied)'),
        'Language: ' . $lang,
        'Consent to contact about this request: yes',
        '',
        'Customer message:',
        $message !== '' ? $message : '(none)',
        '',
        'Received: ' . gmdate('Y-m-d H:i:s') . ' UTC',
    ]);
    // Release the visitor session lock before waiting on the SMTP service.
    session_write_close();
    fd_send_mail($recipient, 'FantasyDream order request · ' . $productName, $body, $email);
    fd_order_response(true, $messages[$lang]['sent'], 200, $lang, $messages);
} catch (JsonException) {
    fd_order_response(false, $messages[$lang]['invalid'], 400, $lang, $messages);
} catch (Throwable $exception) {
    // Do not log customer fields, SMTP credentials or SMTP response text.
    error_log('FantasyDream order service: ' . get_class($exception) . ': ' . $exception->getMessage());
    fd_order_response(false, $messages[$lang]['unavailable'], 503, $lang, $messages);
}
