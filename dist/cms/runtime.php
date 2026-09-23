<?php

declare(strict_types=1);

function cms_sections(): array
{
    static $sections;
    if ($sections === null) {
        $sections = require __DIR__ . '/schema.php';
    }
    return $sections;
}

function cms_fields(): array
{
    static $fields;
    if ($fields !== null) {
        return $fields;
    }

    $fields = [];
    foreach (cms_sections() as $section) {
        foreach ($section['fields'] as $field) {
            $fields[$field['key']] = $field;
        }
    }
    return $fields;
}

function cms_storage_dir(): string
{
    $custom = getenv('LITVINOVA_CMS_DIR');
    if (is_string($custom) && $custom !== '') {
        return rtrim($custom, DIRECTORY_SEPARATOR);
    }
    return dirname(__DIR__, 2) . '/.litvinova-cms';
}

function cms_content_file(): string
{
    return cms_storage_dir() . '/content.json';
}

function cms_defaults(): array
{
    $defaults = [];
    foreach (cms_fields() as $key => $field) {
        $defaults[$key] = $field['default'];
    }
    return $defaults;
}

function cms_content(bool $reload = false): array
{
    static $content;
    if ($content !== null && !$reload) {
        return $content;
    }

    $content = cms_defaults();
    $file = cms_content_file();
    if (!is_file($file)) {
        return $content;
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return $content;
    }

    foreach ($decoded as $key => $value) {
        if (array_key_exists($key, $content) && is_scalar($value)) {
            $content[$key] = (string) $value;
        }
    }
    return $content;
}

function cms_value(string $key): string
{
    $content = cms_content();
    return $content[$key] ?? '';
}

function cms_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cms_page_has_field(array $field, string $page): bool
{
    return in_array('all', $field['pages'], true) || in_array($page, $field['pages'], true);
}

function cms_phone_href(string $phone): string
{
    $prefix = str_starts_with(trim($phone), '+') ? '+' : '';
    return $prefix . preg_replace('/\D+/', '', $phone);
}

function cms_replace_meta(string $html, string $name, string $content): string
{
    $escaped = cms_escape($content);
    $pattern = '/<meta\s+name=["\']' . preg_quote($name, '/') . '["\']\s+content=["\'][^"\']*["\']\s*\/?\s*>/iu';
    $replacement = '<meta name="' . cms_escape($name) . '" content="' . $escaped . '">';
    if (preg_match($pattern, $html)) {
        return (string) preg_replace($pattern, $replacement, $html, 1);
    }
    return str_replace('</head>', '  ' . $replacement . "\n</head>", $html);
}

function cms_render(string $templateFile, string $page): string
{
    $html = (string) file_get_contents($templateFile);
    $fields = array_values(cms_fields());

    usort($fields, static fn(array $a, array $b): int => mb_strlen($b['default']) <=> mb_strlen($a['default']));
    foreach ($fields as $field) {
        if (!$field['replace'] || !cms_page_has_field($field, $page) || $field['default'] === '') {
            continue;
        }
        $replacement = cms_value($field['key']);
        if (in_array($field['key'], [
            'home.about_title_1',
            'home.statement_1',
            'reviews.title_1',
            'bankruptcy.hero_title_1',
            'dtp.hero_title_1',
        ], true)) {
            $replacement = rtrim($replacement) . ' ';
        }
        $html = str_replace($field['default'], cms_escape($replacement), $html);
    }

    $company = cms_escape(cms_value('site.company'));
    $descriptor = cms_escape(cms_value('site.descriptor'));
    $html = str_replace('ЛИТВИНОВА И ПАРТНЕРЫ', mb_strtoupper($company, 'UTF-8'), $html);
    $html = str_replace('Литвинова и партнеры', $company, $html);
    $html = str_replace('ЮРИДИЧЕСКАЯ КОМПАНИЯ', mb_strtoupper($descriptor, 'UTF-8'), $html);
    $html = str_replace('Юридическая компания', $descriptor, $html);
    $html = str_replace('>Л<', '>' . cms_escape(mb_substr(cms_value('site.mark'), 0, 2, 'UTF-8')) . '<', $html);

    $phoneDefaults = ['+7 966 098-03-46', '+7 985 655-20-65', '+7 985 655-20-68'];
    foreach ([1, 2, 3] as $index) {
        $phone = cms_value('site.phone' . $index);
        $html = str_replace('tel:' . cms_phone_href($phoneDefaults[$index - 1]), 'tel:' . cms_escape(cms_phone_href($phone)), $html);
        $html = str_replace($phoneDefaults[$index - 1], cms_escape($phone), $html);
    }

    if ($page === 'home') {
        $html = str_replace('assets/hero-dossier.jpg', cms_escape(cms_value('home.hero_image')), $html);
    }

    $seoPrefix = match ($page) {
        'bankruptcy' => 'seo.bankruptcy_',
        'dtp' => 'seo.dtp_',
        default => 'seo.home_',
    };
    $title = cms_value($seoPrefix . 'title');
    $description = cms_value($seoPrefix . 'description');
    $keywords = cms_value($seoPrefix . 'keywords');
    $robots = cms_value('seo.indexing') === '1' ? 'index, follow' : 'noindex, nofollow, noarchive, nosnippet, noimageindex';

    $html = (string) preg_replace('/<title>.*?<\/title>/isu', '<title>' . cms_escape($title) . '</title>', $html, 1);
    $html = cms_replace_meta($html, 'description', $description);
    $html = cms_replace_meta($html, 'keywords', $keywords);
    $html = cms_replace_meta($html, 'robots', $robots);

    $canonical = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'судебныйадвокат.рф') . match ($page) {
        'bankruptcy' => '/bankrotstvo/',
        'dtp' => '/dtp/',
        default => '/',
    };
    $social = [
        '<link rel="canonical" href="' . cms_escape($canonical) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:locale" content="ru_RU">',
        '<meta property="og:title" content="' . cms_escape($title) . '">',
        '<meta property="og:description" content="' . cms_escape($description) . '">',
        '<meta property="og:url" content="' . cms_escape($canonical) . '">',
    ];
    if ($page === 'home') {
        $image = cms_value('home.hero_image');
        $imageUrl = str_starts_with($image, 'http') ? $image : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'судебныйадвокат.рф') . '/' . ltrim($image, '/');
        $social[] = '<meta property="og:image" content="' . cms_escape($imageUrl) . '">';
    }
    $html = str_replace('</head>', '  ' . implode("\n  ", $social) . "\n</head>", $html);

    return $html;
}
