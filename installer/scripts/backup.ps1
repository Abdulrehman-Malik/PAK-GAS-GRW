# Daily database backup, run by the "<app name> Daily Backup" scheduled task as SYSTEM.
# Dumps land in <InstallDir>\backups as auto-<database>-<timestamp>.sql; older than -KeepDays are removed.
# Also refreshes the license Machine ID cache (see common.ps1).
param(
    [Parameter(Mandatory = $true)][string]$InstallDir,
    [int]$KeepDays = 30
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$DbName = (Get-AppIni $PSScriptRoot)['database']['name']
$InstallDir = $InstallDir.TrimEnd('\')
$BackupDir = Join-Path $InstallDir 'backups'
$RootCnf = Join-Path $InstallDir 'config\mysql-root.cnf'
$Dump = Join-Path $InstallDir 'mysql\bin\mysqldump.exe'
$Log = Join-Path $InstallDir 'logs\backup.log'

try { Update-LicenseCache $InstallDir } catch { }

$file = Join-Path $BackupDir ('auto-' + $DbName + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql')
try {
    # 'Continue' here: Windows PowerShell 5.1 turns redirected stderr into errors, fatal under 'Stop'.
    $ErrorActionPreference = 'Continue'
    & $Dump "--defaults-extra-file=$RootCnf" --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "--result-file=$file" $DbName 2>&1 | Out-Null
    $ErrorActionPreference = 'Stop'
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $file) -or (Get-Item $file).Length -lt 1024) { throw 'mysqldump failed or produced an empty file.' }
    Get-ChildItem $BackupDir -Filter ('auto-' + $DbName + '-*.sql') | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) } | Remove-Item -Force
    Add-Content $Log ((Get-Date -Format s) + ' OK ' + $file)
} catch {
    if (Test-Path $file) { Remove-Item $file -Force }
    Add-Content $Log ((Get-Date -Format s) + ' FAILED ' + $_.Exception.Message)
    exit 1
}
