param(
  [string]$RepoRoot = "",
  [switch]$CleanupExisting = $false
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($RepoRoot)) {
  $RepoRoot = (Resolve-Path "$PSScriptRoot\..").Path
}

$depsRoot = Split-Path $RepoRoot -Parent

Write-Host "#INFO: dependency check started" -ForegroundColor Cyan
Write-Host "#INFO: RepoRoot=$RepoRoot" -ForegroundColor Yellow
Write-Host "#INFO: DepsRoot=$depsRoot" -ForegroundColor Yellow

function Get-GitHubZip([string]$Url, [string]$DestDir) {
  $tmpZip = Join-Path $env:TEMP ("gh_dep_" + [System.IO.Path]::GetRandomFileName() + ".zip")
  Write-Host "#INFO: downloading $Url" -ForegroundColor Cyan
  Invoke-WebRequest -Uri $Url -OutFile $tmpZip -UseBasicParsing
  Write-Host "#INFO: downloaded $(((Get-Item $tmpZip).Length / 1MB).ToString('F1')) MB" -ForegroundColor Green
  Write-Host "#INFO: extracting to $DestDir" -ForegroundColor Cyan
  Expand-Archive -Path $tmpZip -DestinationPath $DestDir -Force
  Remove-Item $tmpZip -Force
}

function Find-Or-Fetch-SiblingRepo([string]$CurrentRepoRoot, [string[]]$Names, [string]$GitHubZipUrl, [string]$LogName) {
  $parent = Split-Path $CurrentRepoRoot -Parent

  if ($CleanupExisting) {
    foreach ($name in $Names) {
      $candidate = Join-Path $parent $name
      if (Test-Path $candidate) {
        Write-Host "#INFO: cleanup existing $candidate" -ForegroundColor Yellow
        Remove-Item $candidate -Recurse -Force
      }
    }
  }

  foreach ($name in $Names) {
    $candidate = Join-Path $parent $name
    if (Test-Path $candidate) {
      Write-Host "#INFO: $LogName found at $candidate" -ForegroundColor Green
      return $candidate
    }
  }

  Write-Host "#INFO: $LogName not found, downloading" -ForegroundColor Yellow
  Get-GitHubZip -Url $GitHubZipUrl -DestDir $parent

  foreach ($name in $Names) {
    $candidate = Join-Path $parent $name
    if (Test-Path $candidate) {
      Write-Host "#INFO: $LogName ready at $candidate" -ForegroundColor Green
      return $candidate
    }
  }

  throw "Failed to locate $LogName after download"
}

try {
  $libs = Find-Or-Fetch-SiblingRepo $RepoRoot `
    @("alpet-libs-php", "alpet-libs-php-main", "alpet-libs-php-master") `
    "https://github.com/alpet83/alpet-libs-php/archive/refs/heads/main.zip" `
    "alpet-libs-php"

  $datafeed = Find-Or-Fetch-SiblingRepo $RepoRoot `
    @("datafeed", "datafeed-main", "datafeed-master") `
    "https://github.com/alpet83/datafeed/archive/refs/heads/main.zip" `
    "datafeed"

  Write-Host "#INFO: dependency check passed" -ForegroundColor Green
  Write-Host "#INFO: alpet-libs-php path: $libs" -ForegroundColor Green
  Write-Host "#INFO: datafeed path: $datafeed" -ForegroundColor Green
  exit 0
} catch {
  Write-Host "#ERROR: $($_.Exception.Message)" -ForegroundColor Red
  exit 1
}
