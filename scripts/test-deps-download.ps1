param(
  [string]$TestDir = "C:\Trading\test-deps",
  [switch]$Cleanup = $false
)

$ErrorActionPreference = "Stop"

Write-Host "=== Test: Download and Unzip GitHub Dependencies ===" -ForegroundColor Cyan
Write-Host "Target: $TestDir" -ForegroundColor Yellow

if ($Cleanup -and (Test-Path $TestDir)) {
  Write-Host "Cleaning up $TestDir..." -ForegroundColor Yellow
  Remove-Item $TestDir -Recurse -Force
}

if (-not (Test-Path $TestDir)) {
  New-Item -ItemType Directory -Path $TestDir -Force | Out-Null
  Write-Host "Created $TestDir" -ForegroundColor Green
}

function Get-GitHubZip([string]$Url, [string]$DestDir) {
  $tmpZip = Join-Path $env:TEMP (("gh_dep_" + [System.IO.Path]::GetRandomFileName() + ".zip"))
  Write-Host "  • Downloading $Url" -ForegroundColor Cyan
  Invoke-WebRequest -Uri $Url -OutFile $tmpZip -UseBasicParsing
  Write-Host "    ✓ Downloaded $(((Get-Item $tmpZip).Length / 1MB).ToString('F1')) MB" -ForegroundColor Green
  Write-Host "  • Extracting to $DestDir" -ForegroundColor Cyan
  Expand-Archive -Path $tmpZip -DestinationPath $DestDir -Force
  Write-Host "    ✓ Extracted" -ForegroundColor Green
  Remove-Item $tmpZip -Force
}

function Find-Or-Fetch-SiblingRepo([string]$RepoRoot, [string[]]$Names, [string]$GitHubZipUrl, [string]$LogName) {
  $parent = Split-Path $RepoRoot -Parent
  foreach ($name in $Names) {
    $candidate = Join-Path $parent $name
    if (Test-Path $candidate) {
      Write-Host "  • $LogName already exists at $candidate" -ForegroundColor Green
      return $candidate
    }
  }
  Write-Host "  • $LogName not found, downloading..." -ForegroundColor Yellow
  Get-GitHubZip -Url $GitHubZipUrl -DestDir $parent
  foreach ($name in $Names) {
    $candidate = Join-Path $parent $name
    if (Test-Path $candidate) {
      Write-Host "  • Found $LogName at $candidate" -ForegroundColor Green
      return $candidate
    }
  }
  throw "Failed to locate $LogName after download"
}

try {
  Write-Host "`n1. Testing alpet-libs-php download..." -ForegroundColor Cyan
  $libs = Find-Or-Fetch-SiblingRepo $TestDir `
    @("alpet-libs-php", "alpet-libs-php-main", "alpet-libs-php-master") `
    "https://github.com/alpet83/alpet-libs-php/archive/refs/heads/main.zip" `
    "alpet-libs-php"

  Write-Host "`n2. Testing datafeed download..." -ForegroundColor Cyan
  $datafeed = Find-Or-Fetch-SiblingRepo $TestDir `
    @("datafeed", "datafeed-main", "datafeed-master") `
    "https://github.com/alpet83/datafeed/archive/refs/heads/main.zip" `
    "datafeed"

  Write-Host "`n✓ SUCCESS: All dependencies downloaded and available" -ForegroundColor Green
  Write-Host "`nContents of $TestDir`:" -ForegroundColor Cyan
  Get-ChildItem $TestDir -Directory | ForEach-Object {
    Write-Host "  • $($_.Name)  ($((Get-ChildItem $_.FullName -Recurse | Measure-Object -Sum Length).Sum / 1MB).ToString('F1')) MB"
  }

} catch {
  Write-Host "`n✗ ERROR: $($_.Exception.Message)" -ForegroundColor Red
  exit 1
}
