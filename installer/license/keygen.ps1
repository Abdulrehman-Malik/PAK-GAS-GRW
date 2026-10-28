# License keys for the app in ..\app.ini (developer PC only). Run it through keygen.bat.
# The first run creates the app's signing key:
#   private key  %USERPROFILE%\.app-licenses\<short_name>\license-private.xml  (never commit or ship it; back it up)
#   public key   public-key.xml (checked by setup.exe) and public-key.pem (checked by license-check.php)
#                next to this script - commit both.
# Each later run turns a client's Machine ID into a license key that works only on that computer,
# and records it in issued-licenses.csv next to the private key.
param(
    [string]$MachineId = '',
    [string]$Customer = '',
    [string]$Expires = '',
    [string]$KeyDir = '',
    [string]$PublicDir = $PSScriptRoot,
    [switch]$CreateSigningKey,
    # Only make sure the signing key exists (used by build.ps1 on an app's first build); issue no license.
    [switch]$InitOnly
)

$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path $PSScriptRoot -Parent) 'scripts\common.ps1')
$Product = Read-Ini (Join-Path (Split-Path $PSScriptRoot -Parent) 'app.ini')
$AppName = $Product['app']['name']
if (-not $KeyDir) { $KeyDir = Join-Path $env:USERPROFILE ('.app-licenses\' + $Product['app']['short_name']) }
$PrivateFile = Join-Path $KeyDir 'license-private.xml'
$IssuedFile = Join-Path $KeyDir 'issued-licenses.csv'
$PublicXml = Join-Path $PublicDir 'public-key.xml'
$PublicPem = Join-Path $PublicDir 'public-key.pem'

function ToBase64Url([byte[]]$bytes) { return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_') }

# Minimal DER encoding of an RSA SubjectPublicKeyInfo, so PHP's openssl can read the public key.
function DerLength([int]$n) {
    if ($n -lt 128) { return , [byte[]]@($n) }
    if ($n -lt 256) { return , [byte[]]@(0x81, $n) }
    return , [byte[]]@(0x82, ($n -shr 8), ($n -band 255))
}
function Der([byte]$tag, [byte[]]$body) { return , [byte[]](@($tag) + (DerLength $body.Length) + $body) }
function DerInteger([byte[]]$value) {
    if ($value[0] -ge 0x80) { $value = [byte[]](@([byte]0) + $value) }
    return , (Der 0x02 $value)
}
function ToPem([Security.Cryptography.RSAParameters]$p) {
    $rsaKey = Der 0x30 ([byte[]]((DerInteger $p.Modulus) + (DerInteger $p.Exponent)))
    $algorithm = [byte[]](0x30, 0x0D, 0x06, 0x09, 0x2A, 0x86, 0x48, 0x86, 0xF7, 0x0D, 0x01, 0x01, 0x01, 0x05, 0x00)
    $spki = Der 0x30 ([byte[]]($algorithm + (Der 0x03 ([byte[]](@([byte]0) + $rsaKey)))))
    $b64 = [Convert]::ToBase64String($spki) -split '(.{64})' | Where-Object { $_ }
    return "-----BEGIN PUBLIC KEY-----`n" + ($b64 -join "`n") + "`n-----END PUBLIC KEY-----`n"
}

function Save([string]$path, [string]$text) {
    New-Item -ItemType Directory -Force (Split-Path $path -Parent) | Out-Null
    Write-Text $path $text
}

try {
    Write-Host ('License keys for ' + $AppName) -ForegroundColor Green
    if (-not (Test-Path $PrivateFile)) {
        if ((Test-Path $PublicXml) -or (Test-Path $PublicPem)) {
            throw ('The signing key is missing on this PC, but ' + $PublicDir + ' already has its public key. Copy license-private.xml from your backup to ' + $KeyDir + '. (A new signing key would make every issued license invalid.)')
        }
        Write-Host ('No signing key exists yet for ' + $AppName + '. It is created once and used for every client.') -ForegroundColor Yellow
        if (-not $CreateSigningKey -and (Read-Host 'Create it now? (y/N)') -notmatch '^[yY]') { exit 1 }
        $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider 2048
        $rsa.PersistKeyInCsp = $false
        # All three files or none: a private key without its public files blocks every later run.
        try {
            Save $PublicXml $rsa.ToXmlString($false)
            Save $PublicPem (ToPem $rsa.ExportParameters($false))
            Save $PrivateFile $rsa.ToXmlString($true)
            & icacls.exe $PrivateFile /inheritance:r /grant:r ($env:USERNAME + ':F') | Out-Null
        } catch {
            Remove-Item $PublicXml, $PublicPem, $PrivateFile -Force -ErrorAction SilentlyContinue
            throw
        }
        Write-Host ('Created ' + $PrivateFile) -ForegroundColor Green
        Write-Host '  BACK THIS FILE UP (e.g. on a USB drive or in a password manager). Without it no new license can be issued.' -ForegroundColor Yellow
        Write-Host ('Created ' + $PublicXml + ' and ' + $PublicPem + ' - commit both.') -ForegroundColor Green
        Write-Host ''
    }
    $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider
    $rsa.PersistKeyInCsp = $false
    $rsa.FromXmlString([IO.File]::ReadAllText($PrivateFile))
    if (-not (Test-Path $PublicXml) -or ([IO.File]::ReadAllText($PublicXml) -ne $rsa.ToXmlString($false))) {
        throw ($PublicXml + ' is missing or does not belong to ' + $PrivateFile + '.')
    }
    if ($InitOnly) { exit 0 }

    while ($Customer.Trim() -eq '') { $Customer = Read-Host 'Customer / shop name' }
    while ($true) {
        if (-not $MachineId) { $MachineId = Read-Host 'Machine ID (shown by setup on the client PC)' }
        $MachineId = ($MachineId.ToUpper() -replace '[^0-9A-Z]', '')
        if ($MachineId -match '^[0-9A-Z]{20}$') { $MachineId = ($MachineId -split '(.{4})' | Where-Object { $_ }) -join '-'; break }
        Write-Host 'A Machine ID has 20 letters/digits, like B8AC-QMJ3-84GM-V3AK-PKG3.' -ForegroundColor Red
        $MachineId = ''
    }
    if (-not $PSBoundParameters.ContainsKey('Expires')) { $Expires = Read-Host 'Expiry date yyyy-mm-dd (leave blank for no expiry)' }
    $Expires = $Expires.Trim()
    if ($Expires) { $Expires = [datetime]::ParseExact($Expires, 'yyyy-MM-dd', $null).ToString('yyyy-MM-dd') }

    $payload = [ordered]@{ v = 1; m = $MachineId; c = $Customer.Trim(); i = (Get-Date -Format 'yyyy-MM-dd'); e = $Expires } | ConvertTo-Json -Compress
    $body = ToBase64Url ([Text.Encoding]::UTF8.GetBytes($payload))
    $key = $body + '.' + (ToBase64Url $rsa.SignData([Text.Encoding]::ASCII.GetBytes($body), 'SHA256'))

    if (-not (Test-Path $IssuedFile)) { Save $IssuedFile "issued,customer,machine_id,expires,key`r`n" }
    Add-Content $IssuedFile ('{0},"{1}",{2},{3},{4}' -f (Get-Date -Format s), $Customer.Trim().Replace('"', "'"), $MachineId, $Expires, $key)

    Write-Host ''
    Write-Host ('License key for ' + $Customer.Trim() + ' (' + $MachineId + '):') -ForegroundColor Green
    Write-Host $key
    try { Set-Clipboard -Value $key; Write-Host '(copied to the clipboard)' -ForegroundColor Green } catch { }
    exit 0
} catch {
    Write-Host ('ERROR: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
