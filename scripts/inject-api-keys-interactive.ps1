param(
  [string]$ProjectRoot = "P:/opt/docker/trading-platform-php",
  [string]$ComposeFile = "docker-compose.yml",
  [string]$PassInitComposeFile = "docker-compose.init-pass.yml"
)

$ErrorActionPreference = "Stop"
Set-Location $ProjectRoot

function Ask([string]$Prompt, [string]$Default = "") {
  if ($Default -ne "") {
    $v = Read-Host "$Prompt [$Default]"
    if ([string]::IsNullOrWhiteSpace($v)) { return $Default }
    return $v.Trim()
  }
  return (Read-Host $Prompt).Trim()
}

function AskSecret([string]$Prompt) {
  $secure = Read-Host $Prompt -AsSecureString
  $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
  try {
    return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
  } finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
  }
}

function EscapeSql([string]$Value) {
  return $Value.Replace("'", "''")
}

function AskChoice([string]$Prompt, [int]$Min, [int]$Max, [int]$Default) {
  while ($true) {
    $raw = Ask $Prompt "$Default"
    $v = 0
    if ([int]::TryParse($raw, [ref]$v) -and $v -ge $Min -and $v -le $Max) {
      return $v
    }
    Write-Host "Invalid choice: '$raw' (expected $Min..$Max)"
  }
}

function Invoke-MariaDbQuery(
  [string]$ComposeFile,
  [string]$Query,
  [switch]$NoHeaders
) {
  $dbName = $env:MARIADB_DATABASE
  if ([string]::IsNullOrWhiteSpace($dbName)) { $dbName = "trading" }
  $rootPwd = $env:MARIADB_ROOT_PASSWORD
  if ([string]::IsNullOrWhiteSpace($rootPwd)) { $rootPwd = "root_change_me" }

  $args = @('-f', $ComposeFile, 'exec', '-T', 'mariadb', 'mariadb')
  if ($NoHeaders) { $args += '-N' }
  $args += @('-uroot', "-p$rootPwd", $dbName, '-e', $Query)

  $output = & docker-compose @args
  if ($LASTEXITCODE -ne 0) {
    throw "MariaDB query failed with code $LASTEXITCODE"
  }
  return $output
}

function SplitSecretAuto([string]$Secret) {
  if ([string]::IsNullOrWhiteSpace($Secret) -or $Secret.Length -lt 3) {
    throw "API secret must be at least 3 chars for auto split"
  }

  $mid = [Math]::Floor($Secret.Length / 2)
  if ($mid -lt 1 -or $mid -ge $Secret.Length) {
    throw "Auto split position is out of range"
  }

  $s0 = $Secret.Substring(0, $mid)
  $sep = $Secret.Substring($mid, 1)
  $s1 = $Secret.Substring($mid + 1)

  if ([string]::IsNullOrWhiteSpace($s0) -or [string]::IsNullOrWhiteSpace($sep) -or [string]::IsNullOrWhiteSpace($s1)) {
    throw "Auto split produced an empty part"
  }

  return [pscustomobject]@{
    S0 = $s0
    Sep = $sep
    S1 = $s1
    Pos = $mid
  }
}

$source = Ask "Credential source (pass/db)" "pass"
if ($source -ne "pass" -and $source -ne "db") {
  throw "Unsupported source: $source"
}

if ($source -eq "pass") {
  $accountId = Ask "Account ID" "1"
  $apiKey = AskSecret "API key"
  $exchange = Ask "Exchange" "bitmex"
  $s0 = AskSecret "API secret part S0"
  $s1 = AskSecret "API secret part S1"

  docker-compose -f $PassInitComposeFile --profile init run --rm pass-init sh -lc "printf '%s\n' '$apiKey' | pass insert -f 'api/$exchange@$accountId' >/dev/null; printf '%s\n' '$s0' | pass insert -f 'api/$exchange@$accountId`_s0' >/dev/null; printf '%s\n' '$s1' | pass insert -f 'api/$exchange@$accountId`_s1' >/dev/null; pass show 'api/$exchange@$accountId' >/dev/null"
  if ($LASTEXITCODE -ne 0) {
    throw "Pass injection failed with code $LASTEXITCODE"
  }

  Write-Host "#SUCCESS: pass credentials injected for $exchange@$accountId"
} else {
  $botsRaw = Invoke-MariaDbQuery -ComposeFile $ComposeFile -NoHeaders -Query "SELECT applicant, table_name FROM config__table_map ORDER BY applicant"
  $bots = @()
  foreach ($line in $botsRaw) {
    $s = "$line".Trim()
    if ([string]::IsNullOrWhiteSpace($s)) { continue }
    $parts = $s -split "`t", 2
    if ($parts.Count -lt 2) { continue }
    $bots += [pscustomobject]@{ Applicant = $parts[0].Trim(); Table = $parts[1].Trim() }
  }

  if ($bots.Count -eq 0) {
    throw "No bots found in config__table_map"
  }

  Write-Host "Available bots:"
  for ($i = 0; $i -lt $bots.Count; $i++) {
    $n = $i + 1
    Write-Host ("[{0}] {1}  ({2})" -f $n, $bots[$i].Applicant, $bots[$i].Table)
  }

  $botIdx = AskChoice "Choose bot number" 1 $bots.Count 1
  $selectedBot = $bots[$botIdx - 1]
  $botName = $selectedBot.Applicant
  $cfgTable = $selectedBot.Table

  $accRaw = Invoke-MariaDbQuery -ComposeFile $ComposeFile -NoHeaders -Query "SELECT DISTINCT account_id FROM $cfgTable ORDER BY account_id"
  $accounts = @()
  foreach ($line in $accRaw) {
    $s = "$line".Trim()
    if (-not [string]::IsNullOrWhiteSpace($s)) {
      $accounts += $s
    }
  }

  if ($accounts.Count -gt 0) {
    Write-Host "Available account_id values for $botName:"
    for ($i = 0; $i -lt $accounts.Count; $i++) {
      $n = $i + 1
      Write-Host ("[{0}] {1}" -f $n, $accounts[$i])
    }
    $accIdx = AskChoice "Choose account number" 1 $accounts.Count 1
    $accountId = $accounts[$accIdx - 1]
  } else {
    $accountId = Ask "Account ID" "1"
  }

  $apiKey = AskSecret "API key"
  $secret = AskSecret "API secret"
  $secretEncrypted = Ask "Encrypt API secret in DB using bot_manager key? (0/1)" "1"

  if ($secretEncrypted -eq "1") {
    $env:SECRET_KEY_ENCRYPTED = "1"
  } else {
    $env:SECRET_KEY_ENCRYPTED = "0"
  }

  $env:CREDENTIAL_SOURCE = "db"
  $env:BOT_NAME = "$botName"
  $env:ACCOUNT_ID = "$accountId"
  $env:API_KEY = "$apiKey"
  $env:API_SECRET = "$secret"
  & sh scripts/inject-api-keys.sh

  if ($LASTEXITCODE -ne 0) {
    throw "db key injection failed with code $LASTEXITCODE"
  }

  Write-Host "#SUCCESS: db credentials injected for $botName, account $accountId"
}

Write-Host "#SUCCESS: interactive key injection completed"
