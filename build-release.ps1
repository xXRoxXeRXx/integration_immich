# Build script for Nextcloud integration_immich app
# Creates a release package ready for installation

Write-Host "Building integration_immich release package..." -ForegroundColor Cyan

# Clean previous builds
Write-Host "Cleaning previous builds..." -ForegroundColor Yellow
if (Test-Path "build/release") {
    Remove-Item -Recurse -Force "build/release/*"
}
New-Item -ItemType Directory -Force -Path "build/release" | Out-Null

# Build frontend
Write-Host "Building frontend..." -ForegroundColor Yellow
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Host "Frontend build failed!" -ForegroundColor Red
    exit 1
}

# Create temp directory for packaging
$tempDir = "build/release/integration_immich"
Write-Host "Creating package structure..." -ForegroundColor Yellow
New-Item -ItemType Directory -Force -Path $tempDir | Out-Null

# Copy necessary files
Write-Host "Copying files..." -ForegroundColor Yellow

# Core directories
Copy-Item -Recurse "appinfo" "$tempDir/appinfo"
Copy-Item -Recurse "lib" "$tempDir/lib"
Copy-Item -Recurse "templates" "$tempDir/templates"
Copy-Item -Recurse "l10n" "$tempDir/l10n"
Copy-Item -Recurse "img" "$tempDir/img"
Copy-Item -Recurse "css" "$tempDir/css"
Copy-Item -Recurse "js" "$tempDir/js"

# Vendor directory (Composer dependencies)
if (Test-Path "vendor") {
    Copy-Item -Recurse "vendor" "$tempDir/vendor"
}
if (Test-Path "composer") {
    Copy-Item -Recurse "composer" "$tempDir/composer"
}

# Root files
Copy-Item "COPYING" "$tempDir/COPYING"
Copy-Item "README.md" "$tempDir/README.md"
Copy-Item "CHANGELOG.md" "$tempDir/CHANGELOG.md"

# Remove source maps and development files from js directory
Write-Host "Removing development files..." -ForegroundColor Yellow
Get-ChildItem -Path "$tempDir/js" -Filter "*.map" -Recurse | Remove-Item -Force

# Get version from appinfo/info.xml
[xml]$infoXml = Get-Content "appinfo/info.xml"
$version = $infoXml.info.version
$appId = $infoXml.info.id

Write-Host "Package: $appId v$version" -ForegroundColor Green

# Create tar.gz archive
$archiveName = "${appId}-${version}.tar.gz"
$archivePath = "build/release/$archiveName"

Write-Host "Creating archive: $archiveName..." -ForegroundColor Yellow

# Use tar command (available in Windows 10+)
Push-Location "build/release"
tar -czf $archiveName "integration_immich"
Pop-Location

if (Test-Path $archivePath) {
    $size = (Get-Item $archivePath).Length / 1MB
    Write-Host "✓ Release package created successfully!" -ForegroundColor Green
    Write-Host "  File: $archivePath" -ForegroundColor Cyan
    Write-Host "  Size: $([math]::Round($size, 2)) MB" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "To install:" -ForegroundColor Yellow
    Write-Host "  1. Upload to Nextcloud Apps folder or" -ForegroundColor White
    Write-Host "  2. Extract to nextcloud/apps/ directory" -ForegroundColor White
} else {
    Write-Host "✗ Failed to create archive!" -ForegroundColor Red
    exit 1
}

# Optionally create a source archive without node_modules
Write-Host ""
Write-Host "Creating source archive..." -ForegroundColor Yellow
$sourceArchiveName = "${appId}-${version}-source.tar.gz"

Push-Location ".."
tar -czf "integration_immich/build/release/$sourceArchiveName" `
    --exclude="integration_immich/node_modules" `
    --exclude="integration_immich/build" `
    --exclude="integration_immich/.git" `
    --exclude="integration_immich/.github" `
    --exclude="integration_immich/vendor" `
    "integration_immich"
Pop-Location

if (Test-Path "build/release/$sourceArchiveName") {
    $sourceSize = (Get-Item "build/release/$sourceArchiveName").Length / 1MB
    Write-Host "✓ Source archive created!" -ForegroundColor Green
    Write-Host "  File: build/release/$sourceArchiveName" -ForegroundColor Cyan
    Write-Host "  Size: $([math]::Round($sourceSize, 2)) MB" -ForegroundColor Cyan
}

Write-Host ""
Write-Host "Build complete! 🎉" -ForegroundColor Green
