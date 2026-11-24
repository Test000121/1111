
<?php
declare(strict_types=1);

// Private storage dashboard configuration.
$STORE = getenv('STORE') ?: (sys_get_temp_dir() . '/drops');
$DASH_PASSWORD = getenv('DASH_PASSWORD') ?: '';

if ($DASH_PASSWORD === '') {
    http_response_code(503);
    exit('Dashboard password not configured');
}

// Secure browser session.
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Strict');

session_start();

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function valid_filename(string $name): bool {
    return basename($name) === $name
        && preg_match('/^[A-Za-z0-9_.-]+\.txt$/', $name) === 1;
}

function format_size(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return $bytes . ' bytes';
}

// Create a CSRF token for this session.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Logout.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['logout'])) {

    if (!hash_equals(
        $_SESSION['csrf'],
        (string)($_POST['csrf'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request');
    }

    $_SESSION = [];
    session_destroy();

    header('Location: manage.php');
    exit;
}

// Login.
if (empty($_SESSION['logged_in'])) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['login'])) {

        if (!hash_equals(
            $_SESSION['csrf'],
            (string)($_POST['csrf'] ?? '')
        )) {
            http_response_code(403);
            exit('Invalid request');
        }

        $password = (string)($_POST['password'] ?? '');

        if (hash_equals($DASH_PASSWORD, $password)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            header('Location: manage.php');
            exit;
        }

        $error = 'Incorrect password';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Storage Login</title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: Arial, sans-serif;
                background: #f1f4f8;
                display: grid;
                place-items: center;
                min-height: 100vh;
                margin: 0;
            }
            .login {
                background: white;
                padding: 30px;
                border-radius: 12px;
                width: min(360px, 92%);
                box-shadow: 0 5px 25px #0001;
            }
            input, button {
                width: 100%;
                padding: 13px;
                margin-top: 14px;
                border-radius: 6px;
            }
            input { border: 1px solid #ccc; }
            button {
                background: #1769e0;
                color: white;
                border: 0;
                cursor: pointer;
            }
            .error { color: #c62828; }
        </style>
    </head>
    <body>
        <form class="login" method="post">
            <h2>Storage Dashboard</h2>
            <p>Sign in to manage your stored files.</p>

            <?php if (isset($error)): ?>
                <p class="error"><?= h($error) ?></p>
            <?php endif; ?>

            <input type="hidden" name="csrf"
                   value="<?= h($_SESSION['csrf']) ?>">

            <input type="password"
                   name="password"
                   placeholder="Dashboard password"
                   autocomplete="current-password"
                   required>

            <button type="submit" name="login" value="1">
                Sign in
            </button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Make sure the storage directory exists.
if (!is_dir($STORE)) {
    @mkdir($STORE, 0750, true);
}

$storeRoot = realpath($STORE);

if ($storeRoot === false || !is_dir($storeRoot)) {
    http_response_code(500);
    exit('Storage unavailable');
}

// Delete a file.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete'])) {

    if (!hash_equals(
        $_SESSION['csrf'],
        (string)($_POST['csrf'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request');
    }

    $name = (string)$_POST['delete'];

    if (valid_filename($name)) {
        $candidate = $storeRoot . DIRECTORY_SEPARATOR . $name;
        $realFile = realpath($candidate);

        if (
            $realFile !== false &&
            dirname($realFile) === $storeRoot &&
            is_file($realFile) &&
            !is_link($candidate)
        ) {
            unlink($realFile);
        }
    }

    header('Location: manage.php');
    exit;
}

// Download a file.
if (isset($_GET['download'])) {

    $name = (string)$_GET['download'];

    if (!valid_filename($name)) {
        http_response_code(400);
        exit('Invalid filename');
    }

    $candidate = $storeRoot . DIRECTORY_SEPARATOR . $name;
    $realFile = realpath($candidate);

    if (
        $realFile === false ||
        dirname($realFile) !== $storeRoot ||
        !is_file($realFile) ||
        is_link($candidate)
    ) {
        http_response_code(404);
        exit('File not found');
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($realFile));

    readfile($realFile);
    exit;
}

// List files.
$files = [];

foreach (glob($storeRoot . '/*.txt') ?: [] as $path) {

    if (is_link($path) || !is_file($path)) {
        continue;
    }

    $name = basename($path);

    if (!valid_filename($name)) {
        continue;
    }

    $files[] = [
        'name' => $name,
        'size' => filesize($path),
        'date' => filemtime($path),
    ];
}

// Newest files first.
usort($files, fn($a, $b) => $b['date'] <=> $a['date']);

$total = array_sum(array_column($files, 'size'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Your Storage Dashboard</title>

<style>
* {
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f1f4f8;
    margin: 0;
    padding: 22px;
    color: #222;
}

.container {
    max-width: 1050px;
    margin: auto;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: 0 3px 15px #00000008;
}

.summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.stat {
    background: #f5f7fa;
    border-radius: 8px;
    padding: 17px;
    overflow-wrap: anywhere;
}

.stat small {
    color: #666;
    display: block;
    margin-bottom: 9px;
}

.stat strong {
    font-size: 17px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 13px 9px;
    border-bottom: 1px solid #eee;
    text-align: left;
    overflow-wrap: anywhere;
}

th {
    background: #f7f8fa;
}

a {
    color: #1769e0;
    text-decoration: none;
}

button {
    border: 0;
    border-radius: 5px;
    padding: 8px 11px;
    cursor: pointer;
}

.download {
    background: #1769e0;
    color: white;
}

.delete {
    background: #c62828;
    color: white;
}

.logout {
    background: #333;
    color: white;
}

.empty {
    color: #777;
    padding: 25px 0;
}

.footer {
    color: #777;
    font-size: 13px;
    margin-top: 20px;
}

@media(max-width: 650px) {
    body { padding: 10px; }
    .summary { grid-template-columns: 1fr; }
    th, td { font-size: 12px; padding: 8px 5px; }
}
</style>
</head>

<body>
<div class="container">

    <div class="header">
        <div>
            <h2>Your Storage Dashboard</h2>
            <p>Manage your uploaded files.</p>
        </div>

        <form method="post">
            <input type="hidden" name="csrf"
                   value="<?= h($_SESSION['csrf']) ?>">
            <button class="logout" type="submit"
                    name="logout" value="1">
                Logout
            </button>
        </form>
    </div>

    <div class="card">
        <h3>Storage overview</h3>

        <div class="summary">

            <div class="stat">
                <small>Files stored</small>
                <strong><?= count($files) ?> files</strong>
            </div>

            <div class="stat">
                <small>Your uploaded files</small>
                <strong><?= h(format_size($total)) ?></strong>
            </div>

            <div class="stat">
                <small>Storage directory</small>
                <strong><?= h($storeRoot) ?></strong>
            </div>

        </div>
    </div>

    <div class="card">
        <h3>Stored files</h3>

        <?php if (count($files) === 0): ?>

            <p class="empty">No files currently stored.</p>

        <?php else: ?>

            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Size</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($files as $file): ?>
                    <tr>
                        <td><?= h($file['name']) ?></td>

                        <td>
                            <?= h(format_size((int)$file['size'])) ?>
                        </td>

                        <td>
                            <?= date('Y-m-d H:i:s', $file['date']) ?>
                        </td>

                        <td>
                            <a class="download"
                               href="?download=<?= rawurlencode($file['name']) ?>">
                                Download
                            </a>

                            <form method="post"
                                  style="display:inline"
                                  onsubmit="return confirm('Delete this file permanently?')">

                                <input type="hidden"
                                       name="csrf"
                                       value="<?= h($_SESSION['csrf']) ?>">

                                <input type="hidden"
                                       name="delete"
                                       value="<?= h($file['name']) ?>">

                                <button class="delete" type="submit">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

        <?php endif; ?>
    </div>

    <div class="footer">
        Private storage dashboard.
        Files are stored in <?= h($storeRoot) ?>.
        Render Free storage is temporary.
    </div>

</div>
</body>
</html>
