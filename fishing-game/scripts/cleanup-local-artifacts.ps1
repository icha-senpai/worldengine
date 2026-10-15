param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$localRoot = Join-Path $projectRoot '.local'
$deploymentRoot = Join-Path $localRoot 'deployments'
$evidenceRoot = Join-Path $projectRoot '.runtime\maintenance'
New-Item -ItemType Directory -Path $evidenceRoot -Force | Out-Null
$reviewedPlan = $null
if ($Apply) {
    $planPath = Join-Path $evidenceRoot 'cleanup-plan.json'
    if (!(Test-Path -LiteralPath $planPath)) { throw 'Run the cleanup preview before applying' }
    $reviewedPlan = Get-Content -LiteralPath $planPath -Raw | ConvertFrom-Json
    if ($reviewedPlan.apply) { throw 'Run a fresh cleanup preview before applying again' }
}

function Assert-WorkspacePath([string]$Path) {
    $absolute = [IO.Path]::GetFullPath($Path)
    if (!$absolute.StartsWith($projectRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup path escapes Fishbound' }
    $liveData = Join-Path $localRoot 'spacetimedb'
    $runtimeRoot = Join-Path $projectRoot '.runtime'
    $disposableRuntime = @((Join-Path $runtimeRoot 'maintenance'), (Join-Path $runtimeRoot 'web-upgrade-dialog'))
    if (($absolute -ieq $runtimeRoot -or $absolute.StartsWith($runtimeRoot + '\', [StringComparison]::OrdinalIgnoreCase)) -and
        !@($disposableRuntime | Where-Object { $absolute -ieq $_ -or $absolute.StartsWith($_ + '\', [StringComparison]::OrdinalIgnoreCase) }).Count) { throw 'Cleanup targets installed runtime' }
    foreach ($protected in @($liveData, (Join-Path $localRoot 'rust-artifacts'), (Join-Path $projectRoot 'apps\web\build'), (Join-Path $projectRoot 'node_modules'))) {
        if ($absolute -ieq $protected -or $absolute.StartsWith($protected + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup targets live data, runtime or dependencies' }
    }
    $ancestor = $absolute
    while ($ancestor -ine $projectRoot) {
        if ([IO.File]::GetAttributes($ancestor) -band [IO.FileAttributes]::ReparsePoint) { throw 'Refusing cleanup through a filesystem link' }
        $ancestor = [IO.Path]::GetDirectoryName($ancestor)
    }
    return $absolute
}

function Assert-InactiveTarget([string]$Path) {
    $ownerPath = Join-Path $Path '.fishbound-rust-owner.json'
    if (Test-Path -LiteralPath $ownerPath -PathType Leaf) {
        $owner = Get-Content -LiteralPath $ownerPath -Raw | ConvertFrom-Json
        if ($owner.root -ine $projectRoot -or $owner.target -ine [IO.Path]::GetFullPath($Path) -or !$owner.pid) { throw 'Rust cache ownership is invalid' }
        if (Get-Process -Id $owner.pid -ErrorAction SilentlyContinue) { throw "Active Rust workflow owns cleanup target: $Path" }
    }
    foreach ($process in Get-CimInstance Win32_Process) {
        if ($process.ExecutablePath -and ($process.ExecutablePath -ieq $Path -or $process.ExecutablePath.StartsWith($Path + '\', [StringComparison]::OrdinalIgnoreCase))) { throw 'Cleanup would remove a running program' }
        if ($process.CommandLine -and $process.CommandLine.Replace('/', '\').IndexOf($Path, [StringComparison]::OrdinalIgnoreCase) -ge 0) { throw "Active process references cleanup target: $Path" }
    }
}

function Get-VerifiedEntries([string]$Path) {
    $target = Assert-WorkspacePath $Path
    $pending = [Collections.Generic.Queue[string]]::new()
    $pending.Enqueue($target)
    while ($pending.Count) {
        $current = $pending.Dequeue()
        $item = Get-Item -LiteralPath (Assert-WorkspacePath $current) -Force
        if ($item.FullName -ine $target -and !$item.FullName.StartsWith($target + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Entry escapes its cleanup target' }
        [pscustomobject]@{path = $item.FullName; directory = $item.PSIsContainer; bytes = $(if ($item.PSIsContainer) { 0L } else { $item.Length }); modifiedTicks = $item.LastWriteTimeUtc.Ticks}
        if ($item.PSIsContainer) {
            foreach ($child in Get-ChildItem -LiteralPath $item.FullName -Force) { $pending.Enqueue($child.FullName) }
        }
    }
}

function Remove-VerifiedEntries([string]$Path, [object[]]$Entries) {
    $target = Assert-WorkspacePath $Path
    Assert-InactiveTarget $target
    # Validate the entire reviewed list before removing any file. Do not follow
    # links or recursively delete a computed directory.
    foreach ($entry in $Entries) {
        if ($entry.path -ine $target -and !$entry.path.StartsWith($target + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Entry escapes its cleanup target' }
        $item = Get-Item -LiteralPath (Assert-WorkspacePath $entry.path) -Force
        if ($item.PSIsContainer -ne $entry.directory) { throw 'Cleanup entry changed type' }
        if (!$entry.directory -and ($item.Length -ne $entry.bytes -or $item.LastWriteTimeUtc.Ticks -ne $entry.modifiedTicks)) { throw 'Cleanup file changed after review' }
    }
    foreach ($entry in $Entries | Where-Object { !$_.directory }) {
        $null = Assert-WorkspacePath $entry.path
        $item = Get-Item -LiteralPath $entry.path -Force
        if ($item.Length -ne $entry.bytes -or $item.LastWriteTimeUtc.Ticks -ne $entry.modifiedTicks) { throw 'Cleanup file changed before deletion' }
        Remove-Item -LiteralPath $entry.path -Force
    }
    foreach ($entry in $Entries | Where-Object directory | Sort-Object { $_.path.Length } -Descending) {
        $null = Assert-WorkspacePath $entry.path
        if (@(Get-ChildItem -LiteralPath $entry.path -Force).Count) { throw 'Cleanup directory is not empty; refusing unreviewed entries' }
        Remove-Item -LiteralPath $entry.path -Force
    }
}

$installed = Get-Content -LiteralPath (Join-Path $projectRoot '.runtime\bin\installed.json') -Raw | ConvertFrom-Json
foreach ($program in $installed.programs) {
    if ((Get-FileHash -LiteralPath (Join-Path $projectRoot ('.runtime\bin\' + $program.name))).Hash -ne $program.sha256) { throw 'Installed runtime program differs' }
}
$protectedFiles = @('database-process.json', 'achievement-notification-routes.json')
foreach ($service in @('bot', 'linker', 'web', 'database')) {
    $record = Get-Content -LiteralPath (Join-Path $localRoot "$service-process.json") -Raw | ConvertFrom-Json
    $process = Get-Process -Id $record.pid
    if ($process.StartTime.ToUniversalTime().Ticks -ne [long]$record.startedTicks -or $process.Path -ine $record.executable) { throw 'Tracked runtime process changed' }
    if ($service -notin @('web', 'database') -and !$process.Path.StartsWith($projectRoot + '\.runtime\bin\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Services still depend on Cargo target' }
    $protectedFiles += @("$service-process.json", "$service-runner-config.json", [IO.Path]::GetFileName($record.runnerState))
}
foreach ($service in @('bot', 'linker', 'web', 'database')) { $protectedFiles += @("$service.stdout.log", "$service.stderr.log", "$service.archive-status.json") }
$protectedPaths = @()
foreach ($envFile in @((Join-Path $projectRoot '.env'), (Join-Path $projectRoot 'apps\web\.env'))) {
    foreach ($line in Get-Content -LiteralPath $envFile) {
        if ($line -match '^([A-Z_]+_FILE)=(.+)$') {
            $value = $Matches[2].Trim().Trim('"').Trim("'")
            $path = if ([IO.Path]::IsPathRooted($value)) { $value } else { Join-Path (Split-Path -Parent $envFile) $value }
            $protectedPaths += [IO.Path]::GetFullPath($path)
        }
    }
}

$targets = @()
if (Test-Path -LiteralPath $deploymentRoot) { $targets += $deploymentRoot }
foreach ($entry in Get-ChildItem -LiteralPath $evidenceRoot -Force) {
    if ($entry.Name -ne 'cleanup-plan.json') { $targets += $entry.FullName }
}
$oldWeb = Join-Path $projectRoot '.runtime\web-upgrade-dialog'
if (Test-Path -LiteralPath $oldWeb) { $targets += $oldWeb }
foreach ($entry in Get-ChildItem -LiteralPath $localRoot -Force) {
    if ($entry.Name -in @('spacetimedb', 'deployments', 'log-archive', 'rust-artifacts') -or $entry.Name -like 'pre-*' -or $entry.Name -in $protectedFiles -or $entry.Name -match '\.(token|key|pem|pfx)$') { continue }
    if (@($protectedPaths | Where-Object { $_ -ieq $entry.FullName -or $_.StartsWith($entry.FullName + '\', [StringComparison]::OrdinalIgnoreCase) }).Count) { continue }
    $targets += $entry.FullName
}
foreach ($relative in @('target', 'spacetimedb\target', 'apps\web\.svelte-kit', '.playwright-cli', 'output\playwright', 'output\catch-cards', 'output\material-cards', 'output\rod-cards', 'output\performance-cards', 'image.src)})')) {
    $path = Join-Path $projectRoot $relative
    if (Test-Path -LiteralPath $path) { $targets += $path }
}
$targets = @($targets | ForEach-Object { Assert-WorkspacePath $_ } | Select-Object -Unique)
foreach ($target in $targets) { Assert-InactiveTarget $target }
$entries = @{}
$bytes = 0L
foreach ($target in $targets) {
    $entries[$target] = @(Get-VerifiedEntries $target)
    foreach ($entry in $entries[$target]) { $bytes += $entry.bytes }
}
if ($Apply) {
    if (@(Compare-Object $reviewedPlan.targets $targets).Count) { throw 'Cleanup targets changed after preview' }
    foreach ($target in $targets) {
        $reviewed = @{}
        foreach ($entry in $reviewedPlan.entries.PSObject.Properties[$target].Value) { $reviewed[$entry.path] = $entry }
        if ($reviewed.Count -ne $entries[$target].Count) { throw 'Cleanup entries changed after preview' }
        foreach ($entry in $entries[$target]) {
            $old = $reviewed[$entry.path]
            if (!$old -or $old.directory -ne $entry.directory -or $old.bytes -ne $entry.bytes -or (!$entry.directory -and $old.modifiedTicks -ne $entry.modifiedTicks)) { throw 'Cleanup entry changed after preview' }
        }
    }
}
@{apply = [bool]$Apply; bytes = $bytes; targets = $targets; entries = $entries} | ConvertTo-Json -Depth 6 -Compress |
    Set-Content -LiteralPath (Join-Path $evidenceRoot 'cleanup-plan.json') -Encoding utf8
Write-Output ("$($targets.Count) verified cleanup targets, {0:N2} GiB; no deployment copies retained." -f ($bytes / 1GB))
if (!$Apply) { Write-Output 'Inspection only. Use -Apply to remove these verified targets.'; return }
foreach ($target in $targets) {
    Remove-VerifiedEntries $target $entries[$target]
}
Remove-Item -LiteralPath (Join-Path $evidenceRoot 'cleanup-plan.json') -Force
if (!@(Get-ChildItem -LiteralPath $evidenceRoot -Force).Count) { Remove-Item -LiteralPath $evidenceRoot -Force }
Write-Output 'Removed verified scratch/cache and deployment copies. Live data, installed programs, credentials and dependencies retained.'
