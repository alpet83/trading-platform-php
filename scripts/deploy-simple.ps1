param(
  [string]$ProjectRoot = "P:/opt/docker/trading-platform-php",
  [string]$ComposeFile = "docker-compose.yml",
  [int]$DbWaitTimeoutSec = 180,
  [int]$DbWaitIntervalSec = 3,
  [bool]$CleanStart = $true,
  [ValidateSet("auto", "yes", "no")]
  [string]$GeneratePasswords = "auto",
  [string]$OverrideFile = "docker-compose.override.yml",
  [string]$EnvFile = ".env"
)

$ErrorActionPreference = "Stop"

function Info($msg) { Write-Host $msg }
function Fail($msg) { throw $msg }

# ---------- Docker Compose v1 / v2 detection ----------
$script:DockerComposeV2 = $false
docker compose version 2>$null | Out-Null
if ($LASTEXITCODE -eq 0) { $script:DockerComposeV2 = $true }

function Invoke-Compose([string[]]$CompArgs) {
    if ($script:DockerComposeV2) {
        docker compose @CompArgs
    } else {
        docker-compose @CompArgs
    }
}

# ---------- Ensure sibling repos are present ----------
function Ensure-ExternalRepos {
    $externalDir = if ($env:EXTERNAL_REPOS_DIR) {
        $env:EXTERNAL_REPOS_DIR
    } else {
        [System.IO.Path]::GetFullPath((Join-Path $ProjectRoot ".."))
    }

    if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
        Info "#WARN: git not found in PATH; cannot auto-clone external repos"
        return
    }

    $repos = @(
        [pscustomobject]@{ Name = "alpet-libs-php"; Url = "https://github.com/alpet83/alpet-libs-php" },
        [pscustomobject]@{ Name = "datafeed";       Url = "https://github.com/alpet83/datafeed" }
    )

    foreach ($repo in $repos) {
        $destPath = Join-Path $externalDir $repo.Name
        if (Test-Path (Join-Path $destPath ".git")) {
            Info "#INFO: $($repo.Name) already present at $destPath"
            continue
        }
        if (Test-Path $destPath) {
            Info "#WARN: $destPath exists but is not a git repo — skipping clone"
            continue
        }
        Info "#INFO: cloning $($repo.Url) -> $destPath"
        git clone --depth=1 $repo.Url $destPath
        if ($LASTEXITCODE -ne 0) {
            Info "#WARN: failed to clone $($repo.Name) (exit $LASTEXITCODE)"
        }
    }
}

function Verify-DatafeedManagerSource {
    $managerPath = Join-Path $ProjectRoot "../datafeed/src/datafeed_manager.php"
    if (-not (Test-Path $managerPath)) {
        Info "#INFO: datafeed manager not found — running Ensure-ExternalRepos first..."
        Ensure-ExternalRepos
        if (-not (Test-Path $managerPath)) {
            Fail "datafeed manager still missing after repo clone: $managerPath"
        }
    }
}

function New-RandomPassword([int]$Length = 28) {
  Add-Type -AssemblyName System.Security
  $bytes = New-Object byte[] 96
  [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
  $raw = [Convert]::ToBase64String($bytes) -replace '[^A-Za-z0-9]', ''
  if ($raw.Length -lt $Length) {
    $raw += ([Guid]::NewGuid().ToString('N'))
  }
  return $raw.Substring(0, $Length)
}

function Set-EnvValue([string]$Path, [string]$Key, [string]$Value) {
  if (-not (Test-Path $Path)) {
    New-Item -ItemType File -Path $Path -Force | Out-Null
  }

  $content = Get-Content -Path $Path -Raw -ErrorAction SilentlyContinue
  if ($null -eq $content) { $content = "" }
  $pattern = "(?m)^" + [regex]::Escape($Key) + "=.*$"
  $line = "$Key=$Value"
  if ([regex]::IsMatch($content, $pattern)) {
    $content = [regex]::Replace($content, $pattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $line })
  } else {
    if ($content -ne "" -and -not $content.EndsWith("`n")) { $content += "`n" }
    $content += "$line`n"
  }
  $utf8NoBom = New-Object System.Text.UTF8Encoding $false
  [System.IO.File]::WriteAllText($Path, $content, $utf8NoBom)
}

function Set-OverrideValue([string]$Path, [string]$Key, [string]$Value) {
  if (-not (Test-Path $Path)) {
    Info "#WARN: $Path not found, skip $Key update"
    return
  }

  $lines = Get-Content -Path $Path
  $updated = $false
  for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($lines[$i] -match "^([\s]*)" + [regex]::Escape($Key) + ":[\s]*") {
      $indent = $Matches[1]
      $lines[$i] = "${indent}${Key}: $Value"
      $updated = $true
    }
  }

  if (-not $updated) {
    Info "#WARN: key $Key not found in $Path"
    return
  }

  $utf8NoBom = New-Object System.Text.UTF8Encoding $false
  [System.IO.File]::WriteAllText($Path, ($lines -join "`n") + "`n", $utf8NoBom)
}

function Prepare-DeployPasswords {
  $mode = $GeneratePasswords
  if ($mode -eq "auto") {
    if (-not [Console]::IsInputRedirected) {
      $answer = Read-Host "#PROMPT: Generate random passwords and update $OverrideFile + $EnvFile? [Y/n]"
      if ([string]::IsNullOrWhiteSpace($answer) -or $answer -match '^(y|yes)$') {
        $mode = "yes"
      } else {
        $mode = "no"
      }
    } else {
      $mode = "no"
      Info "#INFO: non-interactive session; skip password generation (use -GeneratePasswords yes to force)."
    }
  }

  if ($mode -eq "no") {
    Info "#INFO: password preflight skipped by user choice"
    return
  }

  $envPathLocal = Join-Path $ProjectRoot $EnvFile
  if (-not (Test-Path $envPathLocal)) {
    $envExamplePath = Join-Path $ProjectRoot ".env.example"
    if (Test-Path $envExamplePath) {
      Copy-Item -Path $envExamplePath -Destination $envPathLocal -Force
      Info "#INFO: created $EnvFile from .env.example"
    } else {
      $minimalEnv = @(
        'COMPOSE_PROJECT_NAME=trd',
        'TZ=UTC',
        'MARIADB_DATABASE=trading',
        'MARIADB_USER=trading',
        'WEB_PORT=8088',
        'WEB_PUBLISH_IP=127.0.0.1'
      ) -join "`n"
      $utf8NoBom = New-Object System.Text.UTF8Encoding $false
      [System.IO.File]::WriteAllText($envPathLocal, $minimalEnv + "`n", $utf8NoBom)
      Info "#INFO: created minimal $EnvFile"
    }
  }

  $rootPwd = New-RandomPassword
  $tradingPwd = New-RandomPassword
  $replPwd = New-RandomPassword
  $remotePwd = New-RandomPassword
  $botTraderPwd = New-RandomPassword

  Set-EnvValue -Path $envPathLocal -Key 'MARIADB_ROOT_PASSWORD' -Value $rootPwd
  Set-EnvValue -Path $envPathLocal -Key 'MARIADB_PASSWORD' -Value $tradingPwd
  Set-EnvValue -Path $envPathLocal -Key 'TRADING_DB_PASSWORD' -Value $tradingPwd
  Set-EnvValue -Path $envPathLocal -Key 'MARIADB_REPL_PASSWORD' -Value $replPwd
  Set-EnvValue -Path $envPathLocal -Key 'MARIADB_REMOTE_PASSWORD' -Value $remotePwd
  Set-EnvValue -Path $envPathLocal -Key 'BOT_TRADER_PASSWORD' -Value $botTraderPwd

  $overridePathLocal = Join-Path $ProjectRoot $OverrideFile
  Set-OverrideValue -Path $overridePathLocal -Key 'MARIADB_ROOT_PASSWORD' -Value $rootPwd
  Set-OverrideValue -Path $overridePathLocal -Key 'MARIADB_PASSWORD' -Value $tradingPwd
  Set-OverrideValue -Path $overridePathLocal -Key 'MARIADB_REPL_PASSWORD' -Value $replPwd
  Set-OverrideValue -Path $overridePathLocal -Key 'MARIADB_REMOTE_PASSWORD' -Value $remotePwd
  Set-OverrideValue -Path $overridePathLocal -Key 'DB_PASS' -Value $tradingPwd

  Info "#INFO: randomized credentials applied to $EnvFile and $OverrideFile"
}

function Ensure-CqdsDbSecretIfRepoPresent([string]$TradingRoot) {
  $cqdsRoot = if ($env:CQDS_ROOT) { $env:CQDS_ROOT } else { (Join-Path $TradingRoot "../cqds") }
  $compose = Join-Path $cqdsRoot "docker-compose.yml"
  if (-not (Test-Path $cqdsRoot) -or -not (Test-Path $compose)) {
    Info "#INFO: CQDS not found at $cqdsRoot, skip cqds_db_password"
    return
  }
  $secretsDir = Join-Path $cqdsRoot "secrets"
  if (-not (Test-Path $secretsDir)) {
    New-Item -ItemType Directory -Path $secretsDir -Force | Out-Null
  }
  $f = Join-Path $secretsDir "cqds_db_password"
  if ((Test-Path $f) -and ((Get-Item $f).Length -gt 0)) {
    Info "#INFO: $f already present, leaving unchanged"
    return
  }
  $pw = New-RandomPassword
  $utf8NoBom = New-Object System.Text.UTF8Encoding $false
  [System.IO.File]::WriteAllText($f, $pw, $utf8NoBom)
  Info "#INFO: created $f (random password for CQDS PostgreSQL)"
}

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

# Resolve sibling-repos base dir and key paths
$externalReposBase = if ($env:EXTERNAL_REPOS_DIR) {
    $env:EXTERNAL_REPOS_DIR
} else {
    [System.IO.Path]::GetFullPath((Join-Path $ProjectRoot ".."))
}
$AlpetLibsPath       = [System.IO.Path]::GetFullPath((Join-Path $externalReposBase "alpet-libs-php"))
$AlpetLibsPathDocker = $AlpetLibsPath -replace '\\', '/'
$ProjectRootDocker   = ([System.IO.Path]::GetFullPath($ProjectRoot)) -replace '\\', '/'

Info "#STEP 0/8: ensure external repos (alpet-libs-php, datafeed)"
Ensure-ExternalRepos

Verify-DatafeedManagerSource

$publishIp = "127.0.0.1"
$envPath = Join-Path $ProjectRoot ".env"
if (Test-Path $envPath) {
    $envLine = Select-String -Path $envPath -Pattern '^WEB_PUBLISH_IP=' | Select-Object -First 1
    if ($envLine) {
        $publishIp = ($envLine.Line -replace '^WEB_PUBLISH_IP=', '').Trim()
        if ([string]::IsNullOrWhiteSpace($publishIp)) { $publishIp = "127.0.0.1" }
    }
}

if ($CleanStart) {
    Info "#STEP 1/8: clean previous containers"
    Invoke-Compose @("-f", $ComposeFile, "down", "--remove-orphans")
}

Info "#STEP 2/8: optional password preflight (.env + docker-compose.override.yml)"
Prepare-DeployPasswords
Ensure-CqdsDbSecretIfRepoPresent -TradingRoot $ProjectRoot

Info "#STEP 3/8: bootstrap runtime and generate secrets/db_config.php with random trading password"
docker run --rm `
    -e ALPET_LIBS_REPO=/alpet-libs `
    -v "${AlpetLibsPathDocker}:/alpet-libs" `
    -v "${ProjectRootDocker}:/work" `
    -w /work `
    alpine:3.20 sh -lc "apk add --no-cache git openssl >/dev/null; git config --global --add safe.directory /alpet-libs; git config --global --add safe.directory /alpet-libs/.git; sh shell/bootstrap_container_env.sh"
if ($LASTEXITCODE -ne 0) { Fail "Bootstrap stage failed" }

Info "#STEP 4/8: build images for mariadb/web"
Invoke-Compose @("-f", $ComposeFile, "build", "mariadb", "web")
if ($LASTEXITCODE -ne 0) { Fail "Build stage failed" }

Info "#STEP 5/8: start mariadb"
Invoke-Compose @("-f", $ComposeFile, "up", "-d", "mariadb")
if ($LASTEXITCODE -ne 0) { Fail "Failed to start mariadb" }

Info "#STEP 6/8: wait for mariadb health"
$ok = $false
if (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
    $oldNativeErrPref = $PSNativeCommandUseErrorActionPreference
    $PSNativeCommandUseErrorActionPreference = $false
}

function Test-DbPing {
    $rc = Invoke-NativeNoThrow { Invoke-Compose @("-f", $ComposeFile, "exec", "-T", "mariadb", "sh", "-lc", 'mariadb-admin ping -h 127.0.0.1 -uroot -p"$MARIADB_ROOT_PASSWORD"') }
    if ($rc -eq 0) { return $true }
    $rc = Invoke-NativeNoThrow { Invoke-Compose @("-f", $ComposeFile, "exec", "-T", "mariadb", "sh", "-lc", "mariadb-admin ping -h 127.0.0.1 -uroot") }
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
    Invoke-Compose @("-f", $ComposeFile, "logs", "--tail", "80", "mariadb")
    Fail "MariaDB was not healthy within ${DbWaitTimeoutSec}s"
}
Info "#INFO: mariadb is healthy"

Info "#STEP 7/8: start web (api + admin ui)"
Invoke-Compose @("-f", $ComposeFile, "up", "-d", "web")
if ($LASTEXITCODE -ne 0) { Fail "Failed to start web" }

Info "#STEP 8/8: test admin/api endpoints"
Invoke-Compose @("-f", $ComposeFile, "exec", "-T", "web", "php", "-r", "if(@file_get_contents('http://127.0.0.1/basic-admin.php')===false){fwrite(STDERR,'basic-admin probe failed\n'); exit(1);} echo 'basic-admin-ok\n';")
if ($LASTEXITCODE -ne 0) { Fail "basic-admin endpoint probe failed" }

Invoke-Compose @("-f", $ComposeFile, "exec", "-T", "web", "php", "-r", "if(@file_get_contents('http://127.0.0.1/api/index.php')===false){fwrite(STDERR,'api probe failed\n'); exit(1);} echo 'api-ok\n';")
if ($LASTEXITCODE -ne 0) { Fail "api endpoint probe failed" }

Invoke-Compose @("-f", $ComposeFile, "exec", "-T", "web", "php", "-r", "`$s=@file_get_contents('http://127.0.0.1/bot/get_vwap.php?pair_id=3&limit=5&exchange=bitmex'); if(`$s===false){fwrite(STDERR,'warn: get_vwap probe unavailable\n'); exit(0);} echo 'get-vwap-probe=' . substr(trim(`$s),0,80) . '\n';")
if ($LASTEXITCODE -ne 0) { Fail "get_vwap endpoint probe failed" }

Info ""
Info "#SUCCESS: simple deploy completed"
Info "#URL admin: http://$publishIp`:8088/basic-admin.php"
Info "#URL api:   http://$publishIp`:8088/api/index.php"
Info "#NOTE: local admin UI is intended for trusted local access"
Info "#NEXT: run scripts/inject-api-keys.sh to load exchange keys"
