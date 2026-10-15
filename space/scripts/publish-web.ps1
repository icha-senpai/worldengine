param([switch]$UseExistingBuild)
$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $project
$runtime = [IO.Path]::GetFullPath((Join-Path $project '.runtime'))
$published = Join-Path $runtime 'published-web'
$stage = Join-Path $runtime 'publish-web-stage'
$previous = Join-Path $runtime 'publish-web-previous'
$publicEnv = Join-Path $runtime 'public-web.env'
$originalEnv = @{}
New-Item -ItemType Directory -Path $runtime -Force | Out-Null

function Assert-OwnedTree([string]$Path) {
    $resolved = [IO.Path]::GetFullPath($Path)
    if ($resolved -notin @($published, $stage, $previous)) { throw 'Unexpected publish directory.' }
    if ((Get-Item -LiteralPath $runtime).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked runtime directories are not allowed.' }
    if (Test-Path -LiteralPath $resolved) {
        foreach ($entry in @((Get-Item -LiteralPath $resolved)) + @(Get-ChildItem -LiteralPath $resolved -Recurse -Force)) {
            if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked publish paths are not allowed.' }
        }
    }
}
function Remove-PublishTree([string]$Path) {
    Assert-OwnedTree $Path
    if (Test-Path -LiteralPath $Path) { Remove-Item -LiteralPath $Path -Recurse -Force }
}
try {
    foreach ($path in @($published, $stage, $previous)) { Assert-OwnedTree $path }
    if (Test-Path -LiteralPath $previous) { throw 'An unfinished publish exists. Inspect publish-web-previous before continuing.' }
    if (!(Test-Path -LiteralPath $publicEnv)) { Copy-Item -LiteralPath (Join-Path $project '.env.local') -Destination $publicEnv }
    foreach ($line in Get-Content -LiteralPath $publicEnv) {
        if ($line -match '^(VITE_[A-Z_]+)=(.*)$') {
            $name = $Matches[1]
            $originalEnv[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
            [Environment]::SetEnvironmentVariable($name, $Matches[2].Trim().Trim('"').Trim("'"), 'Process')
        }
    }
    if (!$UseExistingBuild) {
        & npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Build failed; public files were not changed.' }
    }
    $source = Join-Path $project 'dist'
    if (!(Test-Path -LiteralPath (Join-Path $source 'index.html'))) { throw 'Compiled website is missing.' }
    Remove-PublishTree $stage
    Copy-Item -LiteralPath $source -Destination $stage -Recurse
    Assert-OwnedTree $stage
    if (!(Test-Path -LiteralPath (Join-Path $stage 'assets'))) { throw 'Compiled assets are missing.' }
    if (Test-Path -LiteralPath $published) { Move-Item -LiteralPath $published -Destination $previous }
    try { Move-Item -LiteralPath $stage -Destination $published } catch {
        if (Test-Path -LiteralPath $previous) { Move-Item -LiteralPath $previous -Destination $published }
        throw
    }
    Remove-PublishTree $previous
    Write-Host 'Space public website published. Development servers and databases were not changed.'
} catch { Write-Error $_; exit 1 } finally {
    foreach ($name in $originalEnv.Keys) { [Environment]::SetEnvironmentVariable($name, $originalEnv[$name], 'Process') }
}
