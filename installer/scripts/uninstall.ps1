# Run by the uninstaller. Removes the services, backup task, firewall rule and shortcuts, and the app
# code (setup's own file list misses files the app generated). The database (data\), backups\, config\,
# the app's settings file and its keep folders stay, so reinstalling reconnects to the same data.
param([Parameter(Mandatory = $true)][string]$InstallDir)

$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot 'common.ps1')
$Product = Get-AppIni $PSScriptRoot
$AppName = $Product['app']['name']
$InstallDir = $InstallDir.TrimEnd('\')

foreach ($name in ($Product['app']['short_name'] + '-Apache'), ($Product['app']['short_name'] + '-MySQL')) {
    if (Get-Service $name -ErrorAction SilentlyContinue) {
        Stop-Service $name -Force -ErrorAction SilentlyContinue
        & sc.exe delete $name | Out-Null
    }
}
Unregister-ScheduledTask -TaskName ($AppName + ' Daily Backup') -Confirm:$false -ErrorAction SilentlyContinue
Get-NetFirewallRule -Group $AppName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
Remove-Item (Join-Path $env:PUBLIC ('Desktop\' + $AppName + '.url')) -ErrorAction SilentlyContinue
Remove-Item (Join-Path $env:ProgramData ('Microsoft\Windows\Start Menu\Programs\' + $AppName + '.url')) -ErrorAction SilentlyContinue

$appDir = Join-Path $InstallDir 'app'
$kept = @($Product['app']['env_file']) + @(Split-List $Product['app']['keep']) | ForEach-Object { ($_ -split '[\\/]')[0].ToLower() }
Get-ChildItem $appDir -Force -ErrorAction SilentlyContinue | Where-Object { $kept -notcontains $_.Name.ToLower() } | Remove-Item -Recurse -Force
exit 0
