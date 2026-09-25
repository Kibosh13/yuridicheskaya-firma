<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime.php';

function cms_notification_email(): string
{
    $email = trim(cms_value('notifications.email'));
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
}

function cms_notification_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    if (!preg_match('/^[a-z0-9.-]+$/', $host) || !str_contains($host, '.')) {
        return 'xn--80aabfhce7amzp1ayc8j.xn--p1ai';
    }
    return $host;
}

function cms_notification_date(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
    } catch (Exception) {
        return $value;
    }
}

function cms_lead_email_body(array $lead): string
{
    $message = trim((string) ($lead['message'] ?? ''));
    if ($message === '') {
        $message = 'Не заполнено';
    }

    return implode("\n", [
        'Новая заявка с сайта «' . cms_value('site.company') . '»',
        '',
        'Номер: ' . (string) ($lead['id'] ?? ''),
        'Дата: ' . cms_notification_date((string) ($lead['created_at'] ?? '')) . ' (МСК)',
        'Имя: ' . (string) ($lead['name'] ?? ''),
        'Телефон: ' . (string) ($lead['phone'] ?? ''),
        'Направление: ' . (string) ($lead['topic'] ?? ''),
        '',
        'Описание ситуации:',
        $message,
        '',
        'Открыть заявки: https://' . cms_notification_host() . '/admin/#leads',
    ]);
}

function cms_send_lead_notification(array $lead): string
{
    $recipient = cms_notification_email();
    if ($recipient === '') {
        return 'not_configured';
    }

    $host = cms_notification_host();
    $from = 'no-reply@' . $host;
    $subjectText = 'Новая заявка ' . (string) ($lead['id'] ?? '') . ' — ' . (string) ($lead['topic'] ?? 'с сайта');
    $subject = mb_encode_mimeheader($subjectText, 'UTF-8', 'B', "\r\n");
    $headers = implode("\r\n", [
        'From: ' . cms_value('site.company') . ' <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'MIME-Version: 1.0',
        'X-Mailer: Litvinova Website',
    ]);

    $sent = mail($recipient, $subject, cms_lead_email_body($lead), $headers, '-f' . $from);
    return $sent ? 'sent' : 'failed';
}
