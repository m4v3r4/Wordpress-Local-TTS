# Local TTS - builds the plugin ZIP (Windows PowerShell 5.1+ or PowerShell 7).
#
#   powershell -ExecutionPolicy Bypass -File tools\build.ps1
#
# Writes dist\local-tts-<version>.zip. Install it from Plugins > Add New > Upload Plugin.
# Runtime and voices are not in the ZIP; install them from Local TTS > Voices.
# If you changed any strings, run `python tools/i18n.py build` first.

[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = Split-Path -Parent $PSScriptRoot
$Plugin = Join-Path $Root "local-tts"
$Dist = Join-Path $Root "dist"

$main = Get-Content (Join-Path $Plugin "local-tts.php") -Raw
if ($main -notmatch "define\( 'LTTS_VERSION', '([^']+)' \)") { throw "LTTS_VERSION not found" }
$Version = $Matches[1]

# Only these go into the ZIP.
$include = @("local-tts.php", "uninstall.php", "readme.txt", "LICENSE", "NOTICE", "assets", "includes", "languages", "licenses")

New-Item -ItemType Directory -Force -Path $Dist | Out-Null
$zipPath = Join-Path $Dist "local-tts-$Version.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath }

# ZipFile with forward slashes so Linux servers extract it correctly
# (Compress-Archive in PowerShell 5.1 writes backslashes).
$zip = [IO.Compression.ZipFile]::Open($zipPath, [IO.Compression.ZipArchiveMode]::Create)
try {
  $base = (Resolve-Path $Plugin).Path.TrimEnd("\")
  foreach ($name in $include) {
    $p = Join-Path $base $name
    if (-not (Test-Path $p)) { throw "Missing: $p" }
    $items = if ((Get-Item $p).PSIsContainer) { Get-ChildItem -LiteralPath $p -Recurse -File -Force } else { @(Get-Item $p) }
    foreach ($f in $items) {
      $rel = $f.FullName.Substring($base.Length + 1).Replace("\", "/")
      [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $f.FullName, "local-tts/$rel", [IO.Compression.CompressionLevel]::Optimal)
    }
  }
} finally {
  $zip.Dispose()
}

Write-Host ("{0}  {1:N0} KB" -f $zipPath, ((Get-Item $zipPath).Length / 1KB))
