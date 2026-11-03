# Run every hour by the "<app name> Hourly Task" scheduled task as SYSTEM: the app command in app.ini [tasks] hourly.
param(
    [Parameter(Mandatory = $true)][string]$InstallDir
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$InstallDir = $InstallDir.TrimEnd('\')
$Command = (Get-AppIni $PSScriptRoot)['tasks']['hourly']
$PhpDir = Join-Path $InstallDir 'php'
$Log = Join-Path $InstallDir 'logs\hourly-task.log'

# Scheduled tasks do not get the Apache service's environment, and PHP extensions need these.
$env:PHPRC = $PhpDir
$env:PATH = $PhpDir + ';' + (Join-Path $PhpDir 'ext') + ';' + $env:PATH

Set-Location (Join-Path $InstallDir 'app')
# 'Continue' here: Windows PowerShell 5.1 turns redirected stderr into errors, fatal under 'Stop'.
$ErrorActionPreference = 'Continue'
$output = (& (Join-Path $PhpDir 'php.exe') ($Command -split '\s+') 2>&1 | ForEach-Object { "$_" }) -join ' '
$code = $LASTEXITCODE
$ErrorActionPreference = 'Stop'

# The log keeps about the last month.
if ((Test-Path $Log) -and (Get-Item $Log).Length -gt 1048576) { Remove-Item $Log -Force }
Add-Content $Log ((Get-Date -Format s) + ' exit ' + $code + ' ' + $output)
exit $code
