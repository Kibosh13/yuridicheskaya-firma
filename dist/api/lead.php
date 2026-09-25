<?php

declare(strict_types=1);

require dirname(__DIR__) . '/cms/leads.php';
require dirname(__DIR__) . '/cms/notifications.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function lead_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    lead_response(405, ['ok' => false, 'message' => 'Метод не поддерживается.']);
}

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower((string) preg_replace('/:\d+$/', '', $host));
    if ($originHost === '' || !hash_equals($requestHost, $originHost)) {
        lead_response(403, ['ok' => false, 'message' => 'Источник запроса не разрешён.']);
    }
}

if (trim((string) ($_POST['website'] ?? '')) !== '') {
    lead_response(201, ['ok' => true]);
}

session_name('litvinova_lead');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$lastSubmission = (int) ($_SESSION['last_submission'] ?? 0);
if ($lastSubmission > 0 && time() - $lastSubmission < 45) {
    lead_response(429, ['ok' => false, 'message' => 'Заявка уже отправлена. Пожалуйста, подождите немного.']);
}

$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$topic = trim((string) ($_POST['topic'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$topics = ['Банкротство', 'ДТП', 'Семейные споры', 'Арбитражные споры', 'Наследственные споры'];
$phoneDigits = preg_replace('/\D+/', '', $phone);

if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120) {
    lead_response(422, ['ok' => false, 'message' => 'Укажите имя.']);
}
if (!is_string($phoneDigits) || strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
    lead_response(422, ['ok' => false, 'message' => 'Проверьте номер телефона.']);
}
if (!in_array($topic, $topics, true)) {
    lead_response(422, ['ok' => false, 'message' => 'Выберите направление.']);
}
if (mb_strlen($message, 'UTF-8') > 5000) {
    lead_response(422, ['ok' => false, 'message' => 'Описание слишком длинное.']);
}

$now = gmdate('c');
$localDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Ymd');
$lead = [
    'id' => 'L-' . $localDate . '-' . strtoupper(bin2hex(random_bytes(3))),
    'created_at' => $now,
    'updated_at' => $now,
    'status' => 'new',
    'name' => mb_substr($name, 0, 120, 'UTF-8'),
    'phone' => mb_substr($phone, 0, 40, 'UTF-8'),
    'topic' => $topic,
    'message' => mb_substr($message, 0, 5000, 'UTF-8'),
    'note' => '',
    'source' => 'Форма на сайте',
];

$saved = cms_leads_mutate(static function (array $leads) use ($lead): array {
    array_unshift($leads, $lead);
    return array_slice($leads, 0, 5000);
});

if (!$saved) {
    lead_response(500, ['ok' => false, 'message' => 'Не удалось сохранить заявку. Позвоните нам по телефону.']);
}

$notificationStatus = cms_send_lead_notification($lead);
cms_leads_mutate(static function (array $leads) use ($lead, $notificationStatus): array {
    foreach ($leads as &$storedLead) {
        if (is_array($storedLead) && isset($storedLead['id']) && hash_equals((string) $lead['id'], (string) $storedLead['id'])) {
            $storedLead['notification_status'] = $notificationStatus;
            $storedLead['notification_at'] = gmdate('c');
            break;
        }
    }
    unset($storedLead);
    return $leads;
});

$_SESSION['last_submission'] = time();
lead_response(201, ['ok' => true, 'id' => $lead['id']]);
