# Shared by the installer scripts (dot-sourced). Reads app.ini, which sits next to the scripts both
# in the repository's installer\ folder (via ..\app.ini) and in <InstallDir>\installer on the client.
# Windows PowerShell 5.1 compatible.

# .ini file -> @{ section = @{ key = value } }; lines starting with ; or # are comments.
function Read-Ini([string]$path) {
    $sections = @{}
    $current = $null
    foreach ($line in Get-Content $path) {
        if ($line -match '^\s*[;#]') { continue }
        if ($line -match '^\s*\[(.+)\]\s*$') { $current = [ordered]@{}; $sections[$Matches[1]] = $current }
        elseif ($null -ne $current -and $line -match '^\s*([^=]+?)\s*=\s*(.*)$') { $current[$Matches[1]] = $Matches[2].Trim() }
    }
    return $sections
}

function Get-AppIni([string]$scriptDir) {
    foreach ($p in (Join-Path $scriptDir 'app.ini'), (Join-Path (Split-Path $scriptDir -Parent) 'app.ini')) {
        if (Test-Path $p) { return (Read-Ini $p) }
    }
    throw ('app.ini was not found next to ' + $scriptDir)
}

function Split-List([string]$value) { return @($value -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ }) }

function Write-Text([string]$path, [string]$text) {
    [IO.File]::WriteAllText($path, $text, (New-Object Text.UTF8Encoding($false)))
}

# license-check.php caches the Machine ID for a day because reading it takes seconds; setup and the
# daily backup task refresh that cache so no web request has to wait. No-op without licensing.
function Update-LicenseCache([string]$installDir) {
    $script = Join-Path $installDir 'installer\machine-id.ps1'
    if (-not (Test-Path $script)) { return }
    $id = [string](& $script)
    if ($id -match '^[0-9A-Z]{4}(-[0-9A-Z]{4}){4}$') {
        Write-Text (Join-Path $installDir 'config\license-machine-id.cache') ($env:COMPUTERNAME.ToUpper() + '|' + $id)
    }
}
