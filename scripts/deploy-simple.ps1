param(
  [string]$ProjectRoot = "P:/opt/docker/trading-platform-php",
  [string]$ComposeFile = "docker-compose.yml",
  [int]$DbWaitTimeoutSec = 180,
  [int]$DbWaitIntervalSec = 3,
  [bool]$CleanStart = $true
)

$ErrorActionPreference = "Stop"

function Info($msg) { Write-Host $msg }
function Fail($msg) { throw $msg }

function Invoke-NativeNoThrow([scriptblock]$Command) {
  $prevErr = $ErrorActionPreference
  $ErrorActionPreference = "Continue"
  try {
    & $Command 2>$null | Out-Null
  } catch {
    # Ignore native-command exceptions during readiness polling.
  } finally {
    $ErrorActionPreference = $prevErr
  }

  return $LASTEXITCODE
}

Set-Location $ProjectRoot

$publishIp = "127.0.0.1"
$envPath = Join-Path $ProjectRoot ".env"
if (Test-Path $envPath) {
  $line = Select-String -Path $envPath -Pattern '^WEB_PUBLISH_IP=' | Select-Object -First 1
  if ($line) {
    $publishIp = ($line.Line -replace '^WEB_PUBLISH_IP=', '').Trim()
    if ([string]::IsNullOrWhiteSpace($publishIp)) { $publishIp = "127.0.0.1" }
  }
}

if ($CleanStart) {
  Info "#STEP 0/6: clean previous containers"
  docker-compose -f $ComposeFile down --remove-orphans
}

if (-not (Test-Path "P:/GitHub/alpet-libs-php")) {
  Fail "Missing P:/GitHub/alpet-libs-php"
}

Info "#STEP 1/6: bootstrap runtime and generate secrets/db_config.php with random trading password"
docker run --rm -e ALPET_LIBS_REPO=/alpet-libs -v P:/GitHub/alpet-libs-php:/alpet-libs -v ${PWD}:/work -w /work alpine:3.20 sh -lc "apk add --no-cache git openssl >/dev/null; git config --global --add safe.directory /alpet-libs; git config --global --add safe.directory /alpet-libs/.git; sh shell/bootstrap_container_env.sh"
if ($LASTEXITCODE -ne 0) { Fail "Bootstrap stage failed" }

Info "#STEP 2/6: build images for mariadb/web"
docker-compose -f $ComposeFile build mariadb web
if ($LASTEXITCODE -ne 0) { Fail "Build stage failed" }

Info "#STEP 3/6: start mariadb"
docker-compose -f $ComposeFile up -d mariadb
if ($LASTEXITCODE -ne 0) { Fail "Failed to start mariadb" }

Info "#STEP 4/6: wait for mariadb health"
$ok = $false
$oldNativeErrPref = $false
if (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
  $oldNativeErrPref = $PSNativeCommandUseErrorActionPreference
  $PSNativeCommandUseErrorActionPreference = $false
}

function Test-DbPing {
  $rc = Invoke-NativeNoThrow { docker-compose -f $ComposeFile exec -T mariadb sh -lc 'mariadb-admin ping -h 127.0.0.1 -uroot -p"$MARIADB_ROOT_PASSWORD"' }
  if ($rc -eq 0) { return $true }

  $rc = Invoke-NativeNoThrow { docker-compose -f $ComposeFile exec -T mariadb sh -lc 'mariadb-admin ping -h 127.0.0.1 -uroot' }
  return ($rc -eq 0)
}

for ($i = 0; $i -lt [Math]::Ceiling($DbWaitTimeoutSec / $DbWaitIntervalSec); $i++) {
  if (Test-DbPing) { $ok = $true; break }

  Start-Sleep -Seconds $DbWaitIntervalSec
}

if (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
  $PSNativeCommandUseErrorActionPreference = $oldNativeErrPref
}

if (-not $ok) {
  docker-compose -f $ComposeFile logs --tail 80 mariadb
  Fail "MariaDB was not healthy within ${DbWaitTimeoutSec}s"
}
Info "#INFO: mariadb is healthy"

Info "#STEP 5/6: start web(api + admin ui)"
docker-compose -f $ComposeFile up -d web
if ($LASTEXITCODE -ne 0) { Fail "Failed to start web" }

Info "#STEP 6/6: test admin/api endpoints"
docker-compose -f $ComposeFile exec -T web php -r "if(@file_get_contents('http://127.0.0.1/basic-admin.php')===false){fwrite(STDERR,'basic-admin probe failed\n'); exit(1);} echo 'basic-admin-ok\n';"
if ($LASTEXITCODE -ne 0) { Fail "basic-admin endpoint probe failed" }

docker-compose -f $ComposeFile exec -T web php -r "if(@file_get_contents('http://127.0.0.1/api/index.php')===false){fwrite(STDERR,'api probe failed\n'); exit(1);} echo 'api-ok\n';"
if ($LASTEXITCODE -ne 0) { Fail "api endpoint probe failed" }

Info ""
Info "#SUCCESS: simple deploy completed"
Info "#URL admin: http://$publishIp`:8088/basic-admin.php"
Info "#URL api:   http://$publishIp`:8088/api/index.php"
Info "#NOTE: local admin UI is intended for trusted local access"
Info "#NEXT: run scripts/inject-api-keys.sh to load exchange keys"
