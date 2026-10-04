param([ValidateSet('Start', 'Stop', 'Restart')][string]$Action = 'Start')

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$data = Join-Path $project '.runtime\data'
$logs = Join-Path $project '.runtime\services'

function Get-ServiceProcess([int]$Port, [string]$Kind) {
    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    $owners = @($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
    foreach ($ownerId in $owners) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $ownerId"
        $command = $process.CommandLine
        $owned = $false
        if ($command) {
            if ($Kind -eq 'database') {
                $owned = $process.Name -eq 'spacetimedb-standalone.exe' -and
                    $command.IndexOf($data, [StringComparison]::OrdinalIgnoreCase) -ge 0
            } else {
                $owned = $process.Name -eq 'node.exe' -and
                    $command.IndexOf($project, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and
                    $command -match 'vite' -and
                    ($Port -eq 5180 -or $command -match 'playtest')
            }
        }
        if (-not $owned) { throw "Port $Port belongs to another process ($ownerId). Leaving it alone." }
        $process
    }
}

function Wait-Service([int]$Port, [string]$Kind, [string]$Url) {
    $deadline = (Get-Date).AddSeconds(30)
    while ((Get-Date) -lt $deadline) {
        $process = @(Get-ServiceProcess $Port $Kind)
        if ($process.Count) {
            try {
                $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 2
                if ($response.StatusCode -eq 200) { return }
            } catch { }
        }
        Start-Sleep -Milliseconds 300
    }
    throw "$Kind did not become ready on port $Port. See logs in $logs."
}

function Stop-Services {
    # Validate both ports before stopping either service.
    $frontend = @(Get-ServiceProcess 5181 'frontend')
    $mainFrontend = @(Get-ServiceProcess 5180 'frontend')
    $database = @(Get-ServiceProcess 3100 'database')
    foreach ($process in @($frontend) + @($mainFrontend) + @($database)) {
        Stop-Process -Id $process.ProcessId -ErrorAction Stop
        Wait-Process -Id $process.ProcessId -Timeout 15 -ErrorAction SilentlyContinue
    }
    Write-Host 'Space database and both frontend servers stopped. Database files preserved.'
}

function Start-Services {
    $database = @(Get-ServiceProcess 3100 'database')
    $frontend = @(Get-ServiceProcess 5181 'frontend')
    $mainFrontend = @(Get-ServiceProcess 5180 'frontend')
    $cli = Join-Path $env:LOCALAPPDATA 'SpacetimeDB\bin\current\spacetimedb-cli.exe'
    $vite = Join-Path $project 'node_modules\vite\bin\vite.js'
    $node = (& node -p 'process.execPath').Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Node.js is unavailable.' }
    if (-not (Test-Path $cli)) { throw "SpaceTimeDB CLI missing: $cli" }
    if (-not (Test-Path $vite)) { throw 'Run npm install in the space folder first.' }
    if (-not (Test-Path (Join-Path $project '.env.playtest.local'))) {
        throw 'Missing .env.playtest.local. Configure the test databases before starting.'
    }
    New-Item -ItemType Directory -Path $logs -Force | Out-Null
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
    if (-not $database.Count) {
        Start-Process -FilePath $cli -ArgumentList "start --listen-addr 127.0.0.1:3100 --data-dir `"$data`" --non-interactive" -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput "$logs\database-$stamp.log" -RedirectStandardError "$logs\database-$stamp.err.log" | Out-Null
    }
    Wait-Service 3100 'database' 'http://127.0.0.1:3100/v1/ping'
    if (-not $mainFrontend.Count) {
        Start-Process -FilePath $node -ArgumentList "`"$vite`" --host 127.0.0.1 --port 5180 --strictPort" -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput "$logs\main-frontend-$stamp.log" -RedirectStandardError "$logs\main-frontend-$stamp.err.log" | Out-Null
    }
    Wait-Service 5180 'frontend' 'http://127.0.0.1:5180/'
    if (-not $frontend.Count) {
        Start-Process -FilePath $node -ArgumentList "`"$vite`" --mode playtest --host 127.0.0.1 --port 5181 --strictPort" -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput "$logs\frontend-$stamp.log" -RedirectStandardError "$logs\frontend-$stamp.err.log" | Out-Null
    }
    Wait-Service 5181 'frontend' 'http://127.0.0.1:5181/'
    Write-Host 'Space database ready: http://127.0.0.1:3100'
    Write-Host 'Main app ready: https://space.test/evergather (http://127.0.0.1:5180/evergather)'
    Write-Host 'Test server ready: http://127.0.0.1:5181/evergather'
    Write-Host "Logs: $logs"
}

try {
    # Process inspection must work before we can safely identify existing services.
    Get-CimInstance Win32_Process -Filter "ProcessId = $PID" -ErrorAction Stop | Out-Null
    if ($Action -in @('Stop', 'Restart')) { Stop-Services }
    if ($Action -in @('Start', 'Restart')) { Start-Services }
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
