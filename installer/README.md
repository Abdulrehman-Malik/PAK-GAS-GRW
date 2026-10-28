# Windows client installer (any PHP + MySQL app)

This folder builds `<short_name>-Setup-<version>.exe`, which installs a PHP + MySQL web app on a client's Windows 10/11 (x64) PC. Setup downloads Apache 2.4, PHP and MySQL, so the client PC needs internet access.

Step-by-step instructions (build, license keys, client install/upgrade, troubleshooting) are in **[DEVELOPER-GUIDE.md](DEVELOPER-GUIDE.md)**.

Everything app-specific lives in **`app.ini`**. To package another PHP app, copy this whole `installer\` folder into that app's repository root and edit `app.ini`.

## Build an exe (developer PC)

Once per PC:

```powershell
winget install JRSoftware.InnoSetup
```

No PHP or Composer is needed on the build PC. With `[build] composer=1`, the first build downloads its own PHP (the `[requires] php` line) and `composer.phar` into `installer\.tools\`, both SHA-256 checked, and uses them only to bundle `vendor\`.

For each release, double-click **`build.bat`**. It:

1. checks the prerequisites and warns if you are not on `main` or have uncommitted changes;
2. asks for the **new version** (`major.minor.patch`, default = next patch) and **release notes**, and refuses a lower version;
3. stages the app (git-tracked and new non-ignored files, minus `[build] exclude`), runs `composer install --no-dev`, and compiles `output\<short_name>-Setup-<version>.exe`;
4. records the release in `app.ini` `[release]` and at the top of `RELEASES.md`.

Commit `app.ini` and `RELEASES.md` after each build, then send the exe to the client. Without prompts, run `build.ps1 -Version 1.4.0 -Notes "..."`.

## What setup does on the client PC

| Situation | What happens |
|---|---|
| Same version already installed | It shows "already installed" and stops. `setup.exe /REPAIR` reinstalls the same version. |
| Newer version installed | It refuses to downgrade. |
| New install | It asks for the license key, folder, web port and company name. It then downloads Apache, PHP and MySQL and runs `scripts\configure.ps1`, which creates the services `<short_name>-Apache` / `<short_name>-MySQL`, random DB passwords in `config\`, the app's settings file, a daily 23:00 backup, and shortcuts. Finally it opens `first_run_path`. |
| Upgrade, PHP/MySQL line unchanged | It backs up the database to `backups\pre-upgrade-<from>-to-<to>-*.sql` and replaces only the app code. It keeps the settings file and the `keep` folders, rewrites the `[env]` keys and restarts Apache. Nothing is downloaded. |
| Upgrade, `[requires]` line changed | The same as above, plus the newest patch of the new PHP/MySQL line is downloaded and installed. |

Kept across upgrades and uninstall: `data\` (database), `backups\`, `config\` (passwords, license key), `logs\`, the app's settings file and its `keep` folders. Logs are in `logs\install-*.log`, `logs\apache-error.log`, `logs\mysql-error.log` and `logs\backup.log`.

## License keys (one computer per key)

With `[license] enabled=1` (on by default), a key works only on the PC it was issued for:

1. Setup shows the client PC's **Machine ID**: a hash of the motherboard UUID and serial number, so it survives a Windows reinstall. The client sends it to you (the Copy button puts it on the clipboard).
2. You double-click **`license\keygen.bat`**, enter the customer name, Machine ID and an optional expiry date, and send back the key it prints (also copied to the clipboard).
3. Setup checks the key's signature and Machine ID before installing, and saves it in `config\license.key`.
4. On every web request, `license-check.php` checks the key again against the PC's Machine ID. A copied installation shows a "not licensed for this computer" page together with that PC's Machine ID. Command-line PHP is not checked.

The check is hooked in twice, so removing one hook is not enough:
- PHP's `auto_prepend_file` runs it before every request;
- the build adds a `require_once` of it to the top of each `[license] entry_points` file. This changes only the shipped copy, never the repository. If `license-check.php` is deleted, the app stops working.

Two other parts are fixed at build time:
- **The public key** is embedded in `license-check.php`, so swapping in another key pair does not work.
- **Licensing on/off** comes from setup.exe itself, so editing the installed `app.ini` changes nothing.

An app's first build (or first run of `keygen.bat`) creates its signing key:
- `%USERPROFILE%\.app-licenses\<short_name>\license-private.xml` is the **private key**. Never commit or ship it, and **back it up**: without it you cannot issue keys that work with the setup.exe files you have already shipped.
- `license\public-key.xml` and `license\public-key.pem` are the public key, which setup and the PHP check use. **Commit both.**

Every issued key is also logged in `issued-licenses.csv` next to the private key.

Keys are RSA-2048 signatures, so nobody can create a key without the private key. Anyone with administrator rights on the client PC can still edit the installed PHP files and remove both hooks. So this stops copying and casual sharing; it cannot protect plain-text source code from a determined admin. If that matters, also encode the PHP code (e.g. ionCube).

## Packaging another PHP app

1. Copy `installer\` into the app's repository root and delete `RELEASES.md`.
2. Edit `app.ini`:
   - `[app]`:
     - `name`;
     - a **new** `id` from `[guid]::NewGuid()`;
     - `short_name`;
     - `document_root` (e.g. `public`, or `.` for a flat app);
     - `keep` / `create_dirs` (e.g. `storage,bootstrap\cache` for Laravel);
     - `first_run_path` / `health_path`;
     - `env_file` / `env_spaces`.
   - `[release] version=0.0.0`.
   - `[database]`: `name` and `user`. Set `create=1` if the app doesn't create its own database.
   - `[php]` / `[php.ini]`: the extensions and settings the app needs.
   - `[env.first]` / `[env]`: the app's settings keys, using the placeholders listed in `app.ini`. For example, Laravel uses `DB_HOST={db_host}`, `DB_PASSWORD={db_password}`, `APP_KEY=base64:{random_base64_32}`, `APP_URL={base_url}` and `env_spaces=0`.
   - `[build]`: `icon`, `composer`, `exclude`.
   - `[license] enabled`: `1` or `0`.
   - `[license] entry_points`: the app's front controller(s), e.g. `public/index.php`, or `index.php` for a flat app.
3. Delete any copied `license\public-key.*` files, because each app needs its own signing key.
4. Double-click `build.bat`. With licensing on, the first build offers to create the app's signing key.

## Moving to a new PHP or MySQL line

Change `[requires]` in `app.ini` only after testing the app on the new line. When changing the MySQL line, also check `$MySqlMinPatch` in `scripts\resolve.ps1`. The next setup.exe then upgrades every client's server components.
