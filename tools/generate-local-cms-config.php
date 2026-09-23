<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$targetDir = $projectRoot . '/.deployment-private/.litvinova-cms';
if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "Cannot create private deployment directory.\n");
    exit(1);
}

$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%+=_-';
$password = '';
for ($i = 0; $i < 22; $i++) {
    $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}

$username = 'litvinova-admin';
$config = [
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
];
$configPhp = "<?php\nreturn " . var_export($config, true) . ";\n";
file_put_contents($targetDir . '/config.php', $configPhp, LOCK_EX);
chmod($targetDir . '/config.php', 0600);

require_once $projectRoot . '/dist/cms/runtime.php';
file_put_contents(
    $targetDir . '/content.json',
    json_encode(cms_defaults(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    LOCK_EX
);
chmod($targetDir . '/content.json', 0600);

file_put_contents($projectRoot . '/.deployment-private/admin-credentials.txt', "login={$username}\npassword={$password}\n", LOCK_EX);
chmod($projectRoot . '/.deployment-private/admin-credentials.txt', 0600);

fwrite(STDOUT, "Private CMS configuration generated.\n");
