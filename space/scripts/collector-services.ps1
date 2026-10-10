param(
    [ValidateSet('Start', 'Stop', 'Restart')][string]$Action = 'Start',
    [ValidateSet('Main', 'Test')][string]$Target = 'Main',
    [switch]$Supervise
)
$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$logs = Join-Path $project '.runtime\services'
$database = if ($Target -eq 'Test') { 'space-bitcraft-checks' } else { 'space-bitcraft-tools' }
$serviceName = if ($Target -eq 'Test') { 'collector-test' } else { 'collector' }
$pidFile = Join-Path $logs "$serviceName.pid"
$readyFile = Join-Path $logs "$serviceName.ready.json"
$entry = Join-Path $project 'scripts\bitcraft-collector.ts'
$supervisorPidFile = Join-Path $logs "$serviceName.supervisor.pid"
$supervisorLog = Join-Path $logs "$serviceName.supervisor.log"
$scriptPath = $PSCommandPath
$diagnostics = Join-Path $project ".runtime\diagnostics\$database"
function Get-Supervisor {
    if (-not (Test-Path -LiteralPath $supervisorPidFile)) { return }
    $supervisorId = [int](Get-Content -LiteralPath $supervisorPidFile -Raw).Trim()
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $supervisorId"
    if (-not $process) { Remove-Item -LiteralPath $supervisorPidFile; return }
    if ($process.Name -notin @('powershell.exe', 'pwsh.exe') -or -not $process.CommandLine -or
        $process.CommandLine.IndexOf($scriptPath, [StringComparison]::OrdinalIgnoreCase) -lt 0 -or
        $process.CommandLine -notmatch '-Supervise(?:\s|$)' -or
        $process.CommandLine -notmatch "-Target\s+$Target(?:\s|$)") {
        throw "Supervisor PID $supervisorId belongs to another process. Leaving it alone."
    }
    $process
}
function Write-SupervisorLog([string]$Message) {
    if ((Test-Path -LiteralPath $supervisorLog) -and (Get-Item -LiteralPath $supervisorLog).Length -gt 1MB) {
        $tail = @(Get-Content -LiteralPath $supervisorLog -Tail 500)
        Set-Content -LiteralPath $supervisorLog -Value $tail
    }
    Add-Content -LiteralPath $supervisorLog -Value "$(Get-Date -Format o) $Message"
}
function Start-Collector {
    $node = (& node -p 'process.execPath').Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Node.js is unavailable.' }
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
    Remove-Item -LiteralPath $readyFile -ErrorAction SilentlyContinue
    # Archive before pruning, including logs from failed startups.
    & $node --import tsx (Join-Path $project 'scripts\archive-collector-logs.ts') $database
    if ($LASTEXITCODE -ne 0) { throw 'Collector archive preparation failed; source logs preserved.' }
    New-Item -ItemType Directory -Path $diagnostics -Force | Out-Null
    $workerArgs = "--report-on-fatalerror --report-exclude-env --report-exclude-network --report-directory=`"$diagnostics`" --report-filename=fatal-report.json --import tsx `"$entry`" --database $database"
    if ($Target -eq 'Test') { $workerArgs += ' --storage-only' } else { $workerArgs += ' --authorize' }
    $started = Start-Process -FilePath $node -ArgumentList $workerArgs -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput "$logs\$serviceName-$stamp.log" -RedirectStandardError "$logs\$serviceName-$stamp.err.log"
    Set-Content -LiteralPath $pidFile -Value $started.Id
    Write-SupervisorLog "Worker started: PID $($started.Id); logs $serviceName-$stamp."
    $started
}
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
function Stop-Workers {
    # Validate both owners before stopping either. Stop supervision first.
    $supervisor = Get-Supervisor
    $null = Get-Collector
    if ($supervisor) {
        Stop-Process -Id $supervisor.ProcessId
        Wait-Process -Id $supervisor.ProcessId -Timeout 15 -ErrorAction SilentlyContinue
    }
    $existing = Get-Collector
    if ($existing) {
        Stop-Process -Id $existing.ProcessId
        Wait-Process -Id $existing.ProcessId -Timeout 15 -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $supervisorPidFile -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $pidFile -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $readyFile -ErrorAction SilentlyContinue
    Write-SupervisorLog 'Deliberate stop: supervisor and worker stopped.'
}
if ($Supervise) {
    $singleton = New-Object System.Threading.Mutex($false, "Local\SpaceBitcraftSupervisor-$Target")
    $held = $false
    try {
        try { $held = $singleton.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $held = $true }
        if (-not $held) { throw 'A supervisor is already running for this target.' }
        New-Item -ItemType Directory -Path $logs -Force | Out-Null
        Set-Content -LiteralPath $supervisorPidFile -Value $PID
        Write-SupervisorLog "Supervisor started: PID $PID; target $Target."
        $delay = 5
        while ($true) {
            $existing = Get-Collector
            if ($existing) {
                $worker = Get-Process -Id $existing.ProcessId -ErrorAction Stop
                Write-SupervisorLog "Watching worker PID $($worker.Id)."
            } else {
                # Never attempt automatic recovery of an incomplete rebuild.
                $bindingPath = Join-Path $project ".runtime\bitcraft-storage\$database.json"
                $binding = Get-Content -LiteralPath $bindingPath -Raw | ConvertFrom-Json
                if ($binding.database -ne $database) { throw 'Storage binding database mismatch.' }
                if ($binding.pending) {
                    Write-SupervisorLog 'Incomplete storage rebuild: worker remains stopped; manual recovery required.'
                    Start-Sleep -Seconds 60
                    continue
                }
                try { $worker = Start-Collector }
                catch {
                    Write-SupervisorLog "Worker launch failed: $($_.Exception.Message); retry in $delay seconds."
                    Start-Sleep -Seconds $delay
                    $delay = [Math]::Min(60, $delay * 2)
                    continue
                }
            }
            # Capture the process handle before exit, including adopted workers.
            $null = $worker.Handle
            $startedAt = Get-Date
            while (-not $worker.WaitForExit(1000)) { }
            $worker.Refresh()
            $exitCode = $worker.ExitCode
            Write-SupervisorLog "Worker exit observed: PID $($worker.Id), code $exitCode. Saving diagnostics."
            # Snapshot belongs to the exiting worker. Archive before its replacement overwrites latest.json.
            try {
                $lastPath = Join-Path $diagnostics 'latest.json'
                $lastSample = $null
                if (Test-Path -LiteralPath $lastPath) {
                    $candidate = Get-Content -LiteralPath $lastPath -Raw | ConvertFrom-Json
                    if ($candidate.pid -eq $worker.Id) { $lastSample = $candidate }
                }
                New-Item -ItemType Directory -Path $diagnostics -Force | Out-Null
                Remove-Item -LiteralPath (Join-Path $diagnostics 'exit-2.json') -ErrorAction SilentlyContinue
                if (Test-Path -LiteralPath (Join-Path $diagnostics 'exit-1.json')) { Move-Item -LiteralPath (Join-Path $diagnostics 'exit-1.json') -Destination (Join-Path $diagnostics 'exit-2.json') }
                if (Test-Path -LiteralPath (Join-Path $diagnostics 'exit.json')) { Move-Item -LiteralPath (Join-Path $diagnostics 'exit.json') -Destination (Join-Path $diagnostics 'exit-1.json') }
                $unsignedCode = [uint32]([long]$exitCode -band 4294967295)
                $recentEvents = @()
                $eventLog = Join-Path $diagnostics 'events.jsonl'
                # Read plain strings: Get-Content adds PowerShell file metadata
                # that ConvertTo-Json can expand recursively into enormous output.
                if (Test-Path -LiteralPath $eventLog) { $recentEvents = @([System.IO.File]::ReadAllLines($eventLog) | Select-Object -Last 20) }
                @{ at = (Get-Date -Format o); pid = $worker.Id; exitCode = $exitCode; exitCodeHex = ('0x{0:X8}' -f $unsignedCode); observedRunSeconds = [Math]::Round(((Get-Date) - $startedAt).TotalSeconds); lastSample = $lastSample; recentEvents = $recentEvents } | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $diagnostics 'exit.json')
            } catch { Write-SupervisorLog 'Could not save exit diagnostic; restart will continue.' }
            Remove-Item -LiteralPath $readyFile -ErrorAction SilentlyContinue
            Remove-Item -LiteralPath $pidFile -ErrorAction SilentlyContinue
            if (((Get-Date) - $startedAt).TotalSeconds -ge 120) { $delay = 5 }
            Write-SupervisorLog "Worker PID $($worker.Id) exited with code $exitCode; retry in $delay seconds. Storage checks run before writes reopen."
            Start-Sleep -Seconds $delay
            $delay = [Math]::Min(60, $delay * 2)
        }
    } catch {
        Write-SupervisorLog "Supervisor stopped: $($_.Exception.Message)"
        exit 1
    } finally {
        if ($held) { $singleton.ReleaseMutex() }
        $singleton.Dispose()
    }
    exit
}
$commandLock = New-Object System.Threading.Mutex($false, "Local\SpaceBitcraftServiceCommand-$Target")
$commandHeld = $false
try {
    try { $commandHeld = $commandLock.WaitOne(100000) } catch [System.Threading.AbandonedMutexException] { $commandHeld = $true }
    if (-not $commandHeld) { throw 'Another service command is still running.' }
    New-Item -ItemType Directory -Path $logs -Force | Out-Null
    if ($Action -in @('Stop', 'Restart')) { Stop-Workers }
    if ($Action -in @('Start', 'Restart')) {
        $existing = Get-Collector
        $supervisor = Get-Supervisor
        if (-not $supervisor) {
            # Adopt an existing owned worker without duplicating collection.
            $supervisorArgs = "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" -Target $Target -Supervise"
            Start-Process -FilePath powershell.exe -ArgumentList $supervisorArgs -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput "$logs\$serviceName-supervisor-start.log" -RedirectStandardError "$logs\$serviceName-supervisor-start.err.log" | Out-Null
        }
        # Startup may perform a verified storage rebuild before authenticating.
        $deadline = (Get-Date).AddSeconds(90)
        $ready = $false
        while ((Get-Date) -lt $deadline) {
            $worker = Get-Collector
            $supervisor = Get-Supervisor
            if ($worker -and $supervisor -and (Test-Path -LiteralPath $readyFile)) {
                $state = Get-Content -LiteralPath $readyFile -Raw | ConvertFrom-Json
                if ($state.pid -eq $worker.ProcessId -and $state.database -eq $database) { $ready = $true; break }
            }
            Start-Sleep -Milliseconds 300
        }
        if (-not $ready) {
            Stop-Workers
            throw 'Collector did not authenticate during startup. Check its service logs.'
        }
    }
    Write-Host "BitCraft $Target worker: $Action complete."
} catch { Write-Host $_.Exception.Message -ForegroundColor Red; exit 1 }
finally {
    if ($commandHeld) { $commandLock.ReleaseMutex() }
    $commandLock.Dispose()
}
