#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Build and package the integration_immich Nextcloud app for release.

.DESCRIPTION
    Runs npm build, installs PHP production dependencies via Composer,
    then assembles a release ZIP ready for the Nextcloud App Store.

.PARAMETER Target
    all      - Build JS only (default)
    release  - Build JS + create release ZIP
    clean    - Remove build/ directory

.EXAMPLE
    .\Make.ps1
    .\Make.ps1 release
    .\Make.ps1 clean
#>

param(
    [ValidateSet('all', 'release', 'clean', 'zip-only')]
    [string]$Target = 'all'
)

$ErrorActionPreference = 'Stop'
$AppName   = 'integration_immich'
$BuildDir  = Join-Path $PSScriptRoot 'build'
$SourceDir = Join-Path $BuildDir "source\$AppName"
$ReleaseDir = Join-Path $BuildDir 'release'

$AppFiles = @(
    'appinfo', 'composer', 'css', 'img', 'js', 'l10n', 'lib',
    'templates',
    'CHANGELOG.md', 'COPYING', 'README.md'
)

function Invoke-Build {
    Write-Host "`n--- Installing JS dependencies ---" -ForegroundColor Cyan
    npm ci
    if ($LASTEXITCODE -ne 0) { throw "npm ci failed" }

    Write-Host "`n--- Building production JS bundles ---" -ForegroundColor Cyan
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "npm run build failed" }
}

function Invoke-Composer {
    Write-Host "`n--- Installing PHP dependencies (production) ---" -ForegroundColor Cyan
    composer install --no-dev --prefer-dist --optimize-autoloader
    if ($LASTEXITCODE -ne 0) { throw "composer install failed" }
}

function Invoke-Release {
    Invoke-Build
    Invoke-ZipOnly
}

function Invoke-ZipOnly {
    Write-Host "`n--- Assembling release package ---" -ForegroundColor Cyan

    if (Test-Path $SourceDir) { Remove-Item $SourceDir -Recurse -Force }
    New-Item $SourceDir -ItemType Directory -Force | Out-Null
    New-Item $ReleaseDir -ItemType Directory -Force | Out-Null

    foreach ($item in $AppFiles) {
        $src = Join-Path $PSScriptRoot $item
        if (Test-Path $src) {
            Copy-Item $src (Join-Path $SourceDir $item) -Recurse -Force
        } else {
            Write-Warning "Skipping missing item: $item"
        }
    }

    # Remove source maps (not needed in production release)
    Get-ChildItem (Join-Path $SourceDir 'js') -Filter '*.map' | Remove-Item -Force

    # The Nextcloud App Store requires tar.gz archives (not zip).
    # We use WSL / tar.exe to produce a proper Unix tar.gz with correct permissions.
    $tarPath = Join-Path $ReleaseDir "$AppName.tar.gz"
    if (Test-Path $tarPath) { Remove-Item $tarPath -Force }

    $sourceBase = Join-Path $BuildDir 'source'
    # tar.exe (built into Windows 10+) can create tar.gz archives.
    # We set the working directory so the archive contains integration_immich/ at the top level.
    Push-Location $sourceBase
    tar -czf $tarPath $AppName
    Pop-Location

    if ($LASTEXITCODE -ne 0) { throw "tar failed" }

    Write-Host "`n✅  Release tar.gz: $tarPath" -ForegroundColor Green
    Write-Host "    Size: $([math]::Round((Get-Item $tarPath).Length / 1MB, 2)) MB" -ForegroundColor Green
}

function Invoke-Clean {
    Write-Host "`n--- Cleaning build directory ---" -ForegroundColor Cyan
    if (Test-Path $BuildDir) { Remove-Item $BuildDir -Recurse -Force }
    Write-Host "✅  Done" -ForegroundColor Green
}

switch ($Target) {
    'all'      { Invoke-Build }
    'release'  { Invoke-Release }
    'zip-only' { Invoke-ZipOnly }
    'clean'    { Invoke-Clean }
}
