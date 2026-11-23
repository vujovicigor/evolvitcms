# EvolvitCMS

Lightweight PHP + SQLite CMS (Twig templates, Ractive admin in `cms/admin/`).

## Installing on shared hosting (no terminal / Composer needed)
1. Download `evolvitcms-<version>.zip` from the [Releases page](https://github.com/vujovicigor/evolvitcms/releases).
   It already contains everything, including Twig (`cms/vendor`) and the built admin UI.
2. Upload the zip with your host's file manager (cPanel, Plesk, DirectAdmin...) into the folder the site should
   live in (e.g. `public_html/`, or a subfolder such as `public_html/blog/`) and choose *Extract*.
   The zip has no wrapper folder: its files go straight into that folder.
   Make sure hidden files (`.htaccess`) were extracted too.
3. Open `https://your-domain/cms/admin/` and **immediately** create the administrator account
   (until an account exists, anybody could create it).
4. That's it: edit content in the admin, templates live in `twigtemplates/`.

If you see "Composer detected issues in your platform", switch the site to PHP 8.1 or newer in your hosting panel.

## Templates
Pages are Twig 3 templates in `twigtemplates/` (`about.twig` → `/about`), content comes from `fetch("Table")`.
Step-by-step guide: https://evolvitcms.com/doc/twig-templates

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

## Releasing
`git archive --format=zip -o evolvitcms-vX.Y.Z.zip HEAD` (dev-only files are excluded via `.gitattributes`), then attach the
zip to the GitHub release **twice**: as `evolvitcms-vX.Y.Z.zip` and as `evolvitcms.zip`. The second, version-less name
keeps `https://github.com/vujovicigor/evolvitcms/releases/latest/download/evolvitcms.zip` pointing at the newest release.
