# Builds installer\output\<short_name>-Setup-<version>.exe for the app described in app.ini
# (double-click build.bat). This installer\ folder sits in the app's repository root.
# setup.exe carries only the application (tracked + new, non-ignored files, with production Composer
# dependencies); Apache, PHP, MySQL and the VC++ runtime are downloaded on the client PC during setup.
# Asks for the version and release notes unless -Version and -Notes are given, then records them in
# app.ini [release] and RELEASES.md. Needs: git, Inno Setup 6 (winget install JRSoftware.InnoSetup)
# and internet. PHP and Composer on this PC are not used: the build brings its own (Get-BuildTools).
param([string]$Version = '', [string]$Notes = '')

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'scripts\common.ps1')

$Here = $PSScriptRoot
$Repo = Split-Path $Here -Parent
$Stage = Join-Path $Here '.stage'
$AppIni = Join-Path $Here 'app.ini'
$ReleasesMd = Join-Path $Here 'RELEASES.md'
$Interactive = -not ($Version -and $Notes)

function Step([string]$text) { Write-Host ('==> ' + $text) -ForegroundColor Cyan }
function Confirm([string]$question) { return $Interactive -and ((Read-Host ($question + ' (y/N)')) -match '^[yY]') }
# Puts the license check at the top of a staged PHP entry file (after any declare / namespace line,
# which PHP requires first). <InstallDir> is as many levels above the file as the entry path is deep:
# <InstallDir>\app\public\index.php -> dirname(__DIR__, 2).
function Add-LicenseHook([string]$file, [string]$entry) {
    $depth = $entry.Replace('\', '/').Trim('/').Split('/').Count
    $text = [IO.File]::ReadAllText($file)
    $m = [regex]::Match($text, '^<\?php\b\s*(declare\s*\([^)]*\)\s*;\s*)?(namespace\s+[^;{]+;\s*)?')
    if (-not $m.Success) { throw ($entry + ' (app.ini [license] entry_points) must start with <?php.') }
    $hook = 'require_once dirname(__DIR__, ' + $depth + ") . '/installer/license-check.php'; // added by the installer build`n"
    Write-Text $file ($text.Substring(0, $m.Length) + $hook + $text.Substring($m.Length))
}

function Set-ReleaseValue([string]$text, [string]$key, [string]$value) {
    return [regex]::Replace($text, '(?ms)(^\[release\]\s*$.*?^' + $key + '=)[^\r\n]*', ('${1}' + $value.Replace('$', '$$')), 1)
}

# Composer only bundles the app's libraries (vendor\) into setup.exe; the client's PHP comes from setup.
# The build runs Composer with its own copies, kept in installer\.tools, so whatever PHP this PC has
# (or lacks) on PATH does not matter: the client's PHP line from windows.php.net and the latest
# stable composer.phar, both checked against the vendor's SHA-256. Downloaded once, then reused.
function Get-BuildTools([string]$phpLine) {
    $tools = Join-Path $Here '.tools'
    $phpDir = Join-Path $tools 'php'
    $php = Join-Path $phpDir 'php.exe'
    $phpIni = Join-Path $phpDir 'php.ini'
    $phar = Join-Path $tools 'composer.phar'
    $stamp = Join-Path $tools 'php-line.txt'
    $ProgressPreference = 'SilentlyContinue'
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    New-Item -ItemType Directory -Force $tools | Out-Null

    if (-not (Test-Path $php) -or -not (Test-Path $stamp) -or ([IO.File]::ReadAllText($stamp).Trim() -ne $phpLine)) {
        Step ('Downloading PHP ' + $phpLine + ' for the build (only the first time)')
        $found = Join-Path $tools 'php.ini.download'
        & (Join-Path $Here 'scripts\resolve.ps1') -OutFile $found -PhpLine $phpLine -PhpOnly
        $info = Read-Ini $found
        Remove-Item $found
        if (-not $info['php']) { throw ('Could not look up PHP ' + $phpLine + ': ' + $info['error']['message']) }
        $zip = Join-Path $tools 'php.zip'
        Invoke-WebRequest $info['php']['url'] -OutFile $zip -UseBasicParsing -UserAgent 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        if ((Get-FileHash $zip -Algorithm SHA256).Hash.ToLower() -ne $info['php']['sha256']) { Remove-Item $zip; throw 'The PHP download failed its SHA-256 check.' }
        if (Test-Path $phpDir) { Remove-Item $phpDir -Recurse -Force }
        New-Item -ItemType Directory -Force $phpDir | Out-Null
        & tar.exe -xf $zip -C $phpDir
        if ($LASTEXITCODE -ne 0) { Expand-Archive $zip -DestinationPath $phpDir -Force }
        Remove-Item $zip
        Write-Text $phpIni ('extension_dir = "' + (Join-Path $phpDir 'ext') + "`"`r`nextension=openssl`r`nextension=zip`r`nextension=mbstring`r`nextension=curl`r`nmemory_limit = 1G`r`n")
        Write-Text $stamp $phpLine
    }
    if (-not (Test-Path $phar)) {
        Step 'Downloading Composer for the build (only the first time)'
        Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $phar -UseBasicParsing
        $sum = (Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar.sha256sum' -UseBasicParsing).Content
        if ($sum -is [byte[]]) { $sum = [Text.Encoding]::ASCII.GetString($sum) }
        if ((Get-FileHash $phar -Algorithm SHA256).Hash.ToLower() -ne ([string]$sum -split '\s+')[0].ToLower()) { Remove-Item $phar; throw 'The Composer download failed its SHA-256 check.' }
    }

    $ErrorActionPreference = 'Continue'
    $check = & $php -c $phpIni -r "echo extension_loaded('openssl') && extension_loaded('zip') ? 'ok' : 'extensions missing';" 2>&1
    $ErrorActionPreference = 'Stop'
    if ("$check" -ne 'ok') {
        throw ('The build''s own PHP (' + $php + ') does not run: ' + "$check" + ' If Windows reports a missing VCRUNTIME140.dll, install the Microsoft VC++ runtime from https://aka.ms/vs/17/release/vc_redist.x64.exe')
    }
    return @{ php = $php; ini = $phpIni; composer = $phar }
}

try {
    # ---- Prerequisites ----------------------------------------------------------------
    $Product = Read-Ini $AppIni
    foreach ($k in 'name', 'id', 'short_name', 'document_root', 'env_file') {
        if (-not $Product['app'][$k]) { throw ('app.ini [app] needs ' + $k + '=') }
    }
    if ($Product['app']['short_name'] -match '\s') { throw 'app.ini [app] short_name must not contain spaces.' }
    if ($Product['app']['id'] -notmatch '^[0-9A-Fa-f]{8}(-[0-9A-Fa-f]{4}){3}-[0-9A-Fa-f]{12}$') { throw 'app.ini [app] id must be a GUID (create one with [guid]::NewGuid()).' }
    $current = $Product['release']['version']
    $phpLine = $Product['requires']['php']
    $mySqlLine = $Product['requires']['mysql']
    if ($current -notmatch '^\d+\.\d+\.\d+$' -or $phpLine -notmatch '^\d+\.\d+$' -or $mySqlLine -notmatch '^\d+\.\d+$') { throw 'app.ini needs [release] version=x.y.z and [requires] php=x.y, mysql=x.y.' }
    $licensed = $Product['license']['enabled'] -eq '1'
    $composer = $Product['build']['composer'] -eq '1'

    $iscc = @("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe", "$env:ProgramFiles\Inno Setup 6\ISCC.exe", "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe") |
        Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $iscc) { throw 'Inno Setup 6 was not found. Install it with: winget install JRSoftware.InnoSetup' }
    if ($composer) { $tools = Get-BuildTools $phpLine }
    # setup.exe carries the public half of the app's signing key to check license keys. An app's first
    # build creates the key pair (keygen.ps1); the private half stays on this PC.
    $publicKeys = (Join-Path $Here 'license\public-key.xml'), (Join-Path $Here 'license\public-key.pem')
    if ($licensed -and @($publicKeys | Where-Object { -not (Test-Path $_) })) {
        if (-not $Interactive) { throw 'This app has no license signing key yet. Run installer\license\keygen.bat once (or set [license] enabled=0 in app.ini).' }
        Write-Host 'First build of this app: it has no license signing key yet.' -ForegroundColor Yellow
        Write-Host '(To build without license keys instead, answer N and set [license] enabled=0 in app.ini.)'
        & (Join-Path $Here 'license\keygen.ps1') -InitOnly
        if ($LASTEXITCODE -ne 0 -or @($publicKeys | Where-Object { -not (Test-Path $_) })) { throw 'No signing key was created, so setup.exe could not check license keys.' }
        Write-Host 'Commit installer\license\public-key.xml and public-key.pem with this release.' -ForegroundColor Yellow
        Write-Host ''
    }
    $icon = Join-Path $Repo $Product['build']['icon']
    if (-not (Test-Path $icon)) { throw ('The icon ' + $icon + ' (app.ini [build] icon) does not exist.') }

    Push-Location $Repo
    try {
        $branch = (git rev-parse --abbrev-ref HEAD).Trim()
        $commit = (git rev-parse --short HEAD).Trim()
        $changes = @(git status --porcelain --untracked-files=no | Where-Object { $_ -notmatch 'installer/(app\.ini|RELEASES\.md|license/public-key\.(xml|pem))$' })
    } finally { Pop-Location }

    Write-Host ''
    Write-Host ($Product['app']['name'] + ' installer build') -ForegroundColor Green
    Write-Host ('  Branch / commit : ' + $branch + ' / ' + $commit)
    Write-Host ('  Last version    : ' + $current + $(if ($Product['release']['built']) { ' (built ' + $Product['release']['built'] + ')' } else { '' }))
    Write-Host ('  Server lines    : PHP ' + $phpLine + ', MySQL ' + $mySqlLine + ', Apache 2.4')
    Write-Host ('  License keys    : ' + $(if ($licensed) { 'required (one computer per key)' } else { 'off' }))
    Write-Host ''
    if ($branch -ne 'main') {
        Write-Host ('Client builds normally come from main; this is ' + $branch + '.') -ForegroundColor Yellow
        if ($Interactive -and -not (Confirm 'Build from this branch anyway?')) { throw 'Cancelled.' }
    }
    if ($changes) {
        Write-Host 'There are uncommitted changes:' -ForegroundColor Yellow
        $changes | ForEach-Object { Write-Host ('  ' + $_) }
        if (-not (Confirm 'Build with uncommitted changes? (the build would not match any commit)')) { throw 'Commit or stash the changes first.' }
    }

    # ---- Version and notes ------------------------------------------------------------
    $last = [version]$current
    $suggested = if ($current -eq '0.0.0') { '1.0.0' } else { '{0}.{1}.{2}' -f $last.Major, $last.Minor, ($last.Build + 1) }
    while ($true) {
        if (-not $Version) {
            $Version = (Read-Host ('New version [' + $suggested + '] (major.minor.patch)')).Trim()
            if (-not $Version) { $Version = $suggested }
        }
        if ($Version -notmatch '^\d+\.\d+\.\d+$') { Write-Host 'Use three numbers, like 1.4.2.' -ForegroundColor Red }
        elseif ([version]$Version -lt $last) { Write-Host ('The version must be higher than ' + $current + '.') -ForegroundColor Red }
        elseif ([version]$Version -eq $last -and $current -ne '0.0.0' -and -not (Confirm ($Version + ' was built already. Clients that have it will refuse this setup. Rebuild it?'))) { }
        else { break }
        if (-not $Interactive) { throw ('Invalid version ' + $Version + '.') }
        $Version = ''
    }
    while ($Notes.Trim() -eq '') { $Notes = Read-Host 'Release notes (what changed, one line)' }
    $Notes = ($Notes -replace '[\r\n]+', ' ').Trim()

    # ---- Stage and compile ------------------------------------------------------------
    # $Stage\app: the code, replaced on every upgrade. $Stage\keep: tracked files of the app.ini
    # "keep" folders (placeholders such as writable\index.html), copied only where missing.
    if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }
    $appStage = Join-Path $Stage 'app'
    $keepStage = Join-Path $Stage 'keep'
    New-Item -ItemType Directory -Force $appStage, $keepStage | Out-Null

    Step 'Staging the application'
    $exclude = '^installer/'
    if ($Product['build']['exclude']) { $exclude += '|' + $Product['build']['exclude'] }
    $keep = @(Split-List $Product['app']['keep'] | ForEach-Object { $_.Replace('\', '/').Trim('/') + '/' })
    $isKept = { param($f) foreach ($k in $keep) { if ($f.StartsWith($k, [StringComparison]::OrdinalIgnoreCase)) { return $true } }; return $false }
    Push-Location $Repo
    try {
        $tracked = @(git ls-files)
        foreach ($f in @(git ls-files --cached --others --exclude-standard)) {
            if ($f -match $exclude -or -not (Test-Path -LiteralPath $f -PathType Leaf)) { continue }
            if (& $isKept $f) {
                if ($tracked -notcontains $f) { continue }
                $dest = Join-Path $keepStage $f
            } else {
                $dest = Join-Path $appStage $f
            }
            New-Item -ItemType Directory -Force (Split-Path $dest -Parent) | Out-Null
            Copy-Item -LiteralPath $f $dest
        }
    } finally { Pop-Location }

    if ($composer) {
        # Composer reports progress on stderr, which Windows PowerShell 5.1 turns into errors when output is redirected.
        # The app's PHP extensions are needed on the client (configure.ps1 enables them), not on this build PC.
        $ErrorActionPreference = 'Continue'
        & $tools.php -c $tools.ini $tools.composer install --working-dir $appStage --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --ignore-platform-req=ext-*
        $ErrorActionPreference = 'Stop'
        if ($LASTEXITCODE -ne 0) { throw 'composer install failed.' }
    }
    Copy-Item $icon (Join-Path $Stage 'app.ico')

    if ($licensed) {
        Step 'Adding the license check'
        $check = [IO.File]::ReadAllText((Join-Path $Here 'scripts\license-check.php'))
        if (-not $check.Contains('{{PUBLIC_KEY_PEM}}')) { throw 'scripts\license-check.php has lost its {{PUBLIC_KEY_PEM}} marker.' }
        $pem = [IO.File]::ReadAllText((Join-Path $Here 'license\public-key.pem')).Trim()
        Write-Text (Join-Path $Stage 'license-check.php') $check.Replace('{{PUBLIC_KEY_PEM}}', $pem)
        $entries = @(Split-List $Product['license']['entry_points'])
        if (-not $entries) { throw 'app.ini [license] entry_points must name the app''s PHP entry file(s), e.g. public/index.php.' }
        foreach ($entry in $entries) {
            $file = Join-Path $appStage $entry
            if (-not (Test-Path $file)) { throw ($entry + ' (app.ini [license] entry_points) does not exist in the app.') }
            Add-LicenseHook $file $entry
        }
    }

    Step ('Compiling setup ' + $Version + ' (' + $commit + $(if ($changes) { ', uncommitted changes' } else { '' }) + ')')
    & $iscc /Qp "/DAppVersion=$Version" "/DStage=$Stage" (Join-Path $Here 'setup.iss')
    if ($LASTEXITCODE -ne 0) { throw 'Inno Setup compile failed.' }
    $exe = Join-Path $Here ('output\' + $Product['app']['short_name'] + '-Setup-' + $Version + '.exe')

    # ---- Record the release -----------------------------------------------------------
    $built = Get-Date -Format 'yyyy-MM-dd HH:mm'
    $ini = [IO.File]::ReadAllText($AppIni)
    $ini = Set-ReleaseValue $ini 'version' $Version
    $ini = Set-ReleaseValue $ini 'notes' $Notes
    $ini = Set-ReleaseValue $ini 'built' $built
    $ini = Set-ReleaseValue $ini 'commit' $commit
    Write-Text $AppIni $ini
    $history = if (Test-Path $ReleasesMd) { [IO.File]::ReadAllText($ReleasesMd) -replace '^# Releases\s*', '' } else { '' }
    Write-Text $ReleasesMd ("# Releases`r`n`r`n## " + $Version + ' - ' + $built + ' (' + $branch + ' ' + $commit + ")`r`n`r`n- " + $Notes + "`r`n`r`n" + $history.TrimStart())

    Step ('Built ' + $exe + ' (' + [math]::Round((Get-Item $exe).Length / 1MB, 1) + ' MB)')
    Write-Host 'Commit installer\app.ini and installer\RELEASES.md, then ship the exe to the client.' -ForegroundColor Green
    exit 0
} catch {
    Write-Host ('ERROR: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
