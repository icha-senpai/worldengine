param([switch]$Apply)
$ErrorActionPreference = 'Stop'
$project = [IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
$runtime = Join-Path $project '.runtime'
$storage = Join-Path $runtime 'bitcraft-storage'

function Assert-Unlinked([string]$Path) {
    $cursor = [IO.Path]::GetFullPath($Path)
    if (-not $cursor.StartsWith($project + '\', [StringComparison]::OrdinalIgnoreCase)) { throw "Outside Space: $Path" }
    while ($cursor -ne $project) {
        if ((Get-Item -LiteralPath $cursor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Linked cleanup path: $cursor" }
        $cursor = Split-Path $cursor -Parent
    }
}
function Get-ReviewedEntries([string]$Path) {
    Assert-Unlinked $Path
    $item = Get-Item -LiteralPath $Path -Force
    $item
    if ($item.PSIsContainer) {
        foreach ($child in Get-ChildItem -LiteralPath $Path -Force) { Get-ReviewedEntries $child.FullName }
    }
}

# A reset can need its transfer file to finish restoration. Never clean that state.
foreach ($database in @('space-bitcraft-tools', 'space-bitcraft-checks')) {
    $path = Join-Path $storage "$database.json"
    Assert-Unlinked $path
    $binding = Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
    if ($binding.database -ne $database -or $binding.pending) { throw "Unfinished or mismatched maintenance: $database" }
}
if ($Apply) {
    foreach ($name in @('collector', 'collector-test', 'collector.supervisor', 'collector-test.supervisor')) {
        $path = Join-Path $runtime "services\$name.pid"
        if (Test-Path -LiteralPath $path) {
            $workerId = [int](Get-Content -LiteralPath $path -Raw).Trim()
            if (Get-Process -Id $workerId -ErrorAction SilentlyContinue) { throw 'Stop only the owned Space collectors before applying cleanup.' }
        }
    }
}
$targets = [Collections.Generic.List[string]]::new()
foreach ($relative in @('.runtime\backups', '.runtime\ui-preview', '.runtime\xp-render', '.playwright-cli', 'output\playwright', 'output\performance')) {
    $path = Join-Path $project $relative
    if (Test-Path -LiteralPath $path) { $targets.Add($path) }
}
# Top-level runtime files are old one-off scripts/results, not the installed runtime.
# Keep the old provider config, which may contain credentials.
foreach ($item in Get-ChildItem -LiteralPath $runtime -Force -File) {
    if ($item.Name -ne 'provider-config.json' -and $item.Extension -in @('.js', '.mjs', '.ts', '.txt', '.json', '.log', '.py', '.ps1', '.vue')) { $targets.Add($item.FullName) }
}
foreach ($item in Get-ChildItem -LiteralPath (Join-Path $project 'output') -Force -File) {
    if ($item.Name -match '^(collector|market-storage|relay-).+\.json$') { $targets.Add($item.FullName) }
}
foreach ($item in Get-ChildItem -LiteralPath $storage -Force -File) {
    if ($item.Name -match '^space-bitcraft-(tools|checks)-\d+\.json(?:\.sha256)?$') { $targets.Add($item.FullName) }
}
foreach ($database in @('space-bitcraft-tools', 'space-bitcraft-checks')) {
    $directory = Join-Path $runtime "diagnostics\$database"
    foreach ($item in Get-ChildItem -LiteralPath $directory -Force -File) {
        if ($item.Name -match '^archive-\d+-.*\.gz(?:\.json|\.tmp)?$' -or $item.Name -match '^pending-\d+-') { $targets.Add($item.FullName) }
    }
}
$entries = @()
$processes = @(Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -ne $PID })
foreach ($target in $targets) {
    foreach ($process in $processes) {
        if ($process.CommandLine -and $process.CommandLine.IndexOf($target, [StringComparison]::OrdinalIgnoreCase) -ge 0) { throw "Active cleanup target: $target" }
    }
    $entries += @(Get-ReviewedEntries $target | ForEach-Object {
        [pscustomobject]@{ Path=$_.FullName; Directory=$_.PSIsContainer; Bytes=if ($_.PSIsContainer) {0} else {$_.Length}; Modified=$_.LastWriteTimeUtc.Ticks }
    })
}
$bytes = ($entries | Measure-Object -Property Bytes -Sum).Sum
Write-Host "$($targets.Count) scoped targets, $($entries.Count) entries, $([Math]::Round($bytes / 1GB, 3)) GiB."
foreach ($target in $targets) { Write-Host $target.Substring($project.Length + 1) }
if (-not $Apply) { Write-Host 'Preview only. No cleanup plan or report saved.'; exit 0 }
# Recheck every file before the first mutation. Delete only reviewed literal files,
# then empty directories; an unexpected new file prevents directory removal.
foreach ($entry in $entries) {
    Assert-Unlinked $entry.Path
    $current = Get-Item -LiteralPath $entry.Path -Force
    if ($current.PSIsContainer -ne $entry.Directory -or $current.LastWriteTimeUtc.Ticks -ne $entry.Modified -or (-not $entry.Directory -and $current.Length -ne $entry.Bytes)) { throw "Cleanup entry changed: $($entry.Path)" }
}
foreach ($entry in $entries | Where-Object { -not $_.Directory }) { Remove-Item -LiteralPath $entry.Path -Force }
foreach ($entry in $entries | Where-Object Directory | Sort-Object { $_.Path.Length } -Descending) {
    Assert-Unlinked $entry.Path
    [IO.Directory]::Delete($entry.Path, $false)
}
Write-Host "Removed $bytes bytes. Active database, bindings, sealed bundles, identities, credentials, tools, dependencies and requested artifacts preserved."
