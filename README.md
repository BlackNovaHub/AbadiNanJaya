<p align="center">
  <img src="https://raw.githubusercontent.com/BlackNovaHub/AbadiNanJaya/main/icon.png" alt="ABADI NAN JAYA" width="420"/>
</p>

<h1 align="center">ABADI NAN JAYA</h1>
<p align="center">
  <b>Single-file PHP File Manager</b><br/>
  Dark toxic-green UI · multi-auth · tools · WordPress helper
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-5.6%2B-777BB4?logo=php&logoColor=white"/>
  <img alt="License" src="https://img.shields.io/badge/use-authorized%20only-c0f000"/>
  <img alt="Single file" src="https://img.shields.io/badge/deploy-1%20file%20index.php-111"/>
</p>

---

## Features

| Module | Description |
|--------|-------------|
| **File Manager** | Browse, upload, download, edit, rename, move, copy, delete, chmod |
| **PWD bar** | Clickable path segments — jump folders fast |
| **Terminal** | Shell via PHP functions (`proc_open` → `exec` …) with `disable_functions` fallback |
| **Encode / Decode** | Base64, URL, HTML, Hex, MD5/SHA, JSON, ROT13 |
| **Adminer** | Auto-download Adminer and open DB UI |
| **Sysinfo / PHP Info** | Server & PHP diagnostics |
| **WP Admin** | Scan `DOCUMENT_ROOT` for `wp-config.php`, create admin user, auto-login form |
| **Settings** | Switch auth mode (saved into `index.php`) |

---

## Requirements

- PHP **5.6+** (7.4+ recommended)
- Apache / Nginx / LiteSpeed / any PHP host
- Optional: **PDO MySQL** (WordPress Admin & SQL tools)
- Optional: `curl` / `allow_url_fopen` (Adminer install, 404 probe)

Works on **Windows · Linux · macOS**.

---

## Install

1. Upload **`index.php`** to your web directory  
2. Open it in the browser  
3. Login with defaults below (or edit config at top of `index.php`)

```text
your-domain.com/index.php
# or
your-domain.com/abadinanjaya/
```

---

## Default login

| Field | Value |
|-------|-------|
| **Mode** | `userpass` (Username + Password) |
| **Username** | `abadinanjaya` |
| **Password** | `abadinanjayateam` |

> Password is stored as **MD5** inside `index.php`. Type the plain password when logging in.

### Other auth modes

Edit near the top of `index.php`:

```php
define('FM_AUTH_MODE', 'userpass'); // none | cookie | password | userpass
define('FM_AUTH_USER', 'abadinanjaya');
define('FM_AUTH_PASS', '...');      // MD5 for password/userpass
define('FM_COOKIE_NAME', 'abadinanjaya');
define('FM_COOKIE_VALUE', 'abadinanjayateam');
```

| Mode | Behavior |
|------|----------|
| `none` | Open access — no login page |
| `cookie` | Pure host **404** until cookie is set |
| `password` | Custom password-only login page (session) |
| `userpass` | Custom username + password page (session) |

Or use **Tools → Settings** (writes defines back into `index.php`).

---

## Tools quick guide

1. **Terminal** — run commands in current folder (uses available PHP exec functions only)  
2. **Encode / Decode** — text transforms & hashes  
3. **Adminer** — auto install + redirect to `adminer.php`  
4. **Sysinfo / PHP Info** — environment  
5. **WP Admin** — find WordPress under `DOCUMENT_ROOT`, create administrator, POST login to `wp-login.php`  
6. **Settings** — auth mode & credentials  

---

## Security notes

- Change default username/password after install  
- Use HTTPS in production  
- Restrict access by IP / basic auth if exposed publicly  
- **Authorized use only** — do not use on systems you do not own  

---

## Project layout

```text
AbadiNanJaya/
├── index.php      # full application (single file)
├── icon.png       # brand icon
├── README.md
└── .gitignore
```

---

## Links

- Repo: https://github.com/BlackNovaHub/AbadiNanJaya  
- Icon: https://raw.githubusercontent.com/BlackNovaHub/AbadiNanJaya/main/icon.png  

---

<p align="center">
  <b>ABADI NAN JAYA</b> · BlackNovaHub
</p>