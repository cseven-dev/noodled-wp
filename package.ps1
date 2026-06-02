# noodled-wp package & deploy script
# Creates a zip for manual upload or deploys via FTP to SiteGround

param(
    [switch]$Deploy,
    [string]$FtpHost = "",
    [string]$FtpUser = "",
    [string]$FtpPass = "",
    [string]$FtpPath = "/wp-content/plugins/noodled"
)

$pluginDir = $PSScriptRoot
$pluginName = "noodled"
$version = (Select-String -Path "$pluginDir\noodled.php" -Pattern "Version:\s+(.+)" | ForEach-Object { $_.Matches[0].Groups[1].Value.Trim() })

# ── Sync landing page from lab ──
$landingSrc = "D:\c7\lab\noodled.html"
if (Test-Path $landingSrc) {
    Copy-Item $landingSrc "$pluginDir\templates\landing.html" -Force
    Write-Host "Synced landing page from lab" -ForegroundColor Green
}

# ── Auto-increment patch version ──
$parts = $version.Split(".")
$parts[2] = [int]$parts[2] + 1
$newVersion = $parts -join "."

# Update version in noodled.php (header and constant)
(Get-Content "$pluginDir\noodled.php") -replace "Version:\s+$version", "Version:     $newVersion" -replace "NOODLED_VERSION', '$version'", "NOODLED_VERSION', '$newVersion'" | Set-Content "$pluginDir\noodled.php"
$version = $newVersion

Write-Host "Packaging noodled-wp v$version" -ForegroundColor Cyan

# ── Build zip ──
$zipName = "$pluginName-$version.zip"
$zipPath = "$pluginDir\dist\$zipName"

if (!(Test-Path "$pluginDir\dist")) { New-Item -ItemType Directory -Path "$pluginDir\dist" | Out-Null }

# Remove old zip
if (Test-Path $zipPath) { Remove-Item $zipPath }

# Create zip excluding dev files
$exclude = @("dist", ".git", ".claude", ".env", "CLAUDE.md", "package.ps1", "*.zip")
$tempDir = "$env:TEMP\noodled-pkg"
if (Test-Path $tempDir) { Remove-Item $tempDir -Recurse -Force }
New-Item -ItemType Directory -Path "$tempDir\$pluginName" | Out-Null

# Copy files
Get-ChildItem $pluginDir -Recurse | Where-Object {
    $rel = $_.FullName.Replace($pluginDir, "").TrimStart("\")
    $skip = $false
    foreach ($ex in $exclude) {
        if ($rel -like "$ex*" -or $rel -like "*\$ex*") { $skip = $true; break }
    }
    -not $skip -and -not $_.PSIsContainer
} | ForEach-Object {
    $rel = $_.FullName.Replace($pluginDir, "").TrimStart("\")
    $dest = "$tempDir\$pluginName\$rel"
    $destDir = Split-Path $dest -Parent
    if (!(Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
    Copy-Item $_.FullName $dest
}

# Build the zip with forward-slash entry names. Compress-Archive / .NET on
# Windows write backslash separators, which WordPress extracts on Linux as a
# literal file "noodled\noodled.php" instead of a folder — causing the
# "plugin file does not exist" install error.
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null
$base = (Resolve-Path $tempDir).Path.TrimEnd("\") + "\"
$zip  = [System.IO.Compression.ZipFile]::Open($zipPath, "Create")
try {
    Get-ChildItem -Path "$tempDir\$pluginName" -Recurse -File | ForEach-Object {
        $entry = $_.FullName.Substring($base.Length).Replace("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry) | Out-Null
    }
} finally {
    $zip.Dispose()
}
Remove-Item $tempDir -Recurse -Force

$size = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "Created $zipName ($size KB)" -ForegroundColor Green

# ── Copy to Versions and Production ──
$versionsDir = "$env:USERPROFILE\Documents\GitHub\Versions\Noodled"
$productionDir = "$env:USERPROFILE\Documents\GitHub\Production"

if (!(Test-Path $versionsDir)) { New-Item -ItemType Directory -Path $versionsDir | Out-Null }
if (!(Test-Path $productionDir)) { New-Item -ItemType Directory -Path $productionDir | Out-Null }

# Versioned copy (e.g. noodled-1.1.1.zip)
Copy-Item $zipPath "$versionsDir\$zipName"
Write-Host "Stored version: $versionsDir\$zipName" -ForegroundColor Green

# Production copy (always noodled.zip)
Copy-Item $zipPath "$productionDir\noodled.zip" -Force
Write-Host "Production: $productionDir\noodled.zip" -ForegroundColor Green

# ── Update metadata.json version ──
$metaFile = "$pluginDir\metadata.json"
if (Test-Path $metaFile) {
    $metaRaw = Get-Content $metaFile -Raw
    $metaRaw = $metaRaw -replace '"version":\s*"[^"]*"', "`"version`": `"$version`""
    # Keep the changelog's leading heading in step with the version so the WP
    # "what's new" modal never shows a stale version number.
    $metaRaw = $metaRaw -replace '<h4>[^<]*</h4>', "<h4>$version</h4>"
    $metaRaw | Set-Content $metaFile
    Write-Host "Updated metadata.json to v$version" -ForegroundColor Green
}

# ── Upload to c7.ca update server ──
$c7Env = "$env:USERPROFILE\Documents\GitHub\C7\1 Base Plugin\.env"
if (Test-Path $c7Env) {
    $c7Host = ""; $c7User = ""; $c7Pass = ""
    Get-Content $c7Env | ForEach-Object {
        if ($_ -match "^FTP_HOST=(.+)") { $c7Host = $Matches[1].Trim() }
        if ($_ -match "^FTP_USER=(.+)") { $c7User = $Matches[1].Trim() }
        if ($_ -match "^FTP_PASS=(.+)") { $c7Pass = $Matches[1].Trim() }
    }
    if ($c7Host -and $c7User -and $c7Pass) {
        Write-Host "Uploading to c7.ca update server..." -ForegroundColor Cyan
        $c7Cred = New-Object System.Net.NetworkCredential($c7User, $c7Pass)
        $c7Base = "ftp://$c7Host/noodled"

        # Create directory
        try {
            $mkReq = [System.Net.FtpWebRequest]::Create($c7Base)
            $mkReq.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $mkReq.Credentials = $c7Cred
            $mkReq.UsePassive = $true
            $mkReq.GetResponse().Close()
        } catch { }

        # Upload metadata.json
        try {
            $ulReq = [System.Net.FtpWebRequest]::Create("$c7Base/metadata.json")
            $ulReq.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
            $ulReq.Credentials = $c7Cred
            $ulReq.UseBinary = $true
            $ulReq.UsePassive = $true
            $bytes = [System.IO.File]::ReadAllBytes($metaFile)
            $s = $ulReq.GetRequestStream()
            $s.Write($bytes, 0, $bytes.Length)
            $s.Close()
            $ulReq.GetResponse().Close()
            Write-Host "  metadata.json uploaded" -ForegroundColor Green
        } catch {
            Write-Warning "Failed to upload metadata.json: $_"
        }

        # Upload zip
        try {
            $ulReq = [System.Net.FtpWebRequest]::Create("$c7Base/noodled.zip")
            $ulReq.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
            $ulReq.Credentials = $c7Cred
            $ulReq.UseBinary = $true
            $ulReq.UsePassive = $true
            $bytes = [System.IO.File]::ReadAllBytes($zipPath)
            $s = $ulReq.GetRequestStream()
            $s.Write($bytes, 0, $bytes.Length)
            $s.Close()
            $ulReq.GetResponse().Close()
            Write-Host "  noodled.zip uploaded" -ForegroundColor Green
        } catch {
            Write-Warning "Failed to upload noodled.zip: $_"
        }
    }
}

# ── Deploy via FTP ──
if ($Deploy) {
    if (!$FtpHost -or !$FtpUser -or !$FtpPass) {
        # Try .env file
        if (Test-Path "$pluginDir\.env") {
            Get-Content "$pluginDir\.env" | ForEach-Object {
                if ($_ -match "^FTP_HOST=(.+)") { $FtpHost = $Matches[1].Trim() }
                if ($_ -match "^FTP_USER=(.+)") { $FtpUser = $Matches[1].Trim() }
                if ($_ -match "^FTP_PASS=(.+)") { $FtpPass = $Matches[1].Trim() }
                if ($_ -match "^FTP_PATH=(.+)") { $FtpPath = $Matches[1].Trim() }
            }
        }
    }

    if (!$FtpHost -or !$FtpUser -or !$FtpPass) {
        Write-Error "FTP credentials required. Use -FtpHost, -FtpUser, -FtpPass or create .env file"
        exit 1
    }

    $FtpPath = $FtpPath.TrimEnd("/")   # trailing slash → "noodled//file" double slash, which some FTP servers reject (553)
    Write-Host "Deploying to $FtpHost$FtpPath ..." -ForegroundColor Cyan
    $cred = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
    $ftpBase = "ftp://$FtpHost$FtpPath"

    # Ensure the base plugin directory exists — on a fresh install it won't,
    # and the per-file loop below never creates the top-level folder.
    try {
        $baseReq = [System.Net.FtpWebRequest]::Create($ftpBase)
        $baseReq.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $baseReq.Credentials = $cred
        $baseReq.UsePassive = $true
        $baseReq.GetResponse().Close()
        Write-Host "Created base directory" -ForegroundColor Green
    } catch { }

    # Upload all plugin files
    $files = Get-ChildItem "$pluginDir" -Recurse -File | Where-Object {
        $rel = $_.FullName.Replace($pluginDir, "").TrimStart("\")
        $skip = $false
        foreach ($ex in $exclude) {
            if ($rel -like "$ex*" -or $rel -like "*\$ex*") { $skip = $true; break }
        }
        -not $skip
    }

    $uploaded = 0
    foreach ($f in $files) {
        $rel = $f.FullName.Replace($pluginDir, "").TrimStart("\").Replace("\", "/")
        $ftpUrl = "$ftpBase/$rel"

        # Create directories
        $parts = $rel.Split("/")
        $dirPath = $ftpBase
        for ($i = 0; $i -lt $parts.Length - 1; $i++) {
            $dirPath += "/" + $parts[$i]
            try {
                $mkReq = [System.Net.FtpWebRequest]::Create($dirPath)
                $mkReq.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
                $mkReq.Credentials = $cred
                $mkReq.UsePassive = $true
                $mkReq.GetResponse().Close()
            } catch { }
        }

        # Upload file
        try {
            $ulReq = [System.Net.FtpWebRequest]::Create($ftpUrl)
            $ulReq.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
            $ulReq.Credentials = $cred
            $ulReq.UseBinary = $true
            $ulReq.UsePassive = $true
            $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
            $s = $ulReq.GetRequestStream()
            $s.Write($bytes, 0, $bytes.Length)
            $s.Close()
            $ulReq.GetResponse().Close()
            $uploaded++
        } catch {
            Write-Warning "Failed: $rel - $_"
        }
    }

    Write-Host "Uploaded $uploaded files" -ForegroundColor Green

    # ── Flush OPcache so the new code runs immediately ──
    # SiteGround caches compiled PHP and ignores file mtimes, so an FTP deploy
    # otherwise keeps executing the old bytecode until PHP-FPM restarts. Drop a
    # one-shot reset script in the web root, hit it, delete it.
    $siteRoot = ($FtpPath -split '/public_html')[0]
    $domain   = $siteRoot.Trim('/')
    if ($domain) {
        $resetFtp = "ftp://$FtpHost$siteRoot/public_html/_ocreset.php"
        $php = '<?php if(function_exists("opcache_reset")){opcache_reset();} echo "ok";'
        try {
            $up = [System.Net.FtpWebRequest]::Create($resetFtp)
            $up.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
            $up.Credentials = $cred; $up.UsePassive = $true; $up.UseBinary = $true
            $b = [System.Text.Encoding]::ASCII.GetBytes($php)
            $s = $up.GetRequestStream(); $s.Write($b, 0, $b.Length); $s.Close(); $up.GetResponse().Close()
            Invoke-WebRequest "https://$domain/_ocreset.php?cb=$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())" -TimeoutSec 20 -UseBasicParsing | Out-Null
            $del = [System.Net.FtpWebRequest]::Create($resetFtp)
            $del.Method = [System.Net.WebRequestMethods+Ftp]::DeleteFile; $del.Credentials = $cred; $del.UsePassive = $true
            $del.GetResponse().Close()
            Write-Host "Flushed OPcache" -ForegroundColor Green
        } catch { Write-Warning "OPcache flush skipped: $_" }
    }
}

Write-Host ""
Write-Host "Done! noodled-wp v$version" -ForegroundColor Cyan
if (!$Deploy) {
    Write-Host "Zip: $zipPath" -ForegroundColor Yellow
    Write-Host "To deploy: .\package.ps1 -Deploy -FtpHost your.host -FtpUser user -FtpPass pass" -ForegroundColor Yellow
    Write-Host "Or create .env with FTP_HOST, FTP_USER, FTP_PASS, FTP_PATH" -ForegroundColor Yellow
}
