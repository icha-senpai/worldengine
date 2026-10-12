param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$localRoot = Join-Path $projectRoot '.local'
$deploymentRoot = Join-Path $localRoot 'deployments'
$evidenceRoot = Join-Path $projectRoot '.runtime\maintenance'
New-Item -ItemType Directory -Path $evidenceRoot -Force | Out-Null

function Assert-WorkspacePath([string]$Path) {
    $absolute = [IO.Path]::GetFullPath($Path)
    if (!$absolute.StartsWith($projectRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup path escapes Fishbound' }
    $liveData = Join-Path $localRoot 'spacetimedb'
    foreach ($protected in @($liveData, (Join-Path $projectRoot '.runtime'), (Join-Path $projectRoot 'apps\web\build'), (Join-Path $projectRoot 'node_modules'))) {
        if ($absolute -ieq $protected -or $absolute.StartsWith($protected + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup targets live data, runtime or dependencies' }
    }
    if ((Get-Item -LiteralPath $absolute -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Refusing cleanup through a filesystem link' }
    return $absolute
}

$installed = Get-Content -LiteralPath (Join-Path $projectRoot '.runtime\bin\installed.json') -Raw | ConvertFrom-Json
foreach ($program in $installed.programs) {
    if ((Get-FileHash -LiteralPath (Join-Path $projectRoot ('.runtime\bin\' + $program.name))).Hash -ne $program.sha256) { throw 'Installed runtime program differs' }
}
$protectedFiles = @('database-process.json', 'achievement-notification-routes.json')
foreach ($service in @('bot', 'linker', 'web')) {
    $record = Get-Content -LiteralPath (Join-Path $localRoot "$service-process.json") -Raw | ConvertFrom-Json
    $process = Get-Process -Id $record.pid
    if ($process.StartTime.ToUniversalTime().Ticks -ne [long]$record.startedTicks -or $process.Path -ine $record.executable) { throw 'Tracked runtime process changed' }
    if ($service -ne 'web' -and !$process.Path.StartsWith($projectRoot + '\.runtime\bin\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Services still depend on Cargo target' }
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
$active = @(Get-CimInstance Win32_Process)
foreach ($target in $targets) {
    foreach ($process in $active) {
        if ($process.ExecutablePath -and $process.ExecutablePath.StartsWith($target + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup would remove a running program' }
        if ($process.CommandLine -and $process.CommandLine.Replace('/', '\').IndexOf($target, [StringComparison]::OrdinalIgnoreCase) -ge 0) { throw "Active process references cleanup target: $target" }
    }
}
$bytes = 0L
foreach ($target in $targets) {
    $item = Get-Item -LiteralPath $target -Force
    if ($item.PSIsContainer) { $bytes += [long](Get-ChildItem -LiteralPath $target -File -Recurse -Force | Measure-Object Length -Sum).Sum }
    else { $bytes += $item.Length }
}
@{apply = [bool]$Apply; retainedDeployments = @($keep.Name); bytes = $bytes; targets = $targets} | ConvertTo-Json -Depth 4 |
    Set-Content -LiteralPath (Join-Path $evidenceRoot 'cleanup-plan.json') -Encoding utf8
Write-Output ("$($targets.Count) verified cleanup targets, {0:N2} GiB; keep $($keep.Name -join ', ')." -f ($bytes / 1GB))
if (!$Apply) { Write-Output 'Inspection only. Use -Apply to remove these verified targets.'; return }
foreach ($target in $targets) {
    $null = Assert-WorkspacePath $target
    Remove-Item -LiteralPath $target -Recurse -Force
}
@{completedAt = (Get-Date).ToUniversalTime().ToString('o'); bytesRemoved = $bytes; targetsRemoved = $targets.Count; retainedDeployments = @($keep.Name)} | ConvertTo-Json -Depth 4 |
    Set-Content -LiteralPath (Join-Path $evidenceRoot 'cleanup-result.json') -Encoding utf8
Write-Output 'Removed verified scratch/cache targets. Live data, installed programs, credentials, dependencies and two rollback sets retained.'
