<?php

declare(strict_types=1);

use App\Core\Install\DatabaseUrl;
use App\Core\Install\InstallCleaner;
use App\Core\Install\InstallGate;
use App\Core\Install\InstallRunner;
use App\Core\Install\RequirementChecker;

$projectDir = dirname(__DIR__, 2);

require_once $projectDir.'/cp-includes/vendor/autoload.php';

if (InstallGate::shouldBootApplication($projectDir)) {
    header('Location: ./', true, 302);
    exit;
}

$sessionDir = $projectDir.'/cp-core/var/install-sessions';
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
    http_response_code(500);
    echo 'Cannot create the installer session directory.';
    exit;
}

session_name('CPALIUS_INSTALL');
session_save_path($sessionDir);
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$messages = require __DIR__.'/messages.php';
if (isset($_GET['lang']) && isset($messages[(string) $_GET['lang']])) {
    $_SESSION['lang'] = (string) $_GET['lang'];
}
if (!isset($_SESSION['lang'])) {
    $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $_SESSION['lang'] = str_starts_with(strtolower($accept), 'en') ? 'en' : 'tr';
}

$lang = (string) $_SESSION['lang'];
$t = $messages[$lang];
$step = (string) ($_POST['step'] ?? $_GET['step'] ?? 'requirements');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sent = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], $sent)) {
        $error = $t['csrf'];
        $step = (string) ($_POST['step'] ?? 'requirements');
    } else {
        $error = cp_install_handle_post($projectDir, $step, $t);
    }
}

if ($step === 'done') {
    cp_install_render_done($projectDir, $lang, $t);
    exit;
}

$checker = new RequirementChecker();
$checks = $checker->check($projectDir);
$ready = $checker->passes($checks);
if (!$ready && $step !== 'requirements') {
    $step = 'requirements';
}

ob_start();
if ($error !== '') {
    echo '<p class="error">'.htmlspecialchars($error, ENT_QUOTES, 'UTF-8').'</p>';
}
cp_install_render_step($step, $t, $checks, $ready, (string) $_SESSION['csrf']);
$body = (string) ob_get_clean();
$title = $t['title'];
require __DIR__.'/views/layout.php';

/**
 * @param array<string, string> $t
 */
function cp_install_handle_post(string $projectDir, string &$step, array $t): string
{
    try {
        if ($step === 'requirements') {
            $checker = new RequirementChecker();
            if (!$checker->passes($checker->check($projectDir))) {
                return $t['requirements_bad'];
            }
            $step = 'database';

            return '';
        }

        if ($step === 'database') {
            $probe = (new DatabaseUrl())->probe([
                'host' => trim((string) ($_POST['db_host'] ?? '')),
                'port' => (int) ($_POST['db_port'] ?? 3306),
                'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => (string) ($_POST['db_user'] ?? ''),
                'password' => (string) ($_POST['db_password'] ?? ''),
            ]);
            $_SESSION['database_url'] = $probe['url'];
            $_SESSION['db_host'] = trim((string) $_POST['db_host']);
            $_SESSION['db_port'] = (string) (int) $_POST['db_port'];
            $_SESSION['db_name'] = trim((string) $_POST['db_name']);
            $_SESSION['db_user'] = (string) $_POST['db_user'];
            $step = 'site';

            return '';
        }

        if ($step === 'site') {
            $password = (string) ($_POST['password'] ?? '');
            if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
                return $t['password_mismatch'];
            }

            if (!isset($_SESSION['database_url']) || !\is_string($_SESSION['database_url'])) {
                $step = 'database';

                return $t['db_help'];
            }

            $config = [
                'databaseUrl' => $_SESSION['database_url'],
                'siteName' => trim((string) ($_POST['site_name'] ?? '')),
                'siteUrl' => rtrim(trim((string) ($_POST['site_url'] ?? '')), '/'),
                'locale' => (string) ($_POST['locale'] ?? 'tr') === 'en' ? 'en' : 'tr',
                'timezone' => (string) ($_POST['timezone'] ?? 'Europe/Istanbul'),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'username' => trim((string) ($_POST['username'] ?? '')),
                'password' => $password,
                'displayName' => trim((string) ($_POST['display_name'] ?? '')),
            ];

            $runner = new InstallRunner();
            $runner->assertConfig($config);
            session_write_close();
            ignore_user_abort(true);
            set_time_limit(0);
            $result = $runner->run($projectDir, $config);
            $_SESSION['install_result'] = $result + ['siteUrl' => $config['siteUrl']];
            $step = 'done';

            return '';
        }
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    $step = 'requirements';

    return '';
}

/**
 * @param array<string, string> $t
 * @param list<array{id: string, ok: bool, severity: string, label: string, detail: string}> $checks
 */
function cp_install_render_step(string $step, array $t, array $checks, bool $ready, string $csrf): void
{
    echo '<p class="lead">'.htmlspecialchars($t['intro'], ENT_QUOTES, 'UTF-8').'</p>';

    if ($step === 'database') {
        cp_install_database_form($t, $csrf);

        return;
    }

    if ($step === 'site') {
        cp_install_site_form($t, $csrf);

        return;
    }

    echo '<p>'.htmlspecialchars($ready ? $t['requirements_ok'] : $t['requirements_bad'], ENT_QUOTES, 'UTF-8').'</p>';
    echo '<table>';
    foreach ($checks as $check) {
        $class = $check['severity'] === 'fail' ? 'fail' : ($check['severity'] === 'warn' ? 'warn' : 'ok');
        echo '<tr><td>'.htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="'.$class.'">'.htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8').'</td></tr>';
    }
    echo '</table>';
    if ($ready) {
        echo '<form method="post"><input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';
        echo '<input type="hidden" name="step" value="requirements">';
        echo '<button type="submit">'.htmlspecialchars($t['continue'], ENT_QUOTES, 'UTF-8').'</button></form>';
    }
}

/**
 * @param array<string, string> $t
 */
function cp_install_database_form(array $t, string $csrf): void
{
    $host = htmlspecialchars((string) ($_SESSION['db_host'] ?? '127.0.0.1'), ENT_QUOTES, 'UTF-8');
    $port = htmlspecialchars((string) ($_SESSION['db_port'] ?? '3306'), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string) ($_SESSION['db_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $user = htmlspecialchars((string) ($_SESSION['db_user'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<p class="lead">'.htmlspecialchars($t['db_help'], ENT_QUOTES, 'UTF-8').'</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';
    echo '<input type="hidden" name="step" value="database">';
    echo '<div class="row"><div><label>'.htmlspecialchars($t['db_host'], ENT_QUOTES, 'UTF-8').'</label><input name="db_host" required value="'.$host.'"></div>';
    echo '<div><label>'.htmlspecialchars($t['db_port'], ENT_QUOTES, 'UTF-8').'</label><input name="db_port" required value="'.$port.'" inputmode="numeric"></div></div>';
    echo '<label>'.htmlspecialchars($t['db_name'], ENT_QUOTES, 'UTF-8').'</label><input name="db_name" required value="'.$name.'">';
    echo '<label>'.htmlspecialchars($t['db_user'], ENT_QUOTES, 'UTF-8').'</label><input name="db_user" required value="'.$user.'" autocomplete="off">';
    echo '<label>'.htmlspecialchars($t['db_password'], ENT_QUOTES, 'UTF-8').'</label><input name="db_password" type="password" autocomplete="new-password">';
    echo '<div class="actions"><a class="button ghost" href="?step=requirements">'.htmlspecialchars($t['back'], ENT_QUOTES, 'UTF-8').'</a>';
    echo '<button type="submit">'.htmlspecialchars($t['continue'], ENT_QUOTES, 'UTF-8').'</button></div></form>';
}

/**
 * @param array<string, string> $t
 */
function cp_install_site_form(array $t, string $csrf): void
{
    $url = htmlspecialchars(cp_install_guess_url(), ENT_QUOTES, 'UTF-8');
    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';
    echo '<input type="hidden" name="step" value="site">';
    echo '<label>'.htmlspecialchars($t['site_name'], ENT_QUOTES, 'UTF-8').'</label><input name="site_name" required maxlength="180">';
    echo '<label>'.htmlspecialchars($t['site_url'], ENT_QUOTES, 'UTF-8').'</label><input name="site_url" required value="'.$url.'">';
    echo '<div class="row"><div><label>'.htmlspecialchars($t['locale'], ENT_QUOTES, 'UTF-8').'</label><select name="locale"><option value="tr">Türkçe</option><option value="en">English</option></select></div>';
    echo '<div><label>'.htmlspecialchars($t['timezone'], ENT_QUOTES, 'UTF-8').'</label><select name="timezone">';
    foreach (InstallRunner::TIMEZONES as $zone) {
        $selected = $zone === 'Europe/Istanbul' ? ' selected' : '';
        echo '<option value="'.htmlspecialchars($zone, ENT_QUOTES, 'UTF-8').'"'.$selected.'>'.htmlspecialchars($zone, ENT_QUOTES, 'UTF-8').'</option>';
    }
    echo '</select></div></div>';
    echo '<label>'.htmlspecialchars($t['admin_email'], ENT_QUOTES, 'UTF-8').'</label><input name="email" type="email" required>';
    echo '<label>'.htmlspecialchars($t['admin_user'], ENT_QUOTES, 'UTF-8').'</label><input name="username" required minlength="3" maxlength="180" pattern="[A-Za-z0-9_.\\-]{3,180}">';
    echo '<label>'.htmlspecialchars($t['admin_display'], ENT_QUOTES, 'UTF-8').'</label><input name="display_name" maxlength="180">';
    echo '<label>'.htmlspecialchars($t['admin_password'], ENT_QUOTES, 'UTF-8').'</label><input name="password" type="password" required autocomplete="new-password">';
    echo '<label>'.htmlspecialchars($t['admin_password_2'], ENT_QUOTES, 'UTF-8').'</label><input name="password_confirm" type="password" required autocomplete="new-password">';
    echo '<p class="lead">'.htmlspecialchars($t['password_hint'], ENT_QUOTES, 'UTF-8').'</p>';
    echo '<p class="lead">'.htmlspecialchars($t['installing'], ENT_QUOTES, 'UTF-8').'</p>';
    echo '<div class="actions"><a class="button ghost" href="?step=database">'.htmlspecialchars($t['back'], ENT_QUOTES, 'UTF-8').'</a>';
    echo '<button type="submit">'.htmlspecialchars($t['install'], ENT_QUOTES, 'UTF-8').'</button></div></form>';
}

/**
 * @param array<string, string> $t
 */
function cp_install_render_done(string $projectDir, string $lang, array $t): void
{
    $result = $_SESSION['install_result'] ?? null;
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    $siteUrl = \is_array($result) ? (string) ($result['siteUrl'] ?? '') : '';
    $recovery = \is_array($result) ? (string) ($result['recoveryToken'] ?? '') : '';
    $cron = \is_array($result) ? (string) ($result['cronToken'] ?? '') : '';
    $login = ($siteUrl !== '' ? $siteUrl : '').'/login';

    ob_start();
    echo '<h2>'.htmlspecialchars($t['done_title'], ENT_QUOTES, 'UTF-8').'</h2>';
    echo '<p>'.htmlspecialchars($t['done_body'], ENT_QUOTES, 'UTF-8').'</p>';
    echo '<p class="lead">'.htmlspecialchars($t['tokens'], ENT_QUOTES, 'UTF-8').'</p>';
    echo '<p><strong>'.htmlspecialchars($t['recovery'], ENT_QUOTES, 'UTF-8').'</strong><br><code>'.htmlspecialchars($recovery, ENT_QUOTES, 'UTF-8').'</code></p>';
    echo '<p><strong>'.htmlspecialchars($t['cron'], ENT_QUOTES, 'UTF-8').'</strong><br><code>'.htmlspecialchars($cron, ENT_QUOTES, 'UTF-8').'</code></p>';
    echo '<p class="actions">';
    if ($siteUrl !== '') {
        echo '<a class="button" href="'.htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8').'/">'.htmlspecialchars($t['open_site'], ENT_QUOTES, 'UTF-8').'</a>';
    }
    echo '<a class="button" href="'.htmlspecialchars($login, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($t['open_admin'], ENT_QUOTES, 'UTF-8').'</a>';
    echo '</p>';
    $body = (string) ob_get_clean();
    $title = $t['title'];
    $step = 'done';
    ob_start();
    require __DIR__.'/views/layout.php';
    $html = (string) ob_get_clean();

    $leftover = (new InstallCleaner())->cleanup($projectDir);
    if ($leftover !== []) {
        $note = '<p class="error">'.htmlspecialchars($t['leftover'], ENT_QUOTES, 'UTF-8').'</p><ul>';
        foreach ($leftover as $path) {
            $note .= '<li><code>'.htmlspecialchars($path, ENT_QUOTES, 'UTF-8').'</code></li>';
        }
        $note .= '</ul>';
        $html = str_replace('</section>', $note.'</section>', $html);
    }

    echo $html;
}

function cp_install_guess_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(dirname($script), '/');
    if (str_ends_with($base, '/public')) {
        $base = substr($base, 0, -7);
    }
    if ($base === '/') {
        $base = '';
    }

    return ($https ? 'https' : 'http').'://'.$host.$base;
}
