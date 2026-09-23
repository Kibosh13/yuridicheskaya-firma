<?php

declare(strict_types=1);

require dirname(__DIR__) . '/cms/runtime.php';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

$storageDir = cms_storage_dir();
$configFile = $storageDir . '/config.php';
$config = is_file($configFile) ? require $configFile : null;

if (!is_array($config) || empty($config['username']) || empty($config['password_hash'])) {
    http_response_code(503);
    exit('Админ-панель ещё не настроена.');
}

session_name('litvinova_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_csrf_valid(): bool
{
    return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals((string) $_SESSION['csrf'], $_POST['csrf']);
}

function admin_save_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $temporary = $file . '.tmp-' . bin2hex(random_bytes(6));
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false) {
        return false;
    }
    chmod($temporary, 0600);
    return rename($temporary, $file);
}

function admin_redirect(string $status): never
{
    header('Location: ./?status=' . rawurlencode($status));
    exit;
}

$loggedIn = !empty($_SESSION['admin_authenticated']);
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'login') {
        $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            $loginError = 'Слишком много попыток. Повторите вход через несколько минут.';
        } elseif (!admin_csrf_valid()) {
            $loginError = 'Сессия устарела. Обновите страницу.';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (hash_equals((string) $config['username'], $username) && password_verify($password, (string) $config['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_authenticated'] = true;
                $_SESSION['login_attempts'] = 0;
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                admin_redirect('welcome');
            }
            $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['login_locked_until'] = time() + 900;
            }
            usleep(350000);
            $loginError = 'Неверный логин или пароль.';
        }
        $loggedIn = !empty($_SESSION['admin_authenticated']);
    } elseif ($action === 'logout' && $loggedIn && admin_csrf_valid()) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        header('Location: ./');
        exit;
    } elseif ($loggedIn) {
        if (!admin_csrf_valid()) {
            admin_redirect('csrf-error');
        }

        if ($action === 'save_content') {
            $submitted = isset($_POST['content']) && is_array($_POST['content']) ? $_POST['content'] : [];
            $current = cms_content(true);
            $saved = [];
            foreach (cms_fields() as $key => $field) {
                if ($field['type'] === 'image') {
                    $saved[$key] = $current[$key] ?? $field['default'];
                    continue;
                }
                if ($field['type'] === 'checkbox') {
                    $saved[$key] = isset($submitted[$key]) ? '1' : '0';
                    continue;
                }
                $value = isset($submitted[$key]) && is_scalar($submitted[$key]) ? trim((string) $submitted[$key]) : (string) ($current[$key] ?? $field['default']);
                $saved[$key] = mb_substr($value, 0, 10000, 'UTF-8');
            }
            if (isset($saved['site.mark'])) {
                $saved['site.mark'] = mb_substr($saved['site.mark'], 0, 2, 'UTF-8');
            }
            if (!admin_save_json(cms_content_file(), $saved)) {
                admin_redirect('save-error');
            }
            admin_redirect('saved');
        }

        if ($action === 'upload_image') {
            if (!isset($_FILES['hero_image']) || !is_array($_FILES['hero_image']) || (int) $_FILES['hero_image']['error'] !== UPLOAD_ERR_OK) {
                admin_redirect('image-error');
            }
            $upload = $_FILES['hero_image'];
            if ((int) $upload['size'] > 8 * 1024 * 1024) {
                admin_redirect('image-too-large');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
            ];
            if (!isset($extensions[$mime])) {
                admin_redirect('image-format');
            }
            $uploadDir = dirname(__DIR__) . '/assets/uploads';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                admin_redirect('image-error');
            }
            $filename = 'home-hero-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
            if (!move_uploaded_file((string) $upload['tmp_name'], $uploadDir . '/' . $filename)) {
                admin_redirect('image-error');
            }
            chmod($uploadDir . '/' . $filename, 0644);
            $content = cms_content(true);
            $content['home.hero_image'] = 'assets/uploads/' . $filename;
            if (!admin_save_json(cms_content_file(), $content)) {
                admin_redirect('save-error');
            }
            admin_redirect('image-saved');
        }

        if ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            if (!password_verify($currentPassword, (string) $config['password_hash'])) {
                admin_redirect('password-current');
            }
            if (mb_strlen($newPassword, 'UTF-8') < 14 || $newPassword !== $confirmPassword) {
                admin_redirect('password-invalid');
            }
            $newConfig = [
                'username' => (string) $config['username'],
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ];
            $php = "<?php\nreturn " . var_export($newConfig, true) . ";\n";
            $temporary = $configFile . '.tmp-' . bin2hex(random_bytes(5));
            if (file_put_contents($temporary, $php, LOCK_EX) === false || !rename($temporary, $configFile)) {
                admin_redirect('password-error');
            }
            chmod($configFile, 0600);
            admin_redirect('password-saved');
        }
    }
}

$statusMessages = [
    'welcome' => ['success', 'Вход выполнен. Все изменения публикуются сразу после сохранения.'],
    'saved' => ['success', 'Изменения сохранены и уже опубликованы на сайте.'],
    'image-saved' => ['success', 'Новое изображение загружено и опубликовано.'],
    'password-saved' => ['success', 'Пароль администратора изменён.'],
    'save-error' => ['error', 'Не удалось записать изменения. Проверьте права доступа к хранилищу.'],
    'image-error' => ['error', 'Не удалось загрузить изображение.'],
    'image-too-large' => ['error', 'Файл слишком большой. Максимум — 8 МБ.'],
    'image-format' => ['error', 'Поддерживаются JPG, PNG, WebP и AVIF.'],
    'password-current' => ['error', 'Текущий пароль указан неверно.'],
    'password-invalid' => ['error', 'Новый пароль должен содержать не менее 14 символов и совпадать с подтверждением.'],
    'password-error' => ['error', 'Не удалось изменить пароль.'],
    'csrf-error' => ['error', 'Сессия устарела. Обновите страницу и повторите действие.'],
];
$status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$message = $statusMessages[$status] ?? null;
$content = cms_content(true);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="color-scheme" content="light">
  <title><?= $loggedIn ? 'Управление сайтом' : 'Вход' ?> — <?= admin_h(cms_value('site.company')) ?></title>
  <link rel="stylesheet" href="admin.css?v=2">
</head>
<body class="<?= $loggedIn ? 'dashboard-page' : 'login-page' ?>">
<?php if (!$loggedIn): ?>
  <main class="login-shell">
    <section class="login-card">
      <div class="login-brand"><span><?= admin_h(mb_substr(cms_value('site.mark'), 0, 2, 'UTF-8')) ?></span><div><strong><?= admin_h(cms_value('site.company')) ?></strong><small>Управление сайтом</small></div></div>
      <div class="login-copy"><p class="kicker">ЗАКРЫТАЯ ЗОНА</p><h1>Вход в админ-панель</h1><p>Введите данные администратора, чтобы изменить тексты, контакты, SEO и изображения.</p></div>
      <?php if ($loginError !== ''): ?><div class="notice error"><?= admin_h($loginError) ?></div><?php endif; ?>
      <form method="post" class="login-form" autocomplete="on">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= admin_h((string) $_SESSION['csrf']) ?>">
        <label><span>Логин</span><input name="username" autocomplete="username" required autofocus></label>
        <label><span>Пароль</span><input name="password" type="password" autocomplete="current-password" required></label>
        <button class="primary-button" type="submit">Войти <span>→</span></button>
      </form>
      <a class="back-site" href="../">← Вернуться на сайт</a>
    </section>
  </main>
<?php else: ?>
  <div class="dashboard">
    <aside class="sidebar">
      <a class="sidebar-brand" href="./"><span><?= admin_h(mb_substr(cms_value('site.mark'), 0, 2, 'UTF-8')) ?></span><div><strong><?= admin_h(cms_value('site.company')) ?></strong><small>Админ-панель</small></div></a>
      <div class="sidebar-search"><label for="section-search">Поиск настроек</label><input id="section-search" type="search" placeholder="Например, телефон"></div>
      <nav class="sidebar-nav" aria-label="Разделы настроек">
        <?php foreach (cms_sections() as $section): ?>
          <a href="#<?= admin_h($section['id']) ?>"><?= admin_h($section['label']) ?></a>
        <?php endforeach; ?>
        <a href="#security">Безопасность</a>
      </nav>
      <div class="sidebar-actions">
        <a href="../" target="_blank" rel="noopener">Открыть сайт ↗</a>
        <form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= admin_h((string) $_SESSION['csrf']) ?>"><button type="submit">Выйти</button></form>
      </div>
    </aside>

    <main class="content">
      <header class="topbar">
        <div><p class="kicker">CMS / <?= admin_h(cms_value('site.company')) ?></p><h1>Управление сайтом</h1><p>Изменения публикуются сразу после сохранения.</p></div>
        <a class="preview-button" href="../" target="_blank" rel="noopener">Предпросмотр ↗</a>
      </header>

      <?php if ($message): ?><div class="notice <?= admin_h($message[0]) ?>"><?= admin_h($message[1]) ?></div><?php endif; ?>

      <section class="summary-grid" aria-label="Сводка">
        <article><span>СТРАНИЦЫ</span><strong>3</strong><p>Главная, банкротство и ДТП</p></article>
        <article><span>ПОЛЯ</span><strong><?= count(cms_fields()) ?></strong><p>Тексты, контакты и SEO</p></article>
        <article><span>ИНДЕКСАЦИЯ</span><strong><?= cms_value('seo.indexing') === '1' ? 'ВКЛ' : 'ВЫКЛ' ?></strong><p><?= cms_value('seo.indexing') === '1' ? 'Сайт открыт поисковикам' : 'Сайт закрыт от поисковиков' ?></p></article>
      </section>

      <section class="panel image-panel" id="images" data-searchable="изображение фото первый экран hero">
        <div class="panel-heading"><div><p class="kicker">МЕДИА</p><h2>Изображения сайта</h2><p>Сейчас на сайте используется одно фотографическое изображение — фон первого экрана.</p></div></div>
        <div class="image-editor">
          <div class="image-preview"><img src="../<?= admin_h(ltrim(cms_value('home.hero_image'), '/')) ?>" alt="Текущее фото первого экрана"></div>
          <form method="post" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="action" value="upload_image">
            <input type="hidden" name="csrf" value="<?= admin_h((string) $_SESSION['csrf']) ?>">
            <label><span>Фото первого экрана</span><input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp,image/avif" required></label>
            <p>JPG, PNG, WebP или AVIF, до 8 МБ. Рекомендуемый размер — от 1800×1100 px.</p>
            <button class="secondary-button" type="submit">Загрузить и опубликовать</button>
          </form>
        </div>
      </section>

      <form method="post" class="settings-form">
        <input type="hidden" name="action" value="save_content">
        <input type="hidden" name="csrf" value="<?= admin_h((string) $_SESSION['csrf']) ?>">
        <?php foreach (cms_sections() as $section): ?>
          <?php $visibleFields = array_values(array_filter($section['fields'], static fn(array $field): bool => $field['type'] !== 'image')); ?>
          <?php if (!$visibleFields) continue; ?>
          <section class="panel settings-section" id="<?= admin_h($section['id']) ?>" data-searchable="<?= admin_h($section['label'] . ' ' . $section['description'] . ' ' . implode(' ', array_column($visibleFields, 'label'))) ?>">
            <div class="panel-heading"><div><p class="kicker">РАЗДЕЛ</p><h2><?= admin_h($section['label']) ?></h2><p><?= admin_h($section['description']) ?></p></div><span><?= count($visibleFields) ?> полей</span></div>
            <div class="field-grid">
              <?php foreach ($visibleFields as $field): ?>
                <?php $value = (string) ($content[$field['key']] ?? $field['default']); ?>
                <?php if ($field['type'] === 'checkbox'): ?>
                  <label class="toggle-field full-field">
                    <input type="checkbox" name="content[<?= admin_h($field['key']) ?>]" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                    <span class="toggle"></span><span><strong><?= admin_h($field['label']) ?></strong><?php if ($field['help'] !== ''): ?><small><?= admin_h($field['help']) ?></small><?php endif; ?></span>
                  </label>
                <?php else: ?>
                  <label class="control <?= $field['type'] === 'textarea' ? 'full-field' : '' ?>">
                    <span><?= admin_h($field['label']) ?></span>
                    <?php if ($field['type'] === 'textarea'): ?>
                      <textarea name="content[<?= admin_h($field['key']) ?>]" rows="3"><?= admin_h($value) ?></textarea>
                    <?php else: ?>
                      <input type="<?= $field['type'] === 'url' ? 'url' : 'text' ?>" name="content[<?= admin_h($field['key']) ?>]" value="<?= admin_h($value) ?>">
                    <?php endif; ?>
                    <?php if ($field['help'] !== ''): ?><small><?= admin_h($field['help']) ?></small><?php endif; ?>
                  </label>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
        <div class="save-bar"><span>Проверьте изменения перед публикацией.</span><button class="primary-button" type="submit">Сохранить и опубликовать <span>→</span></button></div>
      </form>

      <section class="panel" id="security" data-searchable="безопасность пароль сменить">
        <div class="panel-heading"><div><p class="kicker">БЕЗОПАСНОСТЬ</p><h2>Сменить пароль</h2><p>Используйте уникальный пароль длиной не менее 14 символов.</p></div></div>
        <form method="post" class="password-form">
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="csrf" value="<?= admin_h((string) $_SESSION['csrf']) ?>">
          <label class="control"><span>Текущий пароль</span><input type="password" name="current_password" autocomplete="current-password" required></label>
          <label class="control"><span>Новый пароль</span><input type="password" name="new_password" autocomplete="new-password" minlength="14" required></label>
          <label class="control"><span>Повторите новый пароль</span><input type="password" name="confirm_password" autocomplete="new-password" minlength="14" required></label>
          <button class="secondary-button" type="submit">Изменить пароль</button>
        </form>
      </section>
    </main>
  </div>
  <script src="admin.js?v=1"></script>
<?php endif; ?>
</body>
</html>
