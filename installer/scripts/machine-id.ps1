# Prints this computer's Machine ID for license keys (20 characters as XXXX-XXXX-XXXX-XXXX-XXXX).
# It is a SHA-256 of the motherboard's SMBIOS UUID and serial number, so it survives a Windows
# reinstall but changes when the motherboard is replaced. Windows' MachineGuid is used only when
# the hardware reports no usable IDs (some cheap boards and VMs).
# Used by the setup wizard (license.ps1) and, installed in <InstallDir>\installer, by license-check.php.
# Windows PowerShell 5.1 compatible.
$ErrorActionPreference = 'Stop'

# Placeholder values that boards ship with instead of a real ID.
function Clean($value) {
    $s = ([string]$value).Trim().ToUpper()
    $plain = $s -replace '[-\s]', ''
    if ($plain -match '^(0*|F*)$' -or $plain -eq '03000200040005000006000700080009' -or
        $s -match '^(NONE|DEFAULT STRING|TO BE FILLED BY O\.E\.M\.|SYSTEM SERIAL NUMBER|NOT APPLICABLE|NOT SPECIFIED|N/A|BASE BOARD SERIAL NUMBER)$') { return '' }
    return $s
}

$parts = @(
    (Clean (Get-CimInstance Win32_ComputerSystemProduct).UUID),
    (Clean (Get-CimInstance Win32_BaseBoard | Select-Object -First 1).SerialNumber)
) | Where-Object { $_ }
if (-not $parts) { $parts = @('GUID:' + (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Cryptography').MachineGuid.ToUpper()) }

$hash = [Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes('machine-id|' + ($parts -join '|')))

# Base32 without I, L, O and U, so the ID can be read out over the phone.
$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
$id = ''
$buffer = 0
$bits = 0
foreach ($b in $hash[0..12]) {
    $buffer = ($buffer -shl 8) -bor $b
    $bits += 8
    while ($bits -ge 5) {
        $bits -= 5
        $id += $alphabet[($buffer -shr $bits) -band 31]
    }
    $buffer = $buffer -band ((1 -shl $bits) - 1)
}
Write-Output (($id.Substring(0, 20) -split '(.{4})' | Where-Object { $_ }) -join '-')
