param(
    [string]$ProjectRoot        = "",
    [string]$ComposeFile        = "",
    [string]$PassInitComposeFile = ""
)

$ErrorActionPreference = "Stop"

if (-not [string]::IsNullOrWhiteSpace($ProjectRoot)) { Set-Location $ProjectRoot }

# ── Environment variables (mirrors inject-api-keys.sh) ────────────────────────
$Source    = if ($env:CREDENTIAL_SOURCE)           { $env:CREDENTIAL_SOURCE }           else { "pass"    }
$cfCompose = if (-not [string]::IsNullOrWhiteSpace($ComposeFile))         { $ComposeFile }         `
             elseif ($env:COMPOSE_FILE)            { $env:COMPOSE_FILE }                else { "docker-compose.yml"           }
$cfPassInit = if (-not [string]::IsNullOrWhiteSpace($PassInitComposeFile)) { $PassInitComposeFile } `
             elseif ($env:PASS_INIT_COMPOSE_FILE)  { $env:PASS_INIT_COMPOSE_FILE }      else { "docker-compose.init-pass.yml" }

$Exchange  = if ($env:EXCHANGE)    { $env:EXCHANGE }    else { "" }
$AccountId = if ($env:ACCOUNT_ID)  { $env:ACCOUNT_ID }  else { "" }
$RoSuffix  = if ($env:RO_SUFFIX)   { $env:RO_SUFFIX }   else { "" }

[string]$script:ApiKey       = if ($env:API_KEY)       { $env:API_KEY }       else { "" }
[string]$script:ApiSecret    = if ($env:API_SECRET)    { $env:API_SECRET }    else { "" }
[string]$script:ApiSecretS0  = if ($env:API_SECRET_S0) { $env:API_SECRET_S0 } else { "" }
[string]$script:ApiSecretS1  = if ($env:API_SECRET_S1) { $env:API_SECRET_S1 } else { "" }
[string]$script:ApiSecretSep = if ($env:API_SECRET_SEP){ $env:API_SECRET_SEP } else { "-" }
$ApiSecretSplitPos = if ($env:API_SECRET_SPLIT_POS -match '^\d+$') { [int]$env:API_SECRET_SPLIT_POS } else { 0 }

$BotName          = if ($env:BOT_NAME)                    { $env:BOT_NAME }                    else { ""           }
$ParamKey         = if ($env:BOT_DB_API_KEY_PARAM)        { $env:BOT_DB_API_KEY_PARAM }        else { "api_key"    }
$ParamSecret      = if ($env:BOT_DB_API_SECRET_PARAM)     { $env:BOT_DB_API_SECRET_PARAM }     else { "api_secret" }
$SecretEncrypted  = ($env:SECRET_KEY_ENCRYPTED -eq "1")
$BotManagerKey     = if ($env:BOT_MANAGER_SECRET_KEY)      { $env:BOT_MANAGER_SECRET_KEY }      else { ""                           }
$BotManagerKeyFile = if ($env:BOT_MANAGER_SECRET_KEY_FILE) { $env:BOT_MANAGER_SECRET_KEY_FILE } else { "/run/secrets/bot_manager_key" }

$MariaDbName    = if ($env:MARIADB_DATABASE)        { $env:MARIADB_DATABASE }        else { "trading"        }
$MariaDbRootPwd = if ($env:MARIADB_ROOT_PASSWORD)   { $env:MARIADB_ROOT_PASSWORD }   else { "root_change_me" }

# ── Helpers ───────────────────────────────────────────────────────────────────
function Log([string]$msg)  { Write-Host $msg }
function Fail([string]$msg) { throw "#ERROR: $msg" }
function SqlEsc([string]$v) { return $v.Replace("'", "''") }

function Invoke-DbQuery {
    param([string]$Query, [switch]$NoHeaders)
    $dcArgs = @('-f', $cfCompose, 'exec', '-T', 'mariadb', 'mariadb')
    if ($NoHeaders) { $dcArgs += '-N' }
    $dcArgs += @('-uroot', "-p$MariaDbRootPwd", $MariaDbName, '-e', $Query)
    $out = & docker-compose @dcArgs
    if ($LASTEXITCODE -ne 0) { Fail "MariaDB query failed (exit $LASTEXITCODE)" }
    return $out
}

function Resolve-BotManagerKey {
    if (-not [string]::IsNullOrWhiteSpace($BotManagerKey)) { return $BotManagerKey }
    if (-not [string]::IsNullOrWhiteSpace($BotManagerKeyFile) -and (Test-Path $BotManagerKeyFile)) {
        return (Get-Content $BotManagerKeyFile -Raw).TrimEnd("`r", "`n")
    }
    foreach ($cand in '/run/secrets/bot_manager_secret_key', '/run/secrets/bot_manager_master_key') {
        if (Test-Path $cand) { return (Get-Content $cand -Raw).TrimEnd("`r", "`n") }
    }
    return ""
}

function Encrypt-DbSecret([string]$Plain, [string]$MasterKey) {
    # AES-256-GCM: keyBytes = SHA256(masterKey, raw binary); output = "v1:" + base64(iv[12]+tag[16]+cipher)
    # Identical to PHP encrypt_db_secret() in inject-api-keys.sh — decryptable by bot_manager
    if ($PSVersionTable.PSVersion.Major -lt 7) {
        Fail "AES-GCM encryption requires PowerShell 7+. Current: PS $($PSVersionTable.PSVersion). Upgrade or set SECRET_KEY_ENCRYPTED=0."
    }
    $sha      = [System.Security.Cryptography.SHA256]::Create()
    $keyBytes = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($MasterKey))
    $sha.Dispose()

    $plainBytes  = [System.Text.Encoding]::UTF8.GetBytes($Plain)
    $iv          = [byte[]]::new(12)
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($iv)
    $cipherBytes = [byte[]]::new($plainBytes.Length)
    $tagBytes    = [byte[]]::new(16)

    # .NET 8+ requires tag-size arg in constructor; .NET 5-7 uses single-key constructor
    $aes = if ([System.Environment]::Version.Major -ge 8) {
        [System.Security.Cryptography.AesGcm]::new($keyBytes, 16)
    } else {
        [System.Security.Cryptography.AesGcm]::new($keyBytes)
    }
    try   { $aes.Encrypt($iv, $plainBytes, $cipherBytes, $tagBytes) }
    finally { $aes.Dispose() }

    $combined = $iv + $tagBytes + $cipherBytes
    return "v1:" + [Convert]::ToBase64String($combined)
}

function Resolve-SecretParts {
    if (-not [string]::IsNullOrEmpty($script:ApiSecretS0) -and
        -not [string]::IsNullOrEmpty($script:ApiSecretS1)) { return }
    if ([string]::IsNullOrEmpty($script:ApiSecret)) {
        Fail "Set API_SECRET or API_SECRET_S0/API_SECRET_S1"
    }
    $sep = $script:ApiSecretSep
    $idx = $script:ApiSecret.IndexOf($sep)
    if ($idx -lt 1) {
        Fail "API_SECRET does not contain separator '$sep'. Provide API_SECRET_S0 and API_SECRET_S1 explicitly."
    }
    $script:ApiSecretS0 = $script:ApiSecret.Substring(0, $idx)
    $script:ApiSecretS1 = $script:ApiSecret.Substring($idx + $sep.Length)
}

function Invoke-AutoSplitSecret {
    if ([string]::IsNullOrEmpty($script:ApiSecret)) { Fail "API_SECRET is required for auto split" }
    $len = $script:ApiSecret.Length
    if ($len -lt 3) { Fail "API_SECRET too short for split (min 3 chars)" }
    $pos = if ($ApiSecretSplitPos -gt 0) { $ApiSecretSplitPos } else { [Math]::Floor($len / 2) }
    if ($pos -lt 1 -or $pos -ge $len) { Fail "API_SECRET_SPLIT_POS=$pos out of range (1..$(($len - 1)))" }
    $script:ApiSecretS0  = $script:ApiSecret.Substring(0, $pos)
    $sep                 = $script:ApiSecret.Substring($pos, 1)
    $script:ApiSecretS1  = $script:ApiSecret.Substring($pos + 1)
    $script:ApiSecretSep = $sep
    if ([string]::IsNullOrEmpty($script:ApiSecretS0)) { Fail "auto split produced empty S0" }
    if ([string]::IsNullOrEmpty($sep))                { Fail "auto split produced empty separator" }
    if ([string]::IsNullOrEmpty($script:ApiSecretS1)) { Fail "auto split produced empty S1" }
    Log "#INFO: auto-split at pos=$pos (s0_len=$($script:ApiSecretS0.Length), sep='$sep', s1_len=$($script:ApiSecretS1.Length))"
}

function Invoke-SplitByLiteralSeparator([string]$sep) {
    # Returns $true on success, $false if separator not found — matches sh split_by_separator_literal()
    if ([string]::IsNullOrEmpty($script:ApiSecret) -or [string]::IsNullOrEmpty($sep)) { return $false }
    $idx = $script:ApiSecret.IndexOf($sep)
    if ($idx -lt 1) { return $false }
    $s0 = $script:ApiSecret.Substring(0, $idx)
    $s1 = $script:ApiSecret.Substring($idx + $sep.Length)
    if ([string]::IsNullOrEmpty($s0) -or [string]::IsNullOrEmpty($s1)) { return $false }
    $script:ApiSecretS0  = $s0
    $script:ApiSecretS1  = $s1
    $script:ApiSecretSep = $sep
    return $true
}

# ── Inject: pass mode ─────────────────────────────────────────────────────────
function Invoke-InjectPass {
    if ([string]::IsNullOrEmpty($Exchange))          { Fail "EXCHANGE is required for pass mode"   }
    if ([string]::IsNullOrEmpty($AccountId))         { Fail "ACCOUNT_ID is required for pass mode" }
    if ([string]::IsNullOrEmpty($script:ApiKey))     { Fail "API_KEY is required for pass mode"    }
    Resolve-SecretParts

    $pathBase = "api/${Exchange}@${AccountId}${RoSuffix}"
    Log "#INFO: writing keys into pass store path $pathBase"

    $shCmd = "printf '%s\n' '$($script:ApiKey)' | pass insert -f '$pathBase' >/dev/null; " +
             "printf '%s\n' '$($script:ApiSecretS0)' | pass insert -f '${pathBase}_s0' >/dev/null; " +
             "printf '%s\n' '$($script:ApiSecretS1)' | pass insert -f '${pathBase}_s1' >/dev/null; " +
             "pass show '$pathBase' >/dev/null"
    & docker-compose -f $cfPassInit '--profile' 'init' 'run' '--rm' 'pass-init' 'sh' '-lc' $shCmd
    if ($LASTEXITCODE -ne 0) { Fail "Pass injection failed (exit $LASTEXITCODE)" }
    Log "#SUCCESS: pass credentials injected for ${Exchange}@${AccountId}${RoSuffix}"
}

# ── Inject: db mode ───────────────────────────────────────────────────────────
function Invoke-InjectDb {
    if ([string]::IsNullOrEmpty($BotName))       { Fail "BOT_NAME is required for db mode"   }
    if ([string]::IsNullOrEmpty($AccountId))     { Fail "ACCOUNT_ID is required for db mode" }
    if ([string]::IsNullOrEmpty($script:ApiKey)) { Fail "API_KEY is required for db mode"    }

    $botNameSql = SqlEsc $BotName
    $apiKeySql  = SqlEsc $script:ApiKey
    $pS0Sql     = SqlEsc "${ParamSecret}_s0"
    $pS1Sql     = SqlEsc "${ParamSecret}_s1"
    $pSepSql    = SqlEsc "${ParamSecret}_sep"
    $pEncSql    = SqlEsc "secret_key_encrypted"
    $pKeySql    = SqlEsc $ParamKey
    $pSecretSql = SqlEsc $ParamSecret

    $rawTable = Invoke-DbQuery -NoHeaders "SELECT table_name FROM config__table_map WHERE applicant='$botNameSql' LIMIT 1"
    $cfgTable = ""
    if ($null -ne $rawTable) {
        $t = ($rawTable -join "`n") -split "`n" | Where-Object { $_.Trim() -ne "" } | Select-Object -First 1
        if ($null -ne $t) { $cfgTable = $t.Trim().TrimEnd("`r") }
    }
    if ([string]::IsNullOrWhiteSpace($cfgTable)) {
        Fail "config table not found in config__table_map for applicant '$BotName'"
    }
    $cfgTableSql = SqlEsc $cfgTable

    $encryptedSecretSql   = ""
    $secretS0Sql          = ""
    $secretS1Sql          = ""
    $secretSepSql         = ""
    $dbSecretEncryptedVal = 0

    if ($SecretEncrypted) {
        if ([string]::IsNullOrEmpty($script:ApiSecret)) { Fail "API_SECRET is required when SECRET_KEY_ENCRYPTED=1" }
        $mgrKey = Resolve-BotManagerKey
        if ([string]::IsNullOrWhiteSpace($mgrKey)) {
            Fail "secret encryption requested but BOT_MANAGER_SECRET_KEY (or BOT_MANAGER_SECRET_KEY_FILE) is empty"
        }
        $enc = Encrypt-DbSecret $script:ApiSecret $mgrKey
        if ([string]::IsNullOrEmpty($enc)) { Fail "failed to encrypt API secret" }
        $encryptedSecretSql   = SqlEsc $enc
        $dbSecretEncryptedVal = 1
        $secretSepSql         = "-"
        Log "#INFO: DB secret encrypted with bot_manager master key"
    } else {
        if ([string]::IsNullOrEmpty($script:ApiSecretS0) -or [string]::IsNullOrEmpty($script:ApiSecretS1)) {
            if ($ApiSecretSplitPos -gt 0) {
                Invoke-AutoSplitSecret
            } else {
                $rawSep = Invoke-DbQuery -NoHeaders "SELECT value FROM $cfgTable WHERE account_id=$AccountId AND param='$pSepSql' LIMIT 1"
                $existingSep = ""
                if ($null -ne $rawSep) {
                    $t = ($rawSep -join "`n") -split "`n" | Where-Object { $_.Trim() -ne "" } | Select-Object -First 1
                    if ($null -ne $t) { $existingSep = $t.Trim().TrimEnd("`r") }
                }
                if (-not [string]::IsNullOrEmpty($existingSep)) {
                    $script:ApiSecretSep = $existingSep
                    if (Invoke-SplitByLiteralSeparator $existingSep) {
                        Log "#INFO: auto-split used existing DB separator '$existingSep'"
                    } else {
                        Invoke-AutoSplitSecret
                    }
                } else {
                    Invoke-AutoSplitSecret
                }
            }
        }
        $secretS0Sql  = SqlEsc $script:ApiSecretS0
        $secretS1Sql  = SqlEsc $script:ApiSecretS1
        $secretSepSql = SqlEsc $script:ApiSecretSep
        $dbSecretEncryptedVal = 0
    }

    # Multi-statement dynamic UPSERT — mirrors the .sh query structure exactly
    $q = "SET @t='$cfgTableSql';" +
         "SET @q1=CONCAT('INSERT INTO ',@t,' (account_id,param,value) VALUES ($AccountId,''$pKeySql'',''$apiKeySql'') ON DUPLICATE KEY UPDATE value=VALUES(value)');PREPARE s1 FROM @q1;EXECUTE s1;DEALLOCATE PREPARE s1;" +
         "SET @q2=CONCAT('INSERT INTO ',@t,' (account_id,param,value) VALUES ($AccountId,''$pSecretSql'',''$encryptedSecretSql'') ON DUPLICATE KEY UPDATE value=VALUES(value)');PREPARE s2 FROM @q2;EXECUTE s2;DEALLOCATE PREPARE s2;" +
         "SET @q3=CONCAT('INSERT INTO ',@t,' (account_id,param,value) VALUES ($AccountId,''$pS0Sql'',''$secretS0Sql'') ON DUPLICATE KEY UPDATE value=VALUES(value)');PREPARE s3 FROM @q3;EXECUTE s3;DEALLOCATE PREPARE s3;" +
         "SET @q4=CONCAT('INSERT INTO ',@t,' (account_id,param,value) VALUES ($AccountId,''$pS1Sql'',''$secretS1Sql'') ON DUPLICATE KEY UPDATE value=VALUES(value)');PREPARE s4 FROM @q4;EXECUTE s4;DEALLOCATE PREPARE s4;" +
         "SET @q5=CONCAT('INSERT INTO ',@t,' (account_id,param,value) VALUES ($AccountId,''$pSepSql'',''$secretSepSql'') ON DUPLICATE KEY UPDATE value=VALUES(value)');PREPARE s5 FROM @q5;EXECUTE s5;DEALLOCATE PREPARE s5;" +
         "SET @q6=CONCAT('INSERT INTO ',@t,' (account_id,param,value) VALUES ($AccountId,''$pEncSql'',''$dbSecretEncryptedVal'') ON DUPLICATE KEY UPDATE value=VALUES(value)');PREPARE s6 FROM @q6;EXECUTE s6;DEALLOCATE PREPARE s6;"

    Log "#INFO: writing keys into DB config for bot '$BotName', account '$AccountId'"
    Invoke-DbQuery $q | Out-Null

    $vq = "SET @t='$cfgTableSql';" +
          "SET @vq=CONCAT('SELECT param,value FROM ',@t,' WHERE account_id=$AccountId AND param IN " +
          "(''$pKeySql'',''$pSecretSql'',''$pS0Sql'',''$pS1Sql'',''$pSepSql'',''$pEncSql'') ORDER BY param');" +
          "PREPARE vq FROM @vq;EXECUTE vq;DEALLOCATE PREPARE vq;"
    Invoke-DbQuery $vq

    Log "#SUCCESS: db credentials injected for bot '$BotName', account '$AccountId'"
}

# ── Main ──────────────────────────────────────────────────────────────────────
if (-not (Get-Command docker-compose -ErrorAction SilentlyContinue)) {
    Fail "required command not found: docker-compose"
}
switch ($Source) {
    "pass"  { Invoke-InjectPass }
    "db"    { Invoke-InjectDb   }
    default { Fail "CREDENTIAL_SOURCE must be 'pass' or 'db'" }
}
