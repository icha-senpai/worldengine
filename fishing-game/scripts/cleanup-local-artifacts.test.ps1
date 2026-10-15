$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$localRoot = Join-Path $projectRoot '.local'
$tokens = $null
$parseErrors = $null
$syntax = [Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot 'cleanup-local-artifacts.ps1'), [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw 'Cleanup script has parse errors' }
foreach ($definition in $syntax.FindAll({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst]}, $false)) {
    . ([scriptblock]::Create($definition.Extent.Text))
}
function Expect-Rejection([scriptblock]$Action, [string]$Message) {
    $rejected = $false
    try { & $Action | Out-Null } catch { $rejected = $true }
    if (!$rejected) { throw $Message }
}
$fixture = Join-Path $localRoot ('cleanup-guard-proof-' + [guid]::NewGuid().ToString('N'))
$worker = $null
try {
    $nested = Join-Path $fixture 'nested'
    New-Item -ItemType Directory -Path $nested -Force | Out-Null
    $literal = Join-Path $nested 'literal [space] $name.txt'
    Set-Content -LiteralPath $literal -Value 'owned artifact'
    Expect-Rejection { Assert-WorkspacePath (Join-Path $projectRoot '.runtime') } 'Accepted protected runtime'
    Expect-Rejection { Assert-WorkspacePath (Join-Path $projectRoot '.runtime\bin') } 'Accepted installed programs'
    Expect-Rejection { Assert-WorkspacePath (Join-Path $projectRoot '.runtime\module') } 'Accepted deployed module'
    Expect-Rejection { Assert-WorkspacePath (Join-Path $projectRoot '.runtime\web-upgrade-dialog-extra') } 'Accepted an unlisted runtime path'
    Expect-Rejection { Assert-WorkspacePath (Join-Path $localRoot 'spacetimedb') } 'Accepted live database'
    Expect-Rejection { Assert-WorkspacePath (Join-Path $localRoot 'rust-artifacts') } 'Accepted installable Rust artifacts'
    Expect-Rejection { Assert-WorkspacePath (Join-Path $projectRoot '..\space') } 'Accepted sibling project'
    $entries = @(Get-VerifiedEntries $fixture)
    Set-Content -LiteralPath $literal -Value 'changed after review'
    Expect-Rejection { Remove-VerifiedEntries $fixture $entries } 'Deleted changed file'
    if (!(Test-Path -LiteralPath $literal)) { throw 'Changed file was removed' }
    $ownerMarker = Join-Path $fixture '.fishbound-rust-owner.json'
    @{root = $projectRoot; target = $fixture; pid = $PID} | ConvertTo-Json | Set-Content -LiteralPath $ownerMarker
    Expect-Rejection { Assert-InactiveTarget $fixture } 'Accepted a cache owned by an active Rust workflow'
    Remove-Item -LiteralPath $ownerMarker
    $script = Join-Path $fixture 'waiting.ps1'
    Set-Content -LiteralPath $script -Value 'Start-Sleep -Seconds 30'
    $worker = Start-Process -FilePath (Get-Process -Id $PID).Path -ArgumentList @('-NoProfile', '-File', ('"' + $script + '"')) -WindowStyle Hidden -PassThru
    Expect-Rejection { Assert-InactiveTarget $fixture } 'Accepted an active path'
    Stop-Process -Id $worker.Id
    $worker.WaitForExit()
    $worker = $null
    $entries = @(Get-VerifiedEntries $fixture)
    $unexpected = Join-Path $nested 'unreviewed.txt'
    Set-Content -LiteralPath $unexpected -Value 'must survive'
    Expect-Rejection { Remove-VerifiedEntries $fixture $entries } 'Removed an unreviewed entry'
    if (!(Test-Path -LiteralPath $unexpected)) { throw 'Unreviewed file was removed' }
    Remove-VerifiedEntries $fixture @(Get-VerifiedEntries $fixture)
    if (Test-Path -LiteralPath $fixture) { throw 'Reviewed literal-path cleanup failed' }
    Write-Output 'PASS protected paths, active paths, changed files, unreviewed entries and literal filenames.'
} finally {
    if ($worker -and !( $worker.HasExited )) { Stop-Process -Id $worker.Id; $worker.WaitForExit() }
    if (Test-Path -LiteralPath $fixture) { Remove-VerifiedEntries $fixture @(Get-VerifiedEntries $fixture) }
}
