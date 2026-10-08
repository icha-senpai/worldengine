param(
    [ValidateSet('Start', 'Stop', 'Restart')][string]$Action = 'Start',
    [ValidateSet('Main', 'Test')][string]$Target = 'Main'
)
$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$logs = Join-Path $project '.runtime\services'
$database = if ($Target -eq 'Test') { 'space-bitcraft-checks' } else { 'space-bitcraft-tools' }
$serviceName = if ($Target -eq 'Test') { 'collector-test' } else { 'collector' }
$pidFile = Join-Path $logs "$serviceName.pid"
$readyFile = Join-Path $logs "$serviceName.ready.json"
$entry = Join-Path $project 'scripts\bitcraft-collector.ts'
function Get-Collector {
    if (-not (Test-Path -LiteralPath $pidFile)) { return }
    $collectorId = [int](Get-Content -LiteralPath $pidFile -Raw).Trim()
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $collectorId"
    if (-not $process) { Remove-Item -LiteralPath $pidFile; return }
    if ($process.Name -ne 'node.exe' -or -not $process.CommandLine -or
        $process.CommandLine.IndexOf($entry, [StringComparison]::OrdinalIgnoreCase) -lt 0) {
        throw "Collector PID $collectorId belongs to another process. Leaving it alone."
    }
    if ($Target -eq 'Test' -and
        ($process.CommandLine -notmatch '--database\s+space-bitcraft-checks(?:\s|$)' -or
         $process.CommandLine -notmatch '--storage-only(?:\s|$)')) {
        throw "PID $collectorId is not the test storage guard. Leaving it alone."
    }
    if ($Target -eq 'Main' -and $process.CommandLine -match '--database\s+(?!space-bitcraft-tools(?:\s|$))') {
        throw "PID $collectorId is a worker for another database. Leaving it alone."
    }
    $process
}
try {
    $existing = Get-Collector
    if ($Action -in @('Stop', 'Restart') -and $existing) {
        Stop-Process -Id $existing.ProcessId
        Wait-Process -Id $existing.ProcessId -Timeout 15 -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $pidFile -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $readyFile -ErrorAction SilentlyContinue
        $existing = $null
    }
    if ($Action -in @('Start', 'Restart') -and -not $existing) {
        New-Item -ItemType Directory -Path $logs -Force | Out-Null
        $node = (& node -p 'process.execPath').Trim()
        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
        Remove-Item -LiteralPath $readyFile -ErrorAction SilentlyContinue
        $workerArgs = "--import tsx `"$entry`" --database $database"
        if ($Target -eq 'Test') { $workerArgs += ' --storage-only' } else { $workerArgs += ' --authorize' }
        $started = Start-Process -FilePath $node -ArgumentList $workerArgs -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput "$logs\$serviceName-$stamp.log" -RedirectStandardError "$logs\$serviceName-$stamp.err.log"
        Set-Content -LiteralPath $pidFile -Value $started.Id
        # Startup may perform a verified storage rebuild before authenticating.
        $deadline = (Get-Date).AddSeconds(90)
        $ready = $false
        while ((Get-Date) -lt $deadline) {
            if (-not (Get-Collector)) { throw 'Collector exited during startup. Check its service logs.' }
            if (Test-Path -LiteralPath $readyFile) {
                $state = Get-Content -LiteralPath $readyFile -Raw | ConvertFrom-Json
                if ($state.pid -eq $started.Id -and $state.database -eq $database) { $ready = $true; break }
            }
            Start-Sleep -Milliseconds 300
        }
        if (-not $ready) {
            $failed = Get-Collector
            if ($failed) { Stop-Process -Id $failed.ProcessId }
            Remove-Item -LiteralPath $pidFile -ErrorAction SilentlyContinue
            throw 'Collector did not authenticate during startup. Check its service logs.'
        }
    }
    Write-Host "BitCraft $Target worker: $Action complete."
} catch { Write-Host $_.Exception.Message -ForegroundColor Red; exit 1 }
