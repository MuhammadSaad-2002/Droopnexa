# DroopNexa — cPanel production build

This branch contains the compiled frontend and Laravel runtime dependencies. Hosting requires PHP **8.3**, MySQL, Apache rewrite support. It does not require Node.js or Composer on the server.

## First deployment

1. Set `droopnexa.vwisdomtechnologies.com` to PHP 8.3 in cPanel MultiPHP Manager. Enable the standard Laravel PHP extensions, including `pdo_mysql`, `mbstring`, `fileinfo`, `xml`, and `curl`.
2. In Git Version Control, use branch `main` and click **Update from Remote**. The repository/document-root path is `/home2/vwisdomo/droopnexa.vwisdomtechnologies.com`.
3. Click **Deploy HEAD Commit**. On its first run, the script creates `/home2/vwisdomo/droopnexa-private/.env` and stops with a configuration message.
4. In File Manager, enable hidden files and edit that private `.env`. Set `DB_PASSWORD` to your database password. The database/user are prefilled as `vwisdomo_droopnexa`; ensure that user has permissions on that database. Keep `APP_DEBUG=false`.
5. Click **Deploy HEAD Commit** again. It generates the application key once, applies migrations, builds Laravel caches, and links public uploads. A successful run ends with `DroopNexa deployed with PHP 8.3`.
6. Check the homepage, a product detail URL, login, and `/api/v1/products`.

If the original empty clone does not select a branch, use cPanel Terminal in the repository: `git fetch origin` followed by `git checkout main`.

## Existing data and first accounts

Deployment preserves the private `.env`, application key, uploaded files, and database records. It never runs `migrate:fresh`, resets the database, or creates demo accounts, orders, or wallet balances.

For a new, empty database only, you can load the starter catalogue in cPanel Terminal:

```sh
cd /home2/vwisdomo/droopnexa-private
/opt/cpanel/ea-php83/root/usr/bin/php artisan db:seed --class=ProductSeeder --force
```

Do not run the default `DatabaseSeeder` in production. The catalogue-only seeder can overwrite matching product data, so do not run it on an established catalogue.

To establish the first administrator, register your own account on the website. Then, in the private application directory, run `/opt/cpanel/ea-php83/root/usr/bin/php artisan tinker` and promote only your registered email:

```php
$user = App\Models\User::where('email', 'YOUR_REGISTERED_EMAIL')->firstOrFail();
$user->update(['role' => 'admin']);
```

Log out and sign in again to open the admin portal. No shared administrator password is shipped.

## Later releases

Use **Update from Remote**, then **Deploy HEAD Commit**. Do not edit tracked files in the cPanel clone. Keep all environment changes in the private `.env` outside the repository. PHP dependencies are included under the blocked `.release` directory and copied outside the document root during deployment.

The release frontend runs in the browser and shares the same React screens as the local Next.js application. New product slugs and portal record URLs resolve at runtime without a frontend rebuild. Public page content requires JavaScript; this build does not provide Next.js server rendering.

Build source remains in the development workspace. Generate a fresh release with `bash scripts/build-cpanel.sh` from the workspace root, then commit the contents of `deploy-build/` to this deployment branch. Never add local `.env` files, development databases, or uploaded customer files.
