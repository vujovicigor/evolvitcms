# EvolvitCMS

Lightweight PHP + SQLite CMS (Twig templates, Ractive admin in `cms/admin/`).

## Requirements
* PHP >= 8.1 (tested on 8.5) with `pdo_sqlite`, `gd`, `fileinfo`, `curl`, `intl` (optional)
* Apache with `mod_rewrite` (`AllowOverride All`) – the `.htaccess` files are part of the security model
  (they block direct access to `cms/db`, `cms/uploads`, `cms/vendor`, `twigtemplates`, ...).
  On other web servers replicate those deny rules.
* Writable by the web server: `cms/db/`, `cms/uploads/`, `cms/twigcache/`, `imgcache/`

## First run
No account is shipped. Open `/cms/admin/` and create the first (system administrator) account,
**do this immediately after deploying** – until an account exists anybody can create it.

## Uploaded files
Uploads are stored on disk in `cms/uploads/` (random file names, not directly web accessible) and served
through `image.php` / `img/<slug>`. Only metadata lives in `userfiles._files`. Databases created by older
versions that still hold file blobs are migrated to disk automatically on first request.

## Configuration
Copy `cms/config.sample.php` to `cms/config.php` (git-ignored) to enable debug output, set the Google sign-in
client id(s) accepted for comments, or tune login throttling.

## Security notes
* Stored queries flagged `eval` are no longer executed as PHP (`_recreate_user_table` is implemented natively).
* SQL can not ATTACH/DETACH databases, VACUUM INTO or run writable PRAGMAs (SQLite authorizer).
* Schema editing is system-administrator only; users can not grant roles above their own.
* API writes require POST from the same origin; session cookies are HttpOnly/SameSite=Strict (+Secure on HTTPS).
