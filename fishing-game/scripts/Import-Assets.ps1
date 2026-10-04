param(
    [string]$FishArchive = (Join-Path $env:USERPROFILE 'Downloads\fish-sprites.zip'),
    [string]$RankArchive = (Join-Path $env:USERPROFILE 'Downloads\rank_cards_no_fish_no_textbox.zip')
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$projectRoot = Split-Path $PSScriptRoot -Parent

function Import-PngArchive([string]$ArchivePath, [string]$Folder, [string]$Prefix) {
    $destination = [System.IO.Path]::GetFullPath((Join-Path $projectRoot "assets\$Folder"))
    [System.IO.Directory]::CreateDirectory($destination) | Out-Null
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ArchivePath)
    $count = 0
    try {
        foreach ($entry in $archive.Entries) {
            if (-not $entry.Name) { continue }
            $name = $entry.FullName.Replace('\', '/')
            if ($Prefix -and $name.StartsWith($Prefix)) { $name = $name.Substring($Prefix.Length) }
            if ($name.Contains('/') -or $name.Contains(':') -or $name.Contains('..') -or -not $name.EndsWith('.png', [StringComparison]::OrdinalIgnoreCase)) {
                throw "Unexpected archive entry: $($entry.FullName)"
            }
            $target = [System.IO.Path]::GetFullPath((Join-Path $destination $name))
            if (-not $target.StartsWith($destination + [System.IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
                throw "Archive entry leaves asset destination: $name"
            }
            [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $target, $true)
            $count++
        }
    } finally { $archive.Dispose() }
    Write-Output "Imported $count images into assets/$Folder"
}
Import-PngArchive $FishArchive 'fish' 'fishing/'
Import-PngArchive $RankArchive 'rank-cards' ''
node (Join-Path $PSScriptRoot 'asset-manifest.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Asset manifest generation failed' }
