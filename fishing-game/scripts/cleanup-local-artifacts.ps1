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
    foreach ($protected in @($liveData, (Join-Path $projectRoot '.runtime'), (Join-Path $projectRoot 'apps\web\build'), (Join-Path $projectRoot 'node_modules'))) {
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

$verified = @(Get-ChildItem -LiteralPath $deploymentRoot -Directory | Where-Object {
    $acceptance = Join-Path $_.FullName 'acceptance.json'
    $restore = Join-Path $_.FullName 'restore-verification.json'
    if ((Test-Path -LiteralPath $acceptance) -and (Test-Path -LiteralPath $restore)) {
        $a = Get-Content -LiteralPath $acceptance -Raw | ConvertFrom-Json
        $r = Get-Content -LiteralPath $restore -Raw | ConvertFrom-Json
        $a.preservation -and $a.site -eq 200 -and $r.match
    }
} | Sort-Object Name -Descending)
# Sort by the timestamp suffix, not the feature name.
$verified = @($verified | Sort-Object { $_.Name.Substring($_.Name.Length - 15) } -Descending)
if ($verified.Count -lt 2) { throw 'Two verified rollback sets are required' }
$keep = @($verified | Select-Object -First 2)
$targets = @()
foreach ($folder in Get-ChildItem -LiteralPath $deploymentRoot -Directory) {
    if ($folder.Name -notmatch '^[a-z-]+-\d{8}-\d{6}$') { throw 'Unrecognized deployment directory' }
    if ($keep.FullName -contains $folder.FullName) {
        $partial = Join-Path $folder.FullName 'cold-data.partial.zip'
        $archive = Join-Path $folder.FullName 'cold-data.zip'
        $report = Join-Path $folder.FullName 'archive-verification.json'
        if (Test-Path -LiteralPath $report) {
            $verification = Get-Content -LiteralPath $report -Raw | ConvertFrom-Json
            $file = if (Test-Path -LiteralPath $archive) { $archive } else { $partial }
            if (!$verification.verified -or (Get-FileHash -LiteralPath $file).Hash -ine $verification.archiveSha256) { throw 'Backup archive verification failed' }
            if ($Apply -and $file -eq $partial) { Move-Item -LiteralPath (Assert-WorkspacePath $partial) -Destination $archive }
            if (Test-Path -LiteralPath (Join-Path $folder.FullName 'cold-data')) { $targets += Join-Path $folder.FullName 'cold-data' }
        }
        foreach ($duplicate in @('restore-check', 'retired-served-web-build', 'candidate-web-build')) {
            $path = Join-Path $folder.FullName $duplicate
            if (Test-Path -LiteralPath $path) { $targets += $path }
        }
        # Old clients and previous-web-build remain paired with the backup.
    } else {
        if ($Apply) {
            $history = Join-Path $evidenceRoot ('deployment-history\' + $folder.Name)
            New-Item -ItemType Directory -Path $history -Force | Out-Null
            Get-ChildItem -LiteralPath $folder.FullName -File | Where-Object { $_.Name -match '(acceptance|verification|manifest|deployment|module-before).*\.json$' } |
                ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $history $_.Name) }
        }
        $targets += $folder.FullName
    }
}
foreach ($entry in Get-ChildItem -LiteralPath $localRoot -Force) {
    if ($entry.Name -in @('spacetimedb', 'deployments', 'log-archive') -or $entry.Name -like 'pre-*' -or $entry.Name -in $protectedFiles -or $entry.Name -match '\.(token|key|pem|pfx)$') { continue }
    if (@($protectedPaths | Where-Object { $_ -ieq $entry.FullName -or $_.StartsWith($entry.FullName + '\', [StringComparison]::OrdinalIgnoreCase) }).Count) { continue }
    if ($entry.Name -like 'current-*-deployment.txt') {
        $pointsTo = (Get-Content -LiteralPath $entry.FullName -Raw).Trim()
        if ($keep.FullName -contains $pointsTo) { continue }
    }
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
    if (@(Compare-Object $reviewedPlan.targets $targets).Count -or @(Compare-Object $reviewedPlan.retainedDeployments @($keep.Name)).Count) { throw 'Cleanup targets changed after preview' }
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
@{apply = [bool]$Apply; retainedDeployments = @($keep.Name); bytes = $bytes; targets = $targets; entries = $entries} | ConvertTo-Json -Depth 6 -Compress |
    Set-Content -LiteralPath (Join-Path $evidenceRoot 'cleanup-plan.json') -Encoding utf8
Write-Output ("$($targets.Count) verified cleanup targets, {0:N2} GiB; keep $($keep.Name -join ', ')." -f ($bytes / 1GB))
if (!$Apply) { Write-Output 'Inspection only. Use -Apply to remove these verified targets.'; return }
foreach ($target in $targets) {
    Remove-VerifiedEntries $target $entries[$target]
}
@{completedAt = (Get-Date).ToUniversalTime().ToString('o'); bytesRemoved = $bytes; targetsRemoved = $targets.Count; retainedDeployments = @($keep.Name)} | ConvertTo-Json -Depth 4 |
    Set-Content -LiteralPath (Join-Path $evidenceRoot 'cleanup-result.json') -Encoding utf8
Write-Output 'Removed verified scratch/cache targets. Live data, installed programs, credentials, dependencies and two rollback sets retained.'
