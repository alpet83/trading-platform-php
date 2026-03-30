param(
  [string]$ProjectRoot = "",
  [string]$BaseComposeFile = "docker-compose.yml",
  [string]$LegacyComposeFile = "docker-compose.signals-legacy.yml",
  [bool]$PrepareSecrets = $true,
  [int]$HealthTimeoutSec = 90,
  [int]$HealthIntervalSec = 3
)

$ErrorActionPreference = "Stop"

function Info($msg) { Write-Host $msg }
function Fail($msg) { throw $msg }

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
  $ProjectRoot = (Resolve-Path "$PSScriptRoot\..").Path
}

Set-Location $ProjectRoot

if (-not (Test-Path $BaseComposeFile)) {
  Fail "Missing $BaseComposeFile in $ProjectRoot"
}
if (-not (Test-Path $LegacyComposeFile)) {
  Fail "Missing $LegacyComposeFile in $ProjectRoot"
}

if ($PrepareSecrets) {
  $legacyCfg = Join-Path $ProjectRoot "secrets/signals_db_config.php"
  $legacyCfgExample = Join-Path $ProjectRoot "secrets/signals_db_config.php.example"
  if (-not (Test-Path $legacyCfg)) {
    if (-not (Test-Path $legacyCfgExample)) {
      Fail "Missing secrets/signals_db_config.php and example template"
    }
    Copy-Item -Path $legacyCfgExample -Destination $legacyCfg -Force
    Info "#INFO: created secrets/signals_db_config.php from template"
  }
}

Info "#STEP 1/3: start legacy signals services"
docker-compose -f $BaseComposeFile -f $LegacyComposeFile up -d signals-legacy-db signals-legacy
if ($LASTEXITCODE -ne 0) {
  Fail "Failed to start signals-legacy services"
}

$legacyPort = 8090
$envPath = Join-Path $ProjectRoot ".env"
if (Test-Path $envPath) {
  $line = Select-String -Path $envPath -Pattern '^SIGNALS_LEGACY_PORT=' | Select-Object -First 1
  if ($line) {
    $value = ($line.Line -replace '^SIGNALS_LEGACY_PORT=', '').Trim()
    if ($value -match '^\d+$') {
      $legacyPort = [int]$value
    }
  }
}

$legacyUrl = "http://127.0.0.1:$legacyPort/docs.html"
Info "#STEP 2/3: wait for legacy API probe $legacyUrl"

$ok = $false
$attempts = [Math]::Ceiling($HealthTimeoutSec / $HealthIntervalSec)
for ($i = 0; $i -lt $attempts; $i++) {
  try {
    $resp = Invoke-WebRequest -Uri $legacyUrl -UseBasicParsing -TimeoutSec 5
    if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500) {
      $ok = $true
      break
    }
  } catch {
    # keep polling
  }
  Start-Sleep -Seconds $HealthIntervalSec
}

if (-not $ok) {
  Info "#WARN: legacy endpoint did not respond in time"
  docker-compose -f $BaseComposeFile -f $LegacyComposeFile logs --tail 80 signals-legacy
  Fail "Legacy signals probe failed"
}

Info "#STEP 3/3: show running legacy services"
docker-compose -f $BaseComposeFile -f $LegacyComposeFile ps signals-legacy-db signals-legacy

Info ""
Info "#SUCCESS: legacy signals stack is up"
Info "#URL legacy docs: $legacyUrl"
Info "#NEXT: run scripts/deploy-simple.ps1 for trading group"
