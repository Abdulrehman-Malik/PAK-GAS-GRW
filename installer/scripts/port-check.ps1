# Used by the setup wizard: exit 0 when $Port is free or held by this installation's own Apache, 1 when another program uses it.
param([Parameter(Mandatory = $true)][int]$Port, [string]$InstallDir = '')

$own = ($InstallDir.TrimEnd('\') + '\apache\*')
foreach ($l in Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) {
    $path = (Get-Process -Id $l.OwningProcess -ErrorAction SilentlyContinue).Path
    if (-not ($InstallDir -and $path -and $path -like $own)) { exit 1 }
}
exit 0
