# Installer – Developer Guide

Step-by-step instructions for building the client setup.exe, issuing license keys, and installing or upgrading clients. For how the installer works inside and how to configure `app.ini`, see [README.md](README.md).

Paths below are relative to the repository root. The examples use Perfect LPG (`short_name=PerfectLPG`); another app uses its own `short_name` from `installer\app.ini`.

---

## 1. One-time setup on your PC

1. **Install Inno Setup 6** in PowerShell:
   ```powershell
   winget install JRSoftware.InnoSetup
   ```
2. **You do not need PHP or Composer installed.**
   - The build uses Composer only to bundle the app's PHP libraries (`vendor\`) into setup.exe. The client's own PHP, Apache and MySQL are downloaded by setup on the client PC.
   - The first build downloads its own PHP (the same version the clients get) and Composer into `installer\.tools\`. It checks both against the vendors' SHA-256 and reuses them afterwards.
   - Any PHP on your PATH is ignored, so the build PC only needs internet access.
3. **License signing key.** This happens once per app, on its **first build**.
   - setup.exe carries the **public** half of the key to check license keys. The **private** half stays on your PC and is used only to issue keys.
   - If the app has no key yet, `build.bat` says "First build of this app" and asks "Create it now?". Answer `y` and the build continues.
   - You can also create it beforehand by double-clicking `installer\license\keygen.bat`.
   - To build without license keys, set `[license] enabled=0` in `app.ini`; no key is needed then.
   - It creates the **private key** `%USERPROFILE%\.app-licenses\PerfectLPG\license-private.xml`.
     - **Back it up now** (USB drive, password manager). If it is lost, you cannot issue keys that work with the exes you have already shipped.
     - **Never** commit it, email it or put it in the installer.
   - It creates the **public key** `installer\license\public-key.xml` and `installer\license\public-key.pem`. **Commit both** with the release.
4. **On another developer PC**, do not run keygen to create a new key. Copy `license-private.xml` from the backup to `%USERPROFILE%\.app-licenses\PerfectLPG\`.

---

## 2. Build a release for clients

1. **Merge the release to `main`**, through the normal feature → stage → main flow, and get the latest code:
   ```powershell
   git checkout main
   git pull
   git status        # must be clean
   ```
2. **Double-click `installer\build.bat`.** It shows:
   - the branch, the commit and the last version built;
   - a warning if you are not on `main` or have uncommitted changes. Answer `N` and fix that first.
3. **Answer the two questions:**
   - **New version:** press Enter to accept the suggested next patch (e.g. `1.0.3`), or type one, e.g. `1.1.0`. It must be higher than the last version.
     - Patch (`1.0.x`): fixes.
     - Minor (`1.x.0`): new features.
     - Major (`x.0.0`): big changes.
   - **Release notes:** one line saying what changed. This is mandatory.
4. **Wait for it to finish.** The exe is written to:
   ```
   installer\output\PerfectLPG-Setup-<version>.exe
   ```
5. **Commit the release record.** The build updated `installer\app.ini` (`[release]`) and `installer\RELEASES.md`. Commit them on a `chore/` branch and merge them back through stage → main:
   ```powershell
   git checkout -b chore/release-1.0.3
   git add installer/app.ini installer/RELEASES.md
   git commit -m "Release 1.0.3"
   git push -u origin chore/release-1.0.3
   ```
6. **Ship the exe** to the client (USB, Google Drive, WhatsApp document, etc.).

Without prompts (e.g. in a script):
```powershell
powershell -ExecutionPolicy Bypass -File installer\build.ps1 -Version 1.0.3 -Notes "Fixed dashboard sales card"
```

Rules:
- Never ship two different builds with the same version number. A client who has that version will refuse the second one.
- Build only from committed code, so every exe matches a commit.

---

## 3. Issue a license key for a client

Each key works on **one computer only**.

1. The client runs setup. The **License** page shows their **Machine ID**, e.g. `MF8H-D2MW-8N2M-N5QY-4F97`. They click **Copy** and send it to you.
2. Double-click `installer\license\keygen.bat` and enter:
   - **Customer / shop name**;
   - **Machine ID**. Spaces, dashes and lowercase letters are fine;
   - **Expiry date** as `yyyy-mm-dd`, or blank for no expiry.
3. The key is printed and copied to the clipboard. Send it to the client.
   - It is one long line. Send it as text, not as a screenshot.
4. Every issued key is logged in `%USERPROFILE%\.app-licenses\PerfectLPG\issued-licenses.csv`. Keep this file with the private key backup.

The client needs a **new key** when:
- they move the app to another computer;
- the motherboard is replaced;
- their key expires.

Reinstalling Windows on the same PC does **not** change the Machine ID.

---

## 4. Install on a new client PC

Requirements:
- Windows 10/11 64-bit;
- internet access (setup downloads about 300 MB);
- an administrator account.

1. Copy the exe to the PC, right-click it and choose **Run as administrator**.
2. **License** page: send the Machine ID to the developer (see section 3), then paste the key you receive and click Next.
3. **Folder:** keep `C:\PerfectLPG`. The path must not contain spaces.
4. **Server settings:**
   - **Web port:** `80`, or `8080` if setup says 80 is busy.
   - **Company name:** optional.
5. **Tasks:** tick **Allow other computers on this network** if other POS counters will open the app from their own PCs.
6. Click **Install** and wait. The download and setup take a few minutes.
7. The browser opens the app's first-run page (for Perfect LPG, **Installation**, which creates the database). Then log in with `admin` / `admin123` and **change the password immediately**.

Afterwards:
- **Desktop shortcut:** setup creates one (`Perfect LPG`).
- **Other counters:** they open `http://<computer-name>/`, or `http://<computer-name>:<port>/` if the port is not 80.
- **Backups:** a backup runs every day at 23:00 into `C:\PerfectLPG\backups\`. Backups are kept for 30 days.

---

## 5. Upgrade an existing client

1. Build the new version (section 2) and send the exe.
2. On the client PC, run it **as administrator**. It finds the existing installation by itself, and it does not ask for the license, folder or port again.
3. What happens:
   - **If the installed version is the same:** it says "already installed" and stops.
   - **If the installed version is newer:** it refuses to downgrade.
   - **Otherwise:**
     1. It backs up the database to `backups\pre-upgrade-<old>-to-<new>-<time>.sql`.
     2. It replaces the app code. The `.env` file, `writable\`, the database, `config\` and `backups\` are kept.
     3. It restarts the web server.
     - PHP/MySQL are downloaded again only if `[requires]` in `app.ini` changed.
4. If the release has database migrations, the app offers them on the login page after the upgrade.

If the backup fails, setup asks whether to continue without one. Answer **No** unless you have a recent backup.

---

## 6. Repair, uninstall and move to another PC

**Repair** (same version, e.g. after a broken install). Run in an administrator command prompt:
```
PerfectLPG-Setup-1.0.3.exe /REPAIR
```
This reinstalls Apache, PHP, MySQL and the code. The data is kept.

**Uninstall:** Windows Settings → Apps → *Perfect LPG* → Uninstall.
- It removes the services, the backup task, the shortcuts and the program files.
- The database (`data\`), `backups\`, `config\` (including the license key) and `.env` stay.
- Running setup again on the same PC reconnects to the same data.

**Move to another PC** *(not yet tested end to end)*:
1. On the old PC, take the newest `C:\PerfectLPG\backups\*.sql`.
2. Issue a new key for the new PC's Machine ID (section 3) and install there (section 4). Stop before running the Installation page in the browser.
3. Restore the backup in an administrator command prompt on the new PC:
   ```
   cd C:\PerfectLPG
   mysql\bin\mysql.exe --defaults-extra-file=config\mysql-root.cnf -e "CREATE DATABASE IF NOT EXISTS perfect_lpg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql\bin\mysql.exe --defaults-extra-file=config\mysql-root.cnf perfect_lpg < backups\<file>.sql
   ```
4. Run the same setup.exe with `/REPAIR`, so it detects the restored database and switches off first-run mode.
5. Uninstall from the old PC.

**Silent install** *(not yet tested)*:
```
PerfectLPG-Setup-1.0.3.exe /SILENT /LICENSEKEY=<key> /HTTPPORT=80 /COMPANY="Shop name"
```

---

## 7. Troubleshooting

All paths are on the client PC, under `C:\PerfectLPG\`.

| Problem | Where to look / what to do |
|---|---|
| Setup says "setting up the database or web server failed" | Open the newest `logs\install-*.log`; the last `ERROR:` line says why. Fix it and run setup again (with `/REPAIR` if the version is already installed). |
| "Port 80 is already used" | Another program (IIS, Skype, XAMPP) holds the port. Choose `8080`, or stop that program. |
| Page shows **"not licensed for this computer"** | The key is missing, is for another PC, or has expired. Issue a key for the Machine ID shown on that page and run setup with `/REPAIR` to enter it. |
| "This license key is for another computer" during setup | The key was issued for a different Machine ID. Check the ID the client sent you. |
| "This license key is not valid" | Part of the key is missing or was changed. Ask the client to paste the whole key as text. |
| App does not open / error 500 | `logs\apache-error.log`, and the app's own logs (`app\writable\logs\`). |
| MySQL does not start | `logs\mysql-error.log`. Check that the `PerfectLPG-MySQL` service is running in `services.msc`. |
| Daily backup missing | `logs\backup.log`, and the **Perfect LPG Daily Backup** task in Task Scheduler. |
| Download failed during setup | Check the internet connection (or proxy/firewall) and click Install again. |
| `build.bat`: "The build's own PHP ... does not run" | It usually reports a missing `VCRUNTIME140.dll`. Install the Microsoft VC++ runtime (https://aka.ms/vs/17/release/vc_redist.x64.exe) and build again. |
| `build.bat`: download or SHA-256 errors | Check the internet connection and build again. To force a fresh download, delete `installer\.tools\`. |
| `build.bat`: "no license signing key yet" (non-interactive build) | Run `keygen.bat` once, or run `build.bat` interactively and answer `y` (section 1 step 3). |
| `keygen.bat`: "The signing key is missing on this PC" | Copy `license-private.xml` from the backup to `%USERPROFILE%\.app-licenses\PerfectLPG\`. **Do not** delete the public keys to make a new signing key: every issued license would stop working. |

Services: `PerfectLPG-Apache` and `PerfectLPG-MySQL`. They start automatically and restart on failure. To restart the app, restart `PerfectLPG-Apache` in `services.msc`.

---

## 8. Change the PHP or MySQL version

1. Test the app locally on the new PHP/MySQL version.
2. In `installer\app.ini` → `[requires]`, change `php=` or `mysql=` (e.g. `php=8.6`). When changing the MySQL line, also set `$MySqlMinPatch` in `installer\scripts\resolve.ps1` to a patch the MySQL CDN still serves.
3. Build a new version. Every client that upgrades to it downloads and installs the new version. Their data is kept.

---

## 9. Use the installer for another PHP app

1. Copy the whole `installer\` folder into the other app's repository root.
2. Delete the copied `RELEASES.md` and `license\public-key.*`, because each app needs its own release history and signing key.
3. Edit `installer\app.ini`:
   - `[app]`: a new `id` from `[guid]::NewGuid()`, plus the name, short name, web root, keep folders and pages;
   - `[release] version=0.0.0`;
   - `[database]`;
   - `[php]`;
   - `[env.first]` / `[env]`.

   Details and a Laravel example are in [README.md](README.md#packaging-another-php-app).
4. Build it (section 2). The first build creates that app's own signing key (section 1 step 3).

The app's own code needs no changes. The license check runs before every request through `php.ini`. The build also adds it to the top of the files in `[license] entry_points` (e.g. `public/index.php`), in the shipped copy only.

---

## Quick reference

| Task | Do this |
|---|---|
| Build a release | `installer\build.bat`, then commit `app.ini` + `RELEASES.md` |
| Issue a license key | `installer\license\keygen.bat` |
| Install / upgrade a client | Run the exe as administrator |
| Repair | `setup.exe /REPAIR` |
| Client logs | `C:\PerfectLPG\logs\` |
| Client backups | `C:\PerfectLPG\backups\` |
| Private key (back it up!) | `%USERPROFILE%\.app-licenses\<short_name>\license-private.xml` |
