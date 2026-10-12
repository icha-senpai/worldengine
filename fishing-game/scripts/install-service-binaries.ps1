param([string]$BotBinary, [string]$LinkerBinary)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$binRoot = Join-Path $projectRoot '.runtime\bin'
if (!$BotBinary) { $BotBinary = Join-Path $projectRoot 'target\release\discord-bot.exe' }
if (!$LinkerBinary) { $LinkerBinary = Join-Path $projectRoot 'target\release\account-link.exe' }
New-Item -ItemType Directory -Path $binRoot -Force | Out-Null
$sources = @{'discord-bot.exe' = $BotBinary; 'account-link.exe' = $LinkerBinary}
foreach ($name in $sources.Keys) {
    $source = [IO.Path]::GetFullPath($sources[$name])
    $destination = [IO.Path]::GetFullPath((Join-Path $binRoot $name))
    if (!(Test-Path -LiteralPath $source -PathType Leaf)) { throw "Missing built service: $source" }
    if (!$destination.StartsWith($projectRoot + '\.runtime\bin\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Runtime path escapes project' }
    foreach ($process in Get-Process -Name ([IO.Path]::GetFileNameWithoutExtension($name)) -ErrorAction SilentlyContinue) {
        if ($process.Path -ieq $destination) { throw 'Close Fishbound services before replacing installed programs.' }
    }
}
$manifest = @()
foreach ($name in $sources.Keys) {
    $source = [IO.Path]::GetFullPath($sources[$name])
    $destination = Join-Path $binRoot $name
    $staged = $destination + '.new'
    Copy-Item -LiteralPath $source -Destination $staged -Force
    $hash = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
    if ((Get-FileHash -LiteralPath $staged -Algorithm SHA256).Hash -ne $hash) { throw "Staged program differs: $name" }
    Move-Item -LiteralPath $staged -Destination $destination -Force
    $manifest += @{name = $name; sha256 = $hash; bytes = (Get-Item -LiteralPath $destination).Length}
}
@{installedAt = (Get-Date).ToUniversalTime().ToString('o'); programs = $manifest} | ConvertTo-Json -Depth 4 |
    Set-Content -LiteralPath (Join-Path $binRoot 'installed.json') -Encoding utf8
Write-Output 'Installed and hash-verified both services in .runtime/bin. Cargo target folders are build caches.'
