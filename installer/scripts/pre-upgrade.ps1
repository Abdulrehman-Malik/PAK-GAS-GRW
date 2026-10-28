# Used by the setup wizard before an upgrade replaces any file:
#  1. unless -SkipBackup, dumps the database to <InstallDir>\backups\pre-upgrade-<from>-to-<to>-<timestamp>.sql
#     (the daily backup task does not prune these);
#  2. removes the old app code, so files deleted from the app do not linger. The settings file and
#     the top-level folders of the app.ini "keep" entries stay.
# Exit 0 = done, 1 = the backup failed (nothing removed), 2 = removing the old code failed.
param(
    [Parameter(Mandatory = $true)][string]$InstallDir,
    [Parameter(Mandatory = $true)][string]$FromVersion,
    [Parameter(Mandatory = $true)][string]$ToVersion,
    [switch]$SkipBackup
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$Product = Get-AppIni $PSScriptRoot
$DbName = $Product['database']['name']
$InstallDir = $InstallDir.TrimEnd('\')
$Log = Join-Path $InstallDir 'logs\backup.log'

if (-not $SkipBackup) {
    $file = Join-Path $InstallDir ('backups\pre-upgrade-' + $FromVersion + '-to-' + $ToVersion + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql')
    try {
        Start-Service ($Product['app']['short_name'] + '-MySQL')
        # 'Continue' here: Windows PowerShell 5.1 turns redirected stderr into errors, fatal under 'Stop'.
        $ErrorActionPreference = 'Continue'
        & (Join-Path $InstallDir 'mysql\bin\mysqldump.exe') ('--defaults-extra-file=' + (Join-Path $InstallDir 'config\mysql-root.cnf')) --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "--result-file=$file" $DbName 2>&1 | Out-Null
        $ErrorActionPreference = 'Stop'
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $file) -or (Get-Item $file).Length -lt 1024) { throw 'mysqldump failed or produced an empty file.' }
        Add-Content $Log ((Get-Date -Format s) + ' OK ' + $file)
    } catch {
        if (Test-Path $file) { Remove-Item $file -Force }
        Add-Content $Log ((Get-Date -Format s) + ' FAILED pre-upgrade backup: ' + $_.Exception.Message) -ErrorAction SilentlyContinue
        exit 1
    }
}

try {
    $appDir = Join-Path $InstallDir 'app'
    $kept = @($Product['app']['env_file']) + @(Split-List $Product['app']['keep']) | ForEach-Object { ($_ -split '[\\/]')[0].ToLower() }
    Get-ChildItem $appDir -Force -ErrorAction SilentlyContinue | Where-Object { $kept -notcontains $_.Name.ToLower() } | Remove-Item -Recurse -Force
    exit 0
} catch {
    exit 2
}
