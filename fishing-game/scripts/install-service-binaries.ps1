param([string]$BotBinary, [string]$LinkerBinary, [ValidateSet('All', 'Bot', 'Linker')][string]$Target = 'All')

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$binRoot = Join-Path $projectRoot '.runtime\bin'
if (!$BotBinary) { $BotBinary = Join-Path $projectRoot '.local\rust-artifacts\discord-bot.exe' }
if (!$LinkerBinary) { $LinkerBinary = Join-Path $projectRoot '.local\rust-artifacts\account-link.exe' }
New-Item -ItemType Directory -Path $binRoot -Force | Out-Null
$sources = @{'discord-bot.exe' = $BotBinary; 'account-link.exe' = $LinkerBinary}
if ($Target -eq 'Bot') { $sources.Remove('account-link.exe') }
if ($Target -eq 'Linker') { $sources.Remove('discord-bot.exe') }
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
$manifestPath = Join-Path $binRoot 'installed.json'
if ($Target -ne 'All' -and (Test-Path -LiteralPath $manifestPath)) {
    $existing = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $manifest = @($existing.programs | Where-Object { !$sources.ContainsKey($_.name) })
}
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
    Set-Content -LiteralPath $manifestPath -Encoding utf8
Write-Output "Installed and hash-verified $($sources.Keys -join ', ') in .runtime/bin. Cargo target folders are build caches."
