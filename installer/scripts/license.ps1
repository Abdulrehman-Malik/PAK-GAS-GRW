# Used by the setup wizard, which reads the result from -OutFile ([license] section):
#   -Action machineid  machine_id = this computer's Machine ID (see machine-id.ps1).
#   -Action verify     ok = 1 when -Key (or the key in -KeyFile) is signed with the app's license
#                      key (-PublicKeyFile) and issued for this computer; otherwise ok = 0 and message.
# Key format: <payload>.<signature>, both base64url; the payload is JSON {v, m (Machine ID), c (customer),
# i (issued), e (expires, optional)} and the signature is RSA-SHA256 over the payload text. See license\keygen.ps1.
param(
    [Parameter(Mandatory = $true)][ValidateSet('machineid', 'verify')][string]$Action,
    [Parameter(Mandatory = $true)][string]$OutFile,
    [string]$Key = '',
    [string]$KeyFile = '',
    [string]$PublicKeyFile = ''
)

$ErrorActionPreference = 'Stop'
$lines = @('[license]')

function FromBase64Url([string]$text) {
    $s = $text.Replace('-', '+').Replace('_', '/')
    switch ($s.Length % 4) { 2 { $s += '==' } 3 { $s += '=' } }
    return [Convert]::FromBase64String($s)
}

try {
    $machineId = [string](& (Join-Path $PSScriptRoot 'machine-id.ps1'))
    if ($machineId -notmatch '^[0-9A-Z]{4}(-[0-9A-Z]{4}){4}$') { throw 'Could not read the Machine ID of this computer.' }
    $lines += 'machine_id=' + $machineId

    if ($Action -eq 'verify') {
        if ($KeyFile) { $Key = [IO.File]::ReadAllText($KeyFile) }
        $Key = $Key -replace '\s', ''
        $parts = $Key.Split('.')
        if ($parts.Count -ne 2 -or -not $parts[0] -or -not $parts[1]) { throw 'This is not a license key. Paste the whole key exactly as you received it.' }

        $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider
        $rsa.FromXmlString([IO.File]::ReadAllText($PublicKeyFile))
        try { $signed = $rsa.VerifyData([Text.Encoding]::ASCII.GetBytes($parts[0]), 'SHA256', (FromBase64Url $parts[1])) } catch { $signed = $false }
        if (-not $signed) { throw 'This license key is not valid. Paste the whole key exactly as you received it.' }

        $license = [Text.Encoding]::UTF8.GetString((FromBase64Url $parts[0])) | ConvertFrom-Json
        if ($license.m -ne $machineId) { throw ('This license key is for another computer (' + $license.m + '). This computer''s Machine ID is ' + $machineId + '.') }
        if ($license.e -and [datetime]::ParseExact($license.e, 'yyyy-MM-dd', $null).AddDays(1) -le (Get-Date)) { throw ('This license key expired on ' + $license.e + '.') }
        $lines += 'customer=' + ($license.c -replace '[\r\n]', ' ')
        $lines += 'expires=' + $license.e
    }
    $lines += 'ok=1'
    $code = 0
} catch {
    $lines += 'ok=0'
    $lines += 'message=' + ($_.Exception.Message -replace '[\r\n]+', ' ')
    $code = 1
}
[IO.File]::WriteAllText($OutFile, ($lines -join "`r`n") + "`r`n", (New-Object Text.UTF8Encoding($false)))
exit $code
