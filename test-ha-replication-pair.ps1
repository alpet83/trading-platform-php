<#
.SYNOPSIS
  PowerShell version of test-ha-replication-pair.sh for Windows (Windows PowerShell 5.1+)
  
.DESCRIPTION
  End-to-end HA pair test on Windows: PRIMARY (3306) + STANDBY (3307) + watchdogs
  
.PARAMETER Command
  setup, configure, test, monitor, teardown, or full
  
.EXAMPLE
  .\test-ha-replication-pair.ps1 -Command full
  .\test-ha-replication-pair.ps1 -Command monitor
#>

param(
  [ValidateSet('setup', 'configure', 'test', 'monitor', 'teardown', 'full')]
  [string]$Command = 'full'
)

# Configuration
$ComposeFile = 'docker-compose.test.yml'
$TestDir = './var/test-ha-pair'
$LogDir = './var/log/test-ha-pair'

# Test credentials
$RootPass = 'test_root_123'
$AppUser = 'trading'
$AppPass = 'test_trading_456'
$ReplUser = 'test_repl'
$ReplPass = 'test_repl_789'

# Container endpoints
$PrimaryHost = '127.0.0.1'
$PrimaryPort = 3306
$StandbyHost = '127.0.0.1'
$StandbyPort = 3307

# Timeouts
$ReadyTimeout = 60
$ReplSyncTimeout = 45

# Colors
function Write-Info {
  Write-Host "[ℹ️  INFO] $args" -ForegroundColor Green
}

function Write-Warn {
  Write-Host "[⚠️  WARN] $args" -ForegroundColor Yellow
}

function Write-Error_ {
  Write-Host "[❌ ERROR] $args" -ForegroundColor Red
}

function Write-Debug_ {
  Write-Host "[🔍 DEBUG] $args" -ForegroundColor Cyan
}

# Check if MySQL CLI is available
function Test-MySQLCLI {
  try {
    $null = mysql --version 2>$null
    return $true
  }
  catch {
    return $false
  }
}

# Wait for MySQL to be ready
function Test-MySQLReady {
  param(
    [string]$Host,
    [int]$Port,
    [string]$Label
  )
  
  Write-Info "Waiting for $Label to be ready ($Host:$Port)..."
  $elapsed = 0
  
  while ($elapsed -lt $ReadyTimeout) {
    try {
      $null = mysql -h"$Host" -P"$Port" -uroot -p"$RootPass" -e "SELECT 1" 2>$null
      Write-Host ""
      Write-Info "✓ $Label is ready"
      return $true
    }
    catch {
      Write-Host -NoNewline "."
      Start-Sleep -Seconds 1
      $elapsed++
    }
  }
  
  Write-Error_ "$Label failed to become ready"
  return $false
}

# ============================================================================
# SETUP
# ============================================================================
function Invoke-Setup {
  Write-Info "Setting up HA pair test environment..."
  
  if (-not (Test-MySQLCLI)) {
    Write-Error_ "MySQL CLI not found. Install mysql-client or MySQL Workbench."
    return $false
  }
  
  Write-Info "Creating test directories..."
  New-Item -ItemType Directory -Force -Path $TestDir, $LogDir | Out-Null
  
  Write-Info "Starting containers (PRIMARY + STANDBY + watchdogs)..."
  & docker-compose -f $ComposeFile up -d
  
  # Wait for both to be ready
  if (-not (Test-MySQLReady $PrimaryHost $PrimaryPort "PRIMARY")) {
    return $false
  }
  
  if (-not (Test-MySQLReady $StandbyHost $StandbyPort "STANDBY")) {
    return $false
  }
  
  Write-Info "Ensuring replication users are created..."
  try {
    $null = mysql -h"$PrimaryHost" -P"$PrimaryPort" -uroot -p"$RootPass" -e @"
      CREATE USER IF NOT EXISTS '$ReplUser'@'%' IDENTIFIED BY '$ReplPass';
      GRANT REPLICATION SLAVE ON *.* TO '$ReplUser'@'%';
      FLUSH PRIVILEGES;
"@ 2>$null
  }
  catch {
    Write-Warn "Replication users may already exist"
  }
  
  Write-Info "✓ Setup complete"
  Write-Host ""
  Write-Host "Listening on:"
  Write-Host "  PRIMARY: $(''127.0.0.1:3306'') (user: $AppUser / root)"
  Write-Host "  STANDBY: $(''127.0.0.1:3307'') (user: $AppUser / root)"
  
  return $true
}

# ============================================================================
# CONFIGURE
# ============================================================================
function Invoke-Configure {
  Write-Info "Configuring replication from PRIMARY to STANDBY..."
  
  Write-Info "[Step 1/3] Reading PRIMARY GTID position..."
  try {
    $primaryGtid = & mysql -h"$PrimaryHost" -P"$PrimaryPort" -uroot -p"$RootPass" -sNe "SELECT @@gtid_binlog_pos;" 2>$null
    Write-Debug_ "PRIMARY GTID: $primaryGtid"
  }
  catch {
    Write-Error_ "Failed to get PRIMARY GTID"
    return $false
  }
  
  Write-Info "[Step 2/3] Configuring STANDBY replication channel..."
  try {
    $null = & mysql -h"$StandbyHost" -P"$StandbyPort" -uroot -p"$RootPass" -e @"
      STOP REPLICA;
      CHANGE MASTER TO
        MASTER_HOST='$PrimaryHost',
        MASTER_PORT=$PrimaryPort,
        MASTER_USER='$ReplUser',
        MASTER_PASSWORD='$ReplPass',
        MASTER_USE_GTID=slave_pos,
        MASTER_CONNECT_RETRY=10;
      START REPLICA;
"@ 2>$null
  }
  catch {
    Write-Warn "CHANGE MASTER (MariaDB 11 syntax) might not work; continuing..."
  }
  
  Write-Info "[Step 3/3] Waiting for replication to sync..."
  $elapsed = 0
  while ($elapsed -lt $ReplSyncTimeout) {
    try {
      $slaveLag = & mysql -h"$StandbyHost" -P"$StandbyPort" -uroot -p"$RootPass" -sNe `
        "SHOW SLAVE STATUS\G" 2>$null | Select-String "Seconds_Behind_Master:" | ForEach-Object { $_.ToString().Split(':')[1].Trim() }
      
      if ($slaveLag -eq "0" -or $slaveLag -eq "NULL" -or [string]::IsNullOrEmpty($slaveLag)) {
        Write-Host ""
        Write-Info "✓ Replication synchronized (lag: 0s or not applicable)"
        break
      }
      
      Write-Host -NoNewline "."
      Start-Sleep -Seconds 1
      $elapsed++
    }
    catch {
      Write-Host -NoNewline "."
      Start-Sleep -Seconds 1
      $elapsed++
    }
  }
  
  Write-Info "✓ Replication configured"
  return $true
}

# ============================================================================
# TEST
# ============================================================================
function Invoke-Test {
  Write-Info "Running replication tests..."
  
  Write-Info "[TEST 1/5] PRIMARY status..."
  & mysql -h"$PrimaryHost" -P"$PrimaryPort" -uroot -p"$RootPass" -e `
    "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_binlog_pos AS 'GTID';"
  
  Write-Info "[TEST 2/5] STANDBY status..."
  & mysql -h"$StandbyHost" -P"$StandbyPort" -uroot -p"$RootPass" -e `
    "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_slave_pos AS 'GTID';"
  
  Write-Info "[TEST 3/5] Writing test data to PRIMARY..."
  $timestamp = Get-Date -UFormat %s
  $testTable = "test_pair_${timestamp}"
  
  & mysql -h"$PrimaryHost" -P"$PrimaryPort" -u"$AppUser" -p"$AppPass" trading -e @"
    CREATE TABLE IF NOT EXISTS $testTable (
      id INT AUTO_INCREMENT PRIMARY KEY,
      test_value VARCHAR(255),
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO $testTable (test_value) VALUES 
      ('replica_test_1'),
      ('replica_test_2'),
      ('replica_test_3');
"@
  
  Write-Info "✓ Test table created: $testTable (3 rows)"
  
  Write-Info "[TEST 4/5] Replication lag..."
  try {
    $slaveLag = & mysql -h"$StandbyHost" -P"$StandbyPort" -uroot -p"$RootPass" -sNe `
      "SHOW SLAVE STATUS\G" 2>$null | Select-String "Seconds_Behind_Master:" | ForEach-Object { $_.ToString().Split(':')[1].Trim() }
    
    if ($slaveLag -eq "0" -or $slaveLag -eq "NULL") {
      Write-Info "✓ Replication lag: 0 seconds (or not applicable)"
    }
    else {
      Write-Warn "Replication lag: ${slaveLag}s"
    }
  }
  catch {
    Write-Warn "Could not determine replication lag"
  }
  
  Write-Info "[TEST 5/5] Verifying replicated data on STANDBY..."
  try {
    $rowCount = & mysql -h"$StandbyHost" -P"$StandbyPort" -u"$AppUser" -p"$AppPass" trading -sNe `
      "SELECT COUNT(*) FROM $testTable;" 2>$null
    
    if ($rowCount -eq "3") {
      Write-Info "✓ All 3 rows replicated to STANDBY"
      & mysql -h"$StandbyHost" -P"$StandbyPort" -u"$AppUser" -p"$AppPass" trading -e `
        "SELECT * FROM $testTable;"
      return $true
    }
    else {
      Write-Error_ "Expected 3 rows on STANDBY, got $rowCount"
      return $false
    }
  }
  catch {
    Write-Error_ "Failed to verify replicated data"
    return $false
  }
}

# ============================================================================
# MONITOR
# ============================================================================
function Invoke-Monitor {
  Write-Info "Monitoring replication status (Ctrl+C to stop)..."
  Write-Host ""
  
  $iteration = 0
  while ($true) {
    Clear-Host
    Write-Host "=== HA Pair Replication Monitor ===" -ForegroundColor Green
    Write-Host "Iteration: $($iteration++) | $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    Write-Host ""
    
    Write-Host "--- PRIMARY (localhost:3306) ---" -ForegroundColor Cyan
    try {
      & mysql -h"$PrimaryHost" -P"$PrimaryPort" -uroot -p"$RootPass" -e `
        "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_binlog_pos AS 'GTID Position';" 2>$null
    }
    catch {
      Write-Host "PRIMARY: UNREACHABLE"
    }
    
    Write-Host ""
    Write-Host "--- STANDBY (localhost:3307) ---" -ForegroundColor Cyan
    try {
      & mysql -h"$StandbyHost" -P"$StandbyPort" -uroot -p"$RootPass" -e `
        "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_slave_pos AS 'GTID Position';" 2>$null
    }
    catch {
      Write-Host "STANDBY: UNREACHABLE"
    }
    
    Write-Host ""
    Write-Host "--- Replication Status ---" -ForegroundColor Cyan
    try {
      & mysql -h"$StandbyHost" -P"$StandbyPort" -uroot -p"$RootPass" -e `
        "SHOW SLAVE STATUS\G" 2>$null | Select-String "Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Last_Error"
    }
    catch {
      Write-Host "STANDBY SLAVE: NOT RUNNING"
    }
    
    Write-Host ""
    Write-Host "Updated every 3 seconds... Press Ctrl+C to exit"
    Start-Sleep -Seconds 3
  }
}

# ============================================================================
# TEARDOWN
# ============================================================================
function Invoke-Teardown {
  Write-Info "Tearing down HA pair test environment..."
  
  Write-Info "Stopping containers..."
  & docker-compose -f $ComposeFile down 2>$null
  
  Write-Info "Removing test directories..."
  if (Test-Path './var/lib/mysql-test-*') {
    Remove-Item -Path './var/lib/mysql-test-*' -Recurse -Force -ErrorAction SilentlyContinue
  }
  if (Test-Path './var/backup/mysql-test-*') {
    Remove-Item -Path './var/backup/mysql-test-*' -Recurse -Force -ErrorAction SilentlyContinue
  }
  if (Test-Path $TestDir) {
    Remove-Item -Path $TestDir -Recurse -Force -ErrorAction SilentlyContinue
  }
  if (Test-Path $LogDir) {
    Remove-Item -Path $LogDir -Recurse -Force -ErrorAction SilentlyContinue
  }
  
  Write-Info "✓ Test environment removed"
}

# ============================================================================
# MAIN
# ============================================================================
switch ($Command) {
  'setup' {
    Invoke-Setup
  }
  'configure' {
    Invoke-Configure
  }
  'test' {
    Invoke-Test
  }
  'monitor' {
    Invoke-Monitor
  }
  'teardown' {
    Invoke-Teardown
  }
  'full' {
    if ((Invoke-Setup) -and (Invoke-Configure) -and (Invoke-Test)) {
      Write-Host ""
      Write-Host "✅ FULL TEST SUITE PASSED" -ForegroundColor Green
      Write-Host ""
      Write-Host "To monitor replication: .\test-ha-replication-pair.ps1 -Command monitor"
      Write-Host "To stop everything:     .\test-ha-replication-pair.ps1 -Command teardown"
    }
    else {
      Write-Error_ "Test suite failed"
      exit 1
    }
  }
  default {
    Write-Host @"
Usage: .\test-ha-replication-pair.ps1 -Command <setup|configure|test|monitor|teardown|full>

Commands:
  setup       - Bring up PRIMARY + STANDBY + watchdog containers
  configure   - Configure GTID replication from PRIMARY → STANDBY
  test        - Run full replication test suite
  monitor     - Watch replication status (real-time, Ctrl+C to exit)
  teardown    - Stop all containers and remove test data
  full        - Complete test: setup → configure → test (recommended)

Test Environment:
  Compose File: $ComposeFile
  PRIMARY:      $($PrimaryHost):$PrimaryPort (Server ID: 1)
  STANDBY:      $($StandbyHost):$StandbyPort (Server ID: 2)
  
  Root Pass:    $RootPass
  App User:     $AppUser / $AppPass
  Repl User:    $ReplUser / $ReplPass

Quick Start:
  1. .\test-ha-replication-pair.ps1 -Command full      # Run complete test
  2. .\test-ha-replication-pair.ps1 -Command monitor   # Monitor in real-time
  3. Ctrl+C then .\test-ha-replication-pair.ps1 -Command teardown

Requirements:
  - Docker & Docker Compose
  - MySQL CLI (mysql.exe in PATH)
"@
  }
}
