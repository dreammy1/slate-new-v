# Deploying Slate to 192.168.10.20

This package is a full copy of your Slate site, pre-configured for the new
server. Follow these steps on the new device.

## 1. Create the database and user

On the new server's MySQL/MariaDB, run (adjust if `admin` user already exists):

```sql
CREATE DATABASE IF NOT EXISTS admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'admin'@'localhost' IDENTIFIED BY 'admin';
GRANT ALL PRIVILEGES ON admin.* TO 'admin'@'localhost';
FLUSH PRIVILEGES;
```

## 2. Import your data

Import the `database_export.sql` file that came with this package (or that
you were given alongside it):

```bash
mysql -u admin -padmin -h localhost admin < database_export.sql
```

This brings over every page, booking, customer, plugin setting, and
uploaded-file record from your current install — nothing is left behind.

## 3. Extract this zip to your web root

Extract the whole zip so its contents (this README, `config.php`, `admin/`,
`plugins/`, `uploads/`, etc.) sit directly at your web server's document
root — i.e. `http://192.168.10.20/` should resolve straight to `index.php`
in this folder, not a subfolder.

## 4. Check `.env`

A `.env` file is already included and pre-filled for this server:

- `APP_URL=http://192.168.10.20`
- `DB_HOST=localhost`, `DB_NAME=admin`, `DB_USER=admin`, `DB_PASS=admin`
- `APP_SECRET` / `CRON_SECRET` are copied **unchanged** from your current
  install on purpose — these keys encrypt things like stored Stripe keys and
  Google Calendar OAuth tokens in the database. Keeping them identical means
  everything that was encrypted on your old install still decrypts
  correctly on the new one. **Do not regenerate them** unless you're fine
  re-entering Stripe/Google Calendar credentials by hand afterward.

If the new server needs a different `DB_HOST`/port (e.g. a remote MySQL box
instead of `localhost`), edit `.env` accordingly before first load.

## 5. File permissions

Make sure the web server user can write to `uploads/` (this is where
branding logos, product/recipe photos, form file-uploads, etc. live — all
of it is already included in this package under `uploads/`):

```bash
chmod -R 755 uploads
```

## 6. Web server

Point Apache/Nginx + PHP-FPM at this folder as the document root, with the
existing `.htaccess` (Apache) or an equivalent Nginx rewrite config
(`try_files ... /index.php?...`) so Slate's own router handles requests.
PHP 8.1+ with the usual `pdo_mysql`, `mbstring`, `gd`/`imagick`, `curl`,
`openssl` extensions is required — matching your current local install.

## 7. First load

Visit `http://192.168.10.20/admin/` and sign in with the same admin
credentials you already use — accounts, passwords, and everything else came
over in the database import in step 2. Nothing needs to be re-created.

## Notes

- This package does **not** include a database file inside the zip itself —
  `database_export.sql` is provided as a separate file (a `mysqldump` of
  your live database) so you can transfer it however's convenient (USB,
  network share, etc.) alongside this zip.
- If your new server's MySQL is version-mismatched with your current one in
  a way that rejects the dump, re-export with
  `mysqldump --no-tablespaces -u root -h 127.0.0.1 slate > database_export.sql`
  and re-import the same way.
