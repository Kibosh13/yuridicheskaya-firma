<?php

declare(strict_types=1);

require __DIR__ . '/cms/runtime.php';
header('Content-Type: text/plain; charset=UTF-8');

if (cms_value('seo.indexing') === '1') {
    echo "User-agent: *\nAllow: /\nSitemap: https://" . ($_SERVER['HTTP_HOST'] ?? 'судебныйадвокат.рф') . "/sitemap.xml\n";
} else {
    echo "User-agent: *\nDisallow: /\n";
}
