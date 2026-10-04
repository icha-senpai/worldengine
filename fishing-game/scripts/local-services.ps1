param([ValidateSet('Start', 'Close', 'Restart')][string]$Action = 'Start')

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$stateRoot = Join-Path $projectRoot '.local'
Set-Location -LiteralPath $projectRoot
New-Item -ItemType Directory -Path $stateRoot -Force | Out-Null

function Read-TrackedProcess([string]$Name) {
    $recordPath = Join-Path $stateRoot "$Name-process.json"
    if (!(Test-Path -LiteralPath $recordPath)) { return $null }
    $record = Get-Content -LiteralPath $recordPath -Raw | ConvertFrom-Json
    $trackedProcess = Get-Process -Id $record.pid -ErrorAction SilentlyContinue
    if ($trackedProcess -and
        $trackedProcess.StartTime.ToUniversalTime().Ticks -eq [long]$record.startedTicks -and
        $trackedProcess.Path -ieq $record.executable) {
        return $trackedProcess
    }
    return $null
}

function Test-LocalPort([int]$Port) {
    $socket = New-Object System.Net.Sockets.TcpClient
    try {
        $pendingConnect = $socket.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (!$pendingConnect.AsyncWaitHandle.WaitOne(500)) { return $false }
        $socket.EndConnect($pendingConnect)
        return $true
    } catch { return $false } finally { $socket.Dispose() }
}

function Start-TrackedProcess([string]$Name, [string]$Executable, [string[]]$Arguments, [string]$Directory) {
    $existing = Read-TrackedProcess $Name
    if ($existing) { Write-Host "$Name already running (PID $($existing.Id))."; return }
    if (!(Test-Path -LiteralPath $Executable)) { throw "Missing $Executable. Build the game services first." }
    $processOptions = @{
        FilePath = $Executable
        WorkingDirectory = $Directory
        WindowStyle = 'Hidden'
        RedirectStandardOutput = Join-Path $stateRoot "$Name.stdout.log"
        RedirectStandardError = Join-Path $stateRoot "$Name.stderr.log"
        PassThru = $true
    }
    if ($Arguments.Count) { $processOptions.ArgumentList = $Arguments }
    $newProcess = Start-Process @processOptions
    @{ pid = $newProcess.Id; startedTicks = $newProcess.StartTime.ToUniversalTime().Ticks; executable = $Executable } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $stateRoot "$Name-process.json")
    Write-Host "Started $Name (PID $($newProcess.Id))."
}

function Close-GameServices {
    foreach ($serviceName in @('bot', 'linker', 'web')) {
        $trackedProcess = Read-TrackedProcess $serviceName
        if ($trackedProcess) {
            Stop-Process -Id $trackedProcess.Id
            Write-Host "Closed $serviceName."
        }
        $recordPath = Join-Path $stateRoot "$serviceName-process.json"
        if (Test-Path -LiteralPath $recordPath) { Remove-Item -LiteralPath $recordPath }
    }
    Write-Host 'ServBay, its shared tunnel, and the local database remain running.'
}

function Wait-ForService([string]$Name, [string]$Url) {
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (!(Read-TrackedProcess $Name)) { throw "$Name exited. Check .local/$Name.stderr.log." }
        try {
            $result = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 2
            if ($result.StatusCode -eq 200) { return }
        } catch { }
        Start-Sleep -Milliseconds 500
    }
    throw "$Name did not become ready. Check .local/$Name.stderr.log."
}

function Start-GameServices {
    $rootEnv = Join-Path $projectRoot '.env'
    $webEnv = Join-Path $projectRoot 'apps/web/.env'
    if (!(Test-Path -LiteralPath $rootEnv) -or !(Test-Path -LiteralPath $webEnv)) {
        throw 'Configure the root and website .env files first.'
    }
    $config = @{}
    foreach ($line in Get-Content -LiteralPath $rootEnv) {
        if ($line -match '^([A-Z_]+)=(.*)$') { $config[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
    }
    if ($config.SPACETIMEDB_URI -ne 'http://127.0.0.1:3127') {
        throw 'These local launchers expect the isolated database on 127.0.0.1:3127.'
    }
    if (!(Test-LocalPort 3127)) {
        $databaseExecutable = Join-Path $env:LOCALAPPDATA 'SpacetimeDB/spacetime.exe'
        Start-TrackedProcess 'database' $databaseExecutable @('start', '--listen-addr', '127.0.0.1:3127', '--data-dir', '.local/spacetimedb', '--non-interactive') $projectRoot
        for ($attempt = 0; $attempt -lt 30 -and !(Test-LocalPort 3127); $attempt++) { Start-Sleep -Milliseconds 500 }
        if (!(Test-LocalPort 3127)) { throw 'Database startup failed. Check .local/database.stderr.log.' }
    }
    $nodeExecutable = 'C:\ServBay\packages\node\current\node.exe'
    $webDirectory = Join-Path $projectRoot 'apps/web'
    if (!(Test-Path -LiteralPath (Join-Path $webDirectory 'build/index.js'))) { throw 'Run npm run build first.' }
    if ((Test-LocalPort 5173) -and !(Read-TrackedProcess 'web')) { throw 'Port 5173 belongs to an untracked process; it was left alone.' }
    Start-TrackedProcess 'web' $nodeExecutable @('--env-file=.env', 'build/index.js') $webDirectory
    Wait-ForService 'web' 'http://127.0.0.1:5173/'

    if ($config.DISCORD_CLIENT_ID -and $config.DISCORD_CLIENT_SECRET) {
        if ((Test-LocalPort 3001) -and !(Read-TrackedProcess 'linker')) { throw 'Port 3001 belongs to an untracked process; it was left alone.' }
        Start-TrackedProcess 'linker' (Join-Path $projectRoot 'target/debug/account-link.exe') @() $projectRoot
        Wait-ForService 'linker' 'http://127.0.0.1:3001/health/ready'
    } else { Write-Host 'Account linker waits for Discord client ID and secret in .env.' }
    if ($config.DISCORD_BOT_TOKEN) {
        Start-TrackedProcess 'bot' (Join-Path $projectRoot 'target/debug/discord-bot.exe') @() $projectRoot
        Start-Sleep -Seconds 2
        if (!(Read-TrackedProcess 'bot')) { throw 'Bot exited. Check .local/bot.stderr.log.' }
    } else { Write-Host 'Discord bot waits for its token in .env.' }
    Write-Host "Website: $($config.WEBSITE_URL)"
    Write-Host 'ServBay manages the Fishbound site and cloudflared tunnel.'
}

if ($Action -eq 'Close' -or $Action -eq 'Restart') { Close-GameServices }
if ($Action -eq 'Start' -or $Action -eq 'Restart') { Start-GameServices }
