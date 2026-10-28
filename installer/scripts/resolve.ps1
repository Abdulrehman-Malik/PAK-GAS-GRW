# Used by the setup wizard: finds the latest patch release of each tested server line and writes
# their download URLs and checksums to $OutFile (an .ini read by setup, which downloads them).
# A new PHP minor or MySQL major is not picked up automatically: the lines come from installer\version.ini.
param(
    [Parameter(Mandatory = $true)][string]$OutFile,
    [string]$PhpLine = '8.5',
    [string]$MySqlLine = '8.4',
    # Only PHP (build.ps1 runs Composer with it).
    [switch]$PhpOnly
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
# Windows PowerShell 5.1 does not offer TLS 1.2 by default on every Windows build (aka.ms needs it).
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

# The search for the newest patch starts here and stops at the first missing one: when moving to a
# new MySQL line, set this to a patch the CDN still serves.
$MySqlMinPatch = if ($MySqlLine -eq '8.4') { 13 } else { 0 }
$UserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'

function Get-Text([string]$url) {
    $content = (Invoke-WebRequest $url -UserAgent $UserAgent -UseBasicParsing -TimeoutSec 60).Content
    if ($content -is [byte[]]) { $content = [Text.Encoding]::ASCII.GetString($content) }
    return [string]$content
}

$out = New-Object Text.StringBuilder
function Add-Component([string]$name, [string]$version, [string]$url, [string]$sha256) {
    [void]$out.AppendLine('[' + $name + ']')
    [void]$out.AppendLine('version=' + $version)
    [void]$out.AppendLine('url=' + $url)
    [void]$out.AppendLine('sha256=' + $sha256.ToLower())
    [void]$out.AppendLine('file=' + $name + [IO.Path]::GetExtension(([uri]$url).AbsolutePath))
}

try {
    # PHP: Thread Safe x64 build (the Apache module needs TS); php.net publishes the SHA-256.
    $releases = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json' -UserAgent $UserAgent -TimeoutSec 60
    $php = $releases.$PhpLine
    $zip = ($php.PSObject.Properties | Where-Object { $_.Name -match '^ts-vs\d+-x64$' } | Select-Object -First 1).Value.zip
    if (-not $zip) { throw ('No PHP ' + $PhpLine + ' Thread Safe x64 build is listed on windows.php.net.') }
    Add-Component 'php' $php.version ('https://windows.php.net/downloads/releases/' + $zip.path) $zip.sha256
    if ($PhpOnly) {
        [IO.File]::WriteAllText($OutFile, $out.ToString(), (New-Object Text.UTF8Encoding($false)))
        exit 0
    }

    # Apache: newest Win64 build on Apache Lounge, with the SHA-256 from its checksum file.
    $page = Get-Text 'https://www.apachelounge.com/download/'
    $path = ([regex]::Matches($page, '/download/VS\d+/binaries/httpd-2\.4\.\d+-\d+-Win64-VS\d+\.zip') | Select-Object -First 1).Value
    if (-not $path) { throw 'Could not find the Apache download on apachelounge.com.' }
    $apacheUrl = 'https://www.apachelounge.com' + $path
    $sums = Get-Text ($apacheUrl + '.txt')
    $sha = [regex]::Match($sums, 'SHA256-Checksum for:[^\r\n]*\s+([0-9A-Fa-f]{64})').Groups[1].Value
    if (-not $sha) { throw 'Could not read the Apache SHA-256 checksum.' }
    Add-Component 'apache' ([regex]::Match($path, '2\.4\.\d+').Value) $apacheUrl $sha

    # MySQL: highest published patch of the LTS line. The MySQL CDN publishes no machine-readable
    # checksum, so this download is trusted on HTTPS from cdn.mysql.com alone.
    $patch = $null
    for ($v = $MySqlMinPatch; $v -lt $MySqlMinPatch + 60; $v++) {
        try {
            Invoke-WebRequest ('https://cdn.mysql.com/Downloads/MySQL-' + $MySqlLine + '/mysql-' + $MySqlLine + '.' + $v + '-winx64.zip') -Method Head -UserAgent $UserAgent -UseBasicParsing -TimeoutSec 30 | Out-Null
            $patch = $v
        } catch { break }
    }
    if ($null -eq $patch) { throw ('Could not find MySQL ' + $MySqlLine + ' on cdn.mysql.com.') }
    $mysqlVersion = $MySqlLine + '.' + $patch
    Add-Component 'mysql' $mysqlVersion ('https://cdn.mysql.com/Downloads/MySQL-' + $MySqlLine + '/mysql-' + $mysqlVersion + '-winx64.zip') ''

    # VC++ runtime (Microsoft-signed; configure.ps1 checks the signature) and the curl CA bundle.
    Add-Component 'vcredist' 'latest' 'https://aka.ms/vs/17/release/vc_redist.x64.exe' ''
    $caSha = ((Get-Text 'https://curl.se/ca/cacert.pem.sha256') -split '\s+')[0]
    Add-Component 'cacert' 'latest' 'https://curl.se/ca/cacert.pem' $caSha

    [IO.File]::WriteAllText($OutFile, $out.ToString(), (New-Object Text.UTF8Encoding($false)))
    exit 0
} catch {
    [IO.File]::WriteAllText($OutFile, "[error]`r`nmessage=" + ($_.Exception.Message -replace '[\r\n]+', ' ') + "`r`n", (New-Object Text.UTF8Encoding($false)))
    exit 1
}
