<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime.php';

function cms_leads_file(): string
{
    return cms_storage_dir() . '/leads.json';
}

function cms_leads_lock_file(): string
{
    return cms_storage_dir() . '/leads.lock';
}

function cms_lead_statuses(): array
{
    return [
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'processed' => 'Обработана',
        'closed' => 'Закрыта',
    ];
}

function cms_leads_prepare_storage(): bool
{
    $directory = cms_storage_dir();
    return is_dir($directory) || (mkdir($directory, 0700, true) && is_dir($directory));
}

function cms_leads_decode(): array
{
    $file = cms_leads_file();
    if (!is_file($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static fn($lead): bool => is_array($lead)));
}

function cms_leads_read(): array
{
    if (!cms_leads_prepare_storage()) {
        return [];
    }

    $lock = fopen(cms_leads_lock_file(), 'c+');
    if ($lock === false) {
        return [];
    }
    chmod(cms_leads_lock_file(), 0600);

    try {
        if (!flock($lock, LOCK_SH)) {
            return [];
        }
        $leads = cms_leads_decode();
        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }

    usort($leads, static fn(array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
    return $leads;
}

function cms_leads_mutate(callable $mutator): bool
{
    if (!cms_leads_prepare_storage()) {
        return false;
    }

    $lock = fopen(cms_leads_lock_file(), 'c+');
    if ($lock === false) {
        return false;
    }
    chmod(cms_leads_lock_file(), 0600);

    try {
        if (!flock($lock, LOCK_EX)) {
            return false;
        }

        $updated = $mutator(cms_leads_decode());
        if (!is_array($updated)) {
            flock($lock, LOCK_UN);
            return false;
        }

        $encoded = json_encode(array_values($updated), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            flock($lock, LOCK_UN);
            return false;
        }

        $file = cms_leads_file();
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false) {
            flock($lock, LOCK_UN);
            return false;
        }
        chmod($temporary, 0600);
        $saved = rename($temporary, $file);
        if ($saved) {
            chmod($file, 0600);
        }
        flock($lock, LOCK_UN);
        return $saved;
    } finally {
        fclose($lock);
    }
}

