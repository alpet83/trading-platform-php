param(
    [string]$StructureSql = "P:\opt\docker\trading-platform-php\trading-structure.sql",
    [string]$OutputSql = "P:\opt\docker\trading-platform-php\docker\mariadb-init\20-bootstrap-core.sql",
    [string]$TableList = "P:\opt\docker\trading-platform-php\shell\bootstrap-core-tables.txt",
    [string]$DatabaseName = "trading"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not (Test-Path $StructureSql)) {
    throw "Structure dump not found: $StructureSql"
}

if (-not (Test-Path $TableList)) {
    throw "Table list not found: $TableList"
}

$outDir = Split-Path -Parent $OutputSql
if (-not (Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}

$sqlRaw = Get-Content -Raw -Path $StructureSql
$tables = Get-Content -Path $TableList |
    ForEach-Object { $_.Trim() } |
    Where-Object { $_ -ne "" -and -not $_.StartsWith("#") }

$builder = New-Object System.Text.StringBuilder
[void]$builder.AppendLine("-- Auto-generated from trading-structure.sql")
[void]$builder.AppendLine("-- Source: $StructureSql")
[void]$builder.AppendLine("-- Table list: $TableList")
[void]$builder.AppendLine("")
[void]$builder.AppendLine("CREATE DATABASE IF NOT EXISTS ``$DatabaseName``;")
[void]$builder.AppendLine("USE ``$DatabaseName``;")
[void]$builder.AppendLine("SET FOREIGN_KEY_CHECKS = 0;")
[void]$builder.AppendLine("")

foreach ($table in $tables) {
    $tableEscaped = [Regex]::Escape($table)

    $createPattern = "(?ms)^[ \t]*CREATE TABLE ``$tableEscaped``.*?;\s*"
    $createMatch = [Regex]::Match($sqlRaw, $createPattern)
    if (-not $createMatch.Success) {
        Write-Warning "CREATE TABLE not found for $table"
        continue
    }

    $createSql = [Regex]::Replace(
        $createMatch.Value,
        "CREATE TABLE ",
        "CREATE TABLE IF NOT EXISTS ",
        [System.Text.RegularExpressions.RegexOptions]::None,
        [TimeSpan]::FromSeconds(2)
    )

    [void]$builder.AppendLine("-- $table")
    [void]$builder.AppendLine($createSql.TrimEnd())
    [void]$builder.AppendLine("")

    $alterPattern = "(?ms)^[ \t]*ALTER TABLE ``$tableEscaped``.*?;\s*"
    $alterMatches = [Regex]::Matches($sqlRaw, $alterPattern)
    foreach ($m in $alterMatches) {
        [void]$builder.AppendLine($m.Value.TrimEnd())
        [void]$builder.AppendLine("")
    }
}

[void]$builder.AppendLine("SET FOREIGN_KEY_CHECKS = 1;")

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($OutputSql, $builder.ToString(), $utf8NoBom)

Write-Output "Generated bootstrap schema: $OutputSql"
