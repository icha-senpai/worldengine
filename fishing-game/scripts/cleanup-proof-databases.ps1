param([switch]$Apply)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot
$server = 'http://127.0.0.1:3127'
$live = Invoke-RestMethod "$server/v1/database/fishbound-dev-local"
$liveIdentity = $live.database_identity.__identity__.Replace('0x', '')
if ($liveIdentity -ne 'c2002230cb292d622fb9716c64c8c73f84b1934e4eb6e6db546fb271ab32e488') { throw 'Unexpected live database identity' }
$listed = & spacetime list --server $server --no-config
if ($LASTEXITCODE -ne 0) { throw 'Database inventory failed' }
$proofs = @()
foreach ($line in $listed) {
    if ($line -match '^\s*(fishbound-proof-[a-zA-Z0-9-]+)\s*\|\s*([a-f0-9]{64})\s*$') {
        if ($Matches[2] -eq $liveIdentity) { throw 'Proof alias references the live database' }
        $proofs += @{name = $Matches[1]; identity = $Matches[2]}
    }
}
if (!$Apply) { Write-Output "$($proofs.Count) disposable Fishbound proof databases. Use -Apply to delete them."; return }
$evidence = Join-Path $projectRoot '.runtime\maintenance'
New-Item -ItemType Directory -Path $evidence -Force | Out-Null
$proofs | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $evidence 'proof-databases-deleted.json') -Encoding utf8
foreach ($proof in $proofs) {
    $info = Invoke-RestMethod "$server/v1/database/$($proof.name)"
    $identity = $info.database_identity.__identity__.Replace('0x', '')
    if ($identity -ne $proof.identity -or $identity -eq $liveIdentity) { throw 'Database ownership boundary changed' }
    & spacetime delete --server $server --no-config --yes $identity *> (Join-Path $evidence 'proof-delete-last.log')
    if ($LASTEXITCODE -ne 0) { throw "Failed deleting isolated proof: $($proof.name)" }
}
Write-Output "Removed $($proofs.Count) isolated proof databases; live database excluded by name and identity."
