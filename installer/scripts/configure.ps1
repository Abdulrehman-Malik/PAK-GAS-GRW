# Client configuration, run elevated by setup.exe after it has copied the app and (unless -AppOnly)
# downloaded the components listed in <DownloadDir>\components.ini (see resolve.ps1).
# Installs Apache, PHP and MySQL from those downloads, registers them as services and writes the
# app's settings file. Everything app-specific comes from app.ini next to this script.
# Safe to re-run: an existing database, its credentials and the settings file are kept, so running
# setup.exe again upgrades. -AppOnly: an upgrade that kept the installed Apache, PHP and MySQL
# (setup copied only the new app code); nothing is re-extracted, and Apache restarts on the new code.
# Windows PowerShell 5.1 compatible.
param(
    [Parameter(Mandatory = $true)][string]$InstallDir,
    [string]$DownloadDir = '',
    [int]$HttpPort = 80,
    [int]$DbPort = 3307,
    [string]$CompanyName = '',
    [string]$AppVersion = '',
    [switch]$OpenFirewall,
    [switch]$AppOnly,
    # Passed by setup.exe, which has the licensing choice compiled in (app.ini is editable on the client).
    [switch]$LicenseRequired
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
. (Join-Path $PSScriptRoot 'common.ps1')

$Product = Get-AppIni $PSScriptRoot
$AppName = $Product['app']['name']
$ShortName = $Product['app']['short_name']
$ApacheService = $ShortName + '-Apache'
$MySqlService = $ShortName + '-MySQL'
$BackupTask = $AppName + ' Daily Backup'
$FirewallGroup = $AppName
$DbName = $Product['database']['name']
$DbUser = $Product['database']['user']
$LicenseEnabled = [bool]$LicenseRequired

$InstallDir = $InstallDir.TrimEnd('\')
$ApacheDir = Join-Path $InstallDir 'apache'
$PhpDir = Join-Path $InstallDir 'php'
$MySqlDir = Join-Path $InstallDir 'mysql'
$AppDir = Join-Path $InstallDir 'app'
$ConfigDir = Join-Path $InstallDir 'config'
$DataDir = Join-Path $InstallDir 'data\mysql'
$BackupDir = Join-Path $InstallDir 'backups'
$LogDir = Join-Path $InstallDir 'logs'
$InstallIni = Join-Path $ConfigDir 'install.ini'
$SecretsFile = Join-Path $ConfigDir 'secrets.ini'
$RootCnf = Join-Path $ConfigDir 'mysql-root.cnf'
$MyIni = Join-Path $ConfigDir 'my.ini'
$EnvFile = Join-Path $AppDir $Product['app']['env_file']
$DocRoot = Join-Path $AppDir $Product['app']['document_root']
$Php = Join-Path $PhpDir 'php.exe'
$MySql = Join-Path $MySqlDir 'bin\mysql.exe'

foreach ($d in $ConfigDir, $DataDir, $BackupDir, $LogDir) { New-Item -ItemType Directory -Force $d | Out-Null }
Start-Transcript -Path (Join-Path $LogDir ('install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')) | Out-Null

function Step([string]$text) { Write-Host ('==> ' + $text) }

# Runs a program and returns its stdout + stderr as text; the exit code stays in $LASTEXITCODE.
# Windows PowerShell 5.1 turns redirected stderr lines into errors, which ErrorActionPreference
# 'Stop' would make fatal even for progress messages (mysqld writes its log to stderr).
function Invoke-Native([string]$exe, [string[]]$arguments, $stdin = $null) {
    $ErrorActionPreference = 'Continue'
    if ($null -ne $stdin) { $out = $stdin | & $exe @arguments 2>&1 } else { $out = & $exe @arguments 2>&1 }
    return (($out | ForEach-Object { "$_" }) -join "`n")
}

# Forward slashes for Apache / MySQL config files.
function Fwd([string]$path) { return $path.Replace('\', '/') }

function New-RandomBytes([int]$length) {
    $bytes = New-Object byte[] $length
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return , $bytes
}

function New-Secret([int]$length = 24) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    return -join ((New-RandomBytes $length) | ForEach-Object { $chars[$_ % $chars.Length] })
}

# Administrators and SYSTEM only: these files hold database passwords.
function Protect-File([string]$path) {
    & icacls.exe $path /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null
}

# Extracts a downloaded zip into $target. The archives hold Apache24\, one mysql-x.y.z-winx64\ folder, or the files directly (PHP).
function Install-Zip([string]$zip, [string]$target) {
    $tmp = Join-Path $InstallDir ('.extract-' + (Split-Path $target -Leaf))
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
    New-Item -ItemType Directory -Force $tmp | Out-Null
    & tar.exe -xf $zip -C $tmp
    if ($LASTEXITCODE -ne 0) { Remove-Item $tmp -Recurse -Force; Expand-Archive $zip -DestinationPath $tmp }
    $root = $tmp
    $items = @(Get-ChildItem $tmp)
    if (Test-Path (Join-Path $tmp 'Apache24')) { $root = Join-Path $tmp 'Apache24' }
    elseif ($items.Count -eq 1 -and $items[0].PSIsContainer) { $root = $items[0].FullName }
    if (Test-Path $target) { Remove-Item $target -Recurse -Force }
    # robocopy retries files that antivirus still holds open right after extraction.
    & robocopy.exe $root $target /E /MOVE /R:10 /W:2 /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw ('Could not install ' + $target + ' (robocopy exit ' + $LASTEXITCODE + ').') }
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
}

function Read-KeyValues([string]$path) {
    $values = @{}
    if (Test-Path $path) {
        foreach ($line in Get-Content $path) {
            if ($line -match '^\s*([^#;\[][^=]*?)\s*=\s*(.*)$') { $values[$Matches[1]] = $Matches[2] }
        }
    }
    return $values
}

function Test-PortBusy([int]$port) {
    $own = @($ApacheDir, $MySqlDir) | ForEach-Object { $_ + '\*' }
    $listeners = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue
    foreach ($l in $listeners) {
        $path = (Get-Process -Id $l.OwningProcess -ErrorAction SilentlyContinue).Path
        $isOwn = $false
        foreach ($p in $own) { if ($path -and $path -like $p) { $isOwn = $true } }
        if (-not $isOwn) { return $true }
    }
    return $false
}

function Invoke-MySql([string]$sql, [string[]]$auth) {
    $output = Invoke-Native $MySql ($auth + @('--protocol=TCP', '-h', '127.0.0.1', '-P', "$DbPort", '--batch')) $sql
    if ($LASTEXITCODE -ne 0) { throw ('MySQL command failed: ' + $output) }
}

function Wait-MySql([string[]]$auth) {
    for ($i = 0; $i -lt 60; $i++) {
        Invoke-Native $MySql ($auth + @('--protocol=TCP', '-h', '127.0.0.1', '-P', "$DbPort", '-e', 'SELECT 1')) | Out-Null
        if ($LASTEXITCODE -eq 0) { return }
        Start-Sleep -Seconds 2
    }
    throw ('MySQL did not become ready on port ' + $DbPort + '. See ' + (Join-Path $LogDir 'mysql-error.log'))
}

function Stop-OwnService([string]$name) {
    $svc = Get-Service $name -ErrorAction SilentlyContinue
    if ($svc -and $svc.Status -ne 'Stopped') { Stop-Service $name -Force; $svc.WaitForStatus('Stopped', '00:01:00') }
}

# Sets KEY = value in the settings file, replacing an existing (or commented-out) line.
function Set-EnvValue([string]$content, [string]$key, [string]$value) {
    $line = $key + $(if ($Product['app']['env_spaces'] -eq '0') { '=' } else { ' = ' }) + $value
    $pattern = '(?m)^\s*#?\s*' + [regex]::Escape($key) + '\s*=.*$'
    if ($content -match $pattern) { return [regex]::Replace($content, $pattern, $line.Replace('$', '$$'), 1) }
    return $content.TrimEnd() + "`r`n" + $line + "`r`n"
}

# Applies an app.ini [env] / [env.first] section; a line whose placeholder is empty is skipped.
function Set-EnvSection([string]$content, $section, [hashtable]$values) {
    if (-not $section) { return $content }
    foreach ($key in $section.Keys) {
        $value = [string]$section[$key]
        $skip = $false
        foreach ($m in [regex]::Matches($value, '\{([a-z0-9_]+)\}')) {
            $name = $m.Groups[1].Value
            if (-not $values.ContainsKey($name)) { throw ('app.ini uses an unknown placeholder {' + $name + '} for ' + $key + '.') }
            if ([string]$values[$name] -eq '') { $skip = $true }
            $value = $value.Replace($m.Value, [string]$values[$name])
        }
        if (-not $skip) { $content = Set-EnvValue $content $key $value }
    }
    return $content
}

try {
    # ---- Ports -------------------------------------------------------------------------
    $saved = Read-KeyValues $InstallIni
    if ($saved.ContainsKey('db_port')) { $DbPort = [int]$saved['db_port'] }
    elseif (Test-PortBusy $DbPort) {
        $DbPort = (3308..3330 | Where-Object { -not (Test-PortBusy $_) } | Select-Object -First 1)
        if (-not $DbPort) { throw 'No free MySQL port between 3307 and 3330.' }
    }
    if (Test-PortBusy $HttpPort) { throw ('Port ' + $HttpPort + ' is used by another program (often IIS or Skype). Run setup again and choose a different port.') }
    Step ('Web port ' + $HttpPort + ', database port ' + $DbPort)

    Stop-OwnService $ApacheService

    if ($AppOnly) {
        # ---- Installed components (app-only upgrade) -----------------------------------
        $components = @{}
        foreach ($name in 'php', 'apache', 'mysql') {
            if (-not $saved[$name]) { throw ($InstallIni + ' does not record the installed ' + $name + ' version. Run setup with /REPAIR.') }
            $components[$name] = @{ version = $saved[$name] }
            Step ('Keeping ' + $name + ' ' + $saved[$name])
        }
    } else {
        Stop-OwnService $MySqlService

        # ---- Downloaded components ----------------------------------------------------
        $components = Read-Ini (Join-Path $DownloadDir 'components.ini')
        foreach ($name in 'php', 'apache', 'mysql', 'vcredist', 'cacert') {
            if (-not $components.ContainsKey($name) -or -not (Test-Path (Join-Path $DownloadDir $components[$name]['file']))) {
                throw ('The ' + $name + ' download is missing from ' + $DownloadDir + '. Run setup again.')
            }
        }
        function Downloaded([string]$name) { return (Join-Path $DownloadDir $components[$name]['file']) }

        Step 'Installing the Microsoft Visual C++ runtime'
        $sig = Get-AuthenticodeSignature (Downloaded 'vcredist')
        if ($sig.Status -ne 'Valid' -or $sig.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') { throw 'The downloaded VC++ runtime is not signed by Microsoft.' }
        $vc = Start-Process (Downloaded 'vcredist') -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
        # 1638: a newer version is already installed; 3010: installed, reboot recommended.
        if (@(0, 1638, 3010) -notcontains $vc.ExitCode) { throw ('The VC++ runtime installer failed with exit code ' + $vc.ExitCode + '.') }

        foreach ($name in 'php', 'apache', 'mysql') {
            Step ('Installing ' + $name + ' ' + $components[$name]['version'])
            Install-Zip (Downloaded $name) (Join-Path $InstallDir $name)
        }
        Copy-Item (Downloaded 'cacert') (Join-Path $PhpDir 'cacert.pem') -Force

        # PHP runs inside httpd.exe, and Windows loads DLLs from the exe's folder first: where Apache and
        # PHP ship the same DLL (OpenSSL, libssh2, nghttp2, ...), keep the newer one in apache\bin.
        foreach ($dll in Get-ChildItem $PhpDir -Filter *.dll) {
            $other = Join-Path $ApacheDir ('bin\' + $dll.Name)
            if ((Test-Path $other) -and $dll.VersionInfo.FileVersionRaw -ge (Get-Item $other).VersionInfo.FileVersionRaw) {
                Copy-Item $dll.FullName $other -Force
            }
        }
    }

    # ---- MySQL -------------------------------------------------------------------------
    Step 'Configuring MySQL'
    # secrets.ini gets mysql_initialized=1 only once root and the app user exist. A data folder without
    # it is left over from an interrupted first install and is rebuilt - unless it already holds the app database.
    $secrets = Read-KeyValues $SecretsFile
    $freshDb = $secrets['mysql_initialized'] -ne '1'
    if ($freshDb -and $AppOnly) { throw ($SecretsFile + ' is missing or incomplete, so this is not a finished installation. Run setup with /REPAIR.') }
    if ($freshDb -and (Test-Path (Join-Path $DataDir $DbName))) {
        throw ('The MySQL data folder ' + $DataDir + ' holds the ' + $DbName + ' database but ' + $SecretsFile + ' is missing or incomplete. Restore that file before installing.')
    }
    if ($freshDb) {
        Get-ChildItem $DataDir -Force | Remove-Item -Recurse -Force
        $secrets = @{ db_root_password = (New-Secret); db_app_password = (New-Secret) }
    }

    Write-Text $MyIni @"
[mysqld]
basedir="$(Fwd $MySqlDir)"
datadir="$(Fwd $DataDir)"
port=$DbPort
bind-address=127.0.0.1
mysqlx=OFF
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
innodb_buffer_pool_size=256M
max_allowed_packet=64M
log-error="$(Fwd (Join-Path $LogDir 'mysql-error.log'))"

[client]
port=$DbPort
default-character-set=utf8mb4
"@

    Write-Text $RootCnf @"
[client]
user=root
password="$($secrets['db_root_password'])"
host=127.0.0.1
port=$DbPort
protocol=TCP
"@
    Protect-File $RootCnf

    if ($freshDb) {
        Step 'Initializing MySQL data folder'
        Invoke-Native (Join-Path $MySqlDir 'bin\mysqld.exe') @("--defaults-file=$MyIni", '--initialize-insecure', '--console') | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'mysqld --initialize failed.' }
    }

    if (-not (Get-Service $MySqlService -ErrorAction SilentlyContinue)) {
        & (Join-Path $MySqlDir 'bin\mysqld.exe') --install $MySqlService --defaults-file="$MyIni" | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'Could not register the MySQL service.' }
    }
    & sc.exe config $MySqlService start= auto | Out-Null
    & sc.exe failure $MySqlService reset= 86400 actions= restart/5000/restart/5000/restart/30000 | Out-Null
    Start-Service $MySqlService

    if ($freshDb) {
        Wait-MySql @('-u', 'root', '--skip-password')
        $root = $secrets['db_root_password']
        $app = $secrets['db_app_password']
        Invoke-MySql @"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$root';
CREATE USER '$DbUser'@'localhost' IDENTIFIED BY '$app';
CREATE USER '$DbUser'@'127.0.0.1' IDENTIFIED BY '$app';
GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'localhost';
GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'127.0.0.1';
FLUSH PRIVILEGES;
"@ @('-u', 'root', '--skip-password')
        Write-Text $SecretsFile ("db_root_password=" + $root + "`r`ndb_app_password=" + $app + "`r`nmysql_initialized=1`r`n")
        Protect-File $SecretsFile
    }
    Wait-MySql @("--defaults-extra-file=$RootCnf")
    if ($Product['database']['create'] -eq '1') {
        Invoke-MySql ('CREATE DATABASE IF NOT EXISTS `' + $DbName + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;') @("--defaults-extra-file=$RootCnf")
    }

    # ---- PHP ---------------------------------------------------------------------------
    Step 'Configuring PHP'
    $ini = Get-Content (Join-Path $PhpDir 'php.ini-production') -Raw
    $ini = $ini -replace '(?m)^;?\s*extension_dir\s*=\s*"ext"\s*$', ('extension_dir = "' + (Join-Path $PhpDir 'ext') + '"')
    foreach ($e in Split-List $Product['php']['extensions']) {
        $ini = $ini -replace ('(?m)^;extension=' + [regex]::Escape($e) + '\s*$'), ('extension=' + $e)
    }
    $settings = [ordered]@{}
    if ($Product['php.ini']) { foreach ($k in $Product['php.ini'].Keys) { $settings[$k] = $Product['php.ini'][$k] } }
    $settings['curl.cainfo'] = ('"' + (Join-Path $PhpDir 'cacert.pem') + '"')
    $settings['openssl.cafile'] = ('"' + (Join-Path $PhpDir 'cacert.pem') + '"')
    # The machine-locked license check runs before every request (see license-check.php).
    $settings['auto_prepend_file'] = $(if ($LicenseEnabled) { '"' + (Join-Path $InstallDir 'installer\license-check.php') + '"' } else { '' })
    foreach ($k in $settings.Keys) {
        $pattern = '(?m)^;?\s*' + [regex]::Escape($k) + '\s*=.*$'
        $line = $k + ' = ' + $settings[$k]
        if ($ini -match $pattern) { $ini = [regex]::Replace($ini, $pattern, $line.Replace('$', '$$'), 1) } else { $ini += "`r`n" + $line }
    }
    Write-Text (Join-Path $PhpDir 'php.ini') $ini
    $modules = Invoke-Native $Php @('-m')
    $missing = @(Split-List $Product['php']['required_extensions'] | Where-Object { $modules -notmatch ('(?m)^' + [regex]::Escape($_) + '\s*$') })
    if ($LASTEXITCODE -ne 0 -or $missing -or $modules -match 'Warning') { throw ('PHP extensions failed to load (' + ($missing -join ', ') + '): ' + $modules) }

    # ---- Application settings ----------------------------------------------------------
    Step 'Writing application settings'
    $hostName = $env:COMPUTERNAME.ToLower()
    $baseUrl = 'http://' + $hostName + $(if ($HttpPort -eq 80) { '' } else { ':' + $HttpPort }) + '/'
    $tableCount = Invoke-Native $MySql @("--defaults-extra-file=$RootCnf", '--batch', '--skip-column-names') ('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ''' + $DbName + ''';')
    if ($LASTEXITCODE -ne 0) { throw ('Could not query MySQL: ' + $tableCount) }
    $dbInstalled = [int]([regex]::Match($tableCount, '(?m)^\s*(\d+)\s*$').Groups[1].Value) -gt 0
    $placeholders = @{
        base_url         = $baseUrl
        install_dir      = $InstallDir
        app_dir          = $AppDir
        backup_dir       = $BackupDir
        mysql            = $MySql
        mysqldump        = (Join-Path $MySqlDir 'bin\mysqldump.exe')
        company          = ($CompanyName.Trim() -replace '[''"]', '')
        app_version      = $AppVersion
        db_host          = '127.0.0.1'
        db_port          = "$DbPort"
        db_name          = $DbName
        db_user          = $DbUser
        db_password      = $secrets['db_app_password']
        db_empty         = $(if ($dbInstalled) { 'false' } else { 'true' })
        random_hex32     = (((New-RandomBytes 32) | ForEach-Object { $_.ToString('x2') }) -join '')
        random_base64_32 = [Convert]::ToBase64String((New-RandomBytes 32))
    }
    if (Test-Path $EnvFile) {
        $envText = Get-Content $EnvFile -Raw
    } else {
        $envText = Set-EnvSection ("# Generated by the " + $AppName + " installer.`r`n") $Product['env.first'] $placeholders
    }
    $envText = Set-EnvSection $envText $Product['env'] $placeholders
    Write-Text $EnvFile $envText
    Protect-File $EnvFile

    foreach ($d in Split-List $Product['app']['create_dirs']) { New-Item -ItemType Directory -Force (Join-Path $AppDir $d) | Out-Null }

    # ---- Apache ------------------------------------------------------------------------
    Step 'Configuring Apache'
    $rotate = Fwd (Join-Path $ApacheDir 'bin\rotatelogs.exe')
    Write-Text (Join-Path $ApacheDir 'conf\httpd.conf') @"
# Generated by the $AppName installer - changes are overwritten on upgrade.
Define SRVROOT "$(Fwd $ApacheDir)"
ServerRoot "`${SRVROOT}"
Listen $HttpPort
ServerName localhost:$HttpPort
ServerTokens Prod
ServerSignature Off
TraceEnable Off

LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule headers_module modules/mod_headers.so
LoadModule filter_module modules/mod_filter.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule expires_module modules/mod_expires.so
LoadModule env_module modules/mod_env.so

LoadFile "$(Fwd (Join-Path $PhpDir 'php8ts.dll'))"
LoadModule php_module "$(Fwd (Join-Path $PhpDir 'php8apache2_4.dll'))"
PHPIniDir "$(Fwd $PhpDir)"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>

TypesConfig conf/mime.types
DirectoryIndex index.php index.html
AcceptFilter http none
AcceptFilter https none
Timeout 300

<Directory />
    AllowOverride None
    Require all denied
</Directory>

DocumentRoot "$(Fwd $DocRoot)"
<Directory "$(Fwd $DocRoot)">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

<Files ".ht*">
    Require all denied
</Files>

LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"" combined
ErrorLog "|$rotate -n 5 $(Fwd (Join-Path $LogDir 'apache-error.log')) 10M"
CustomLog "|$rotate -n 5 $(Fwd (Join-Path $LogDir 'apache-access.log')) 10M" combined
LogLevel warn
"@
    $test = Invoke-Native (Join-Path $ApacheDir 'bin\httpd.exe') @('-t')
    if ($LASTEXITCODE -ne 0) { throw ('Apache configuration test failed: ' + $test) }

    if (-not (Get-Service $ApacheService -ErrorAction SilentlyContinue)) {
        Invoke-Native (Join-Path $ApacheDir 'bin\httpd.exe') @('-k', 'install', '-n', $ApacheService) | Out-Host
        if (-not (Get-Service $ApacheService -ErrorAction SilentlyContinue)) { throw 'Could not register the Apache service.' }
    }
    # PHP extensions load their DLLs (ICU, libssh2, ...) from the PHP folder, so the service needs it on PATH.
    $svcKey = 'HKLM:\SYSTEM\CurrentControlSet\Services\' + $ApacheService
    New-ItemProperty -Path $svcKey -Name Environment -PropertyType MultiString -Force -Value @(
        ('PATH=' + $PhpDir + ';' + (Join-Path $PhpDir 'ext') + ';' + $env:SystemRoot + '\System32;' + $env:SystemRoot),
        ('PHPRC=' + $PhpDir)
    ) | Out-Null
    if ($LicenseEnabled) { Update-LicenseCache $InstallDir }
    & sc.exe config $ApacheService start= auto depend= $MySqlService | Out-Null
    & sc.exe failure $ApacheService reset= 86400 actions= restart/5000/restart/5000/restart/30000 | Out-Null
    Start-Service $ApacheService

    # ---- Backups, firewall, shortcuts --------------------------------------------------
    Step 'Scheduling the daily database backup'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $InstallDir 'installer\backup.ps1') + '" -InstallDir "' + $InstallDir + '"')
    $trigger = New-ScheduledTaskTrigger -Daily -At '23:00'
    $taskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 2)
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $BackupTask -Action $action -Trigger $trigger -Settings $taskSettings -Principal $principal -Force | Out-Null

    Get-NetFirewallRule -Group $FirewallGroup -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    if ($OpenFirewall) {
        Step ('Allowing other computers on the local network to reach port ' + $HttpPort)
        New-NetFirewallRule -DisplayName ($AppName + ' web (TCP ' + $HttpPort + ')') -Group $FirewallGroup -Direction Inbound -Protocol TCP -LocalPort $HttpPort -Action Allow -Profile Domain, Private | Out-Null
    }

    $shortcut = "[InternetShortcut]`r`nURL=" + $baseUrl + "`r`nIconIndex=0`r`nIconFile=" + (Join-Path $InstallDir 'installer\app.ico') + "`r`n"
    Write-Text (Join-Path $env:PUBLIC ('Desktop\' + $AppName + '.url')) $shortcut
    Write-Text (Join-Path $env:ProgramData ('Microsoft\Windows\Start Menu\Programs\' + $AppName + '.url')) $shortcut

    $firstRun = $Product['app']['first_run_path']
    $openUrl = $baseUrl + $(if ($dbInstalled) { '' } else { $firstRun })
    Write-Text $InstallIni ("[install]`r`nname=" + $AppName + "`r`napp_version=" + $AppVersion + "`r`nhttp_port=" + $HttpPort + "`r`ndb_port=" + $DbPort + "`r`nurl=" + $openUrl +
        "`r`nphp=" + $components['php']['version'] + "`r`napache=" + $components['apache']['version'] + "`r`nmysql=" + $components['mysql']['version'] +
        "`r`ninstalled=" + (Get-Date -Format s) + "`r`n")

    # ---- Health check ------------------------------------------------------------------
    Step 'Checking that the application responds'
    $checkPath = $(if ($dbInstalled) { $Product['app']['health_path'] } else { $firstRun })
    $ok = $false
    for ($i = 0; $i -lt 15 -and -not $ok; $i++) {
        try {
            $r = Invoke-WebRequest ('http://127.0.0.1:' + $HttpPort + '/' + $checkPath) -UseBasicParsing -TimeoutSec 15
            $ok = $r.StatusCode -eq 200
        } catch { Start-Sleep -Seconds 2 }
    }
    if (-not $ok) { throw ('The application did not respond on port ' + $HttpPort + '. See ' + (Join-Path $LogDir 'apache-error.log') + ' and the app''s own logs.') }

    Step ('Done. Open ' + $openUrl)
    Stop-Transcript | Out-Null
    exit 0
} catch {
    Write-Host ('ERROR: ' + $_.Exception.Message)
    Stop-Transcript | Out-Null
    exit 1
}
