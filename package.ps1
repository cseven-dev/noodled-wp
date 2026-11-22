# noodled-wp package & deploy script
# Creates a zip for manual upload or deploys over SSH with WP-CLI

param(
    [switch]$Deploy,
    [string]$SshHost = "noodled",
    [string]$WpPath = "/home/u238-y0vgdp3dveah/www/noodled.ca/public_html"
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

# ── Minify assets ──
# JS uses whitespace+syntax minify only (NOT identifier renaming): the app refers
# to ~200 global functions by name via inline onclick handlers, so renaming breaks
# it. CSS gets a full minify.
if (Get-Command npx -ErrorAction SilentlyContinue) {
    $jsSrc  = Join-Path $pluginDir "assets\js\noodled.js"
    $jsMin  = Join-Path $pluginDir "assets\js\noodled.min.js"
    $cssSrc = Join-Path $pluginDir "assets\css\noodled.css"
    $cssMin = Join-Path $pluginDir "assets\css\noodled.min.css"
    $admSrc = Join-Path $pluginDir "assets\css\admin.css"
    $admMin = Join-Path $pluginDir "assets\css\admin.min.css"
    & npx --yes esbuild $jsSrc --minify-whitespace --minify-syntax --target=es2019 "--outfile=$jsMin"  | Out-Null
    & npx --yes esbuild $cssSrc --minify "--outfile=$cssMin" | Out-Null
    & npx --yes esbuild $admSrc --minify "--outfile=$admMin" | Out-Null
    Write-Host "Minified assets" -ForegroundColor Green
} else {
    Write-Host "npx not found - shipping source assets (the PHP loader falls back automatically)" -ForegroundColor Yellow
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
$exclude = @("dist", ".git", ".claude", ".env", "CLAUDE.md", "package.ps1", "*.zip", "COACH.md", "ROADMAP.md", "SPEC.md", "MOBILE-IOS-SCOPE.md", "tests", "noodled_dropbox.json")
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
    # Keep the changelog's LEADING heading in step with the version so the WP
    # "what's new" modal never shows a stale version number. Only the first <h4>
    # is rewritten (the current release); older version headings are left alone.
    $metaRaw = ([regex]'<h4>[^<]*</h4>').Replace($metaRaw, "<h4>$version</h4>", 1)
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

# ── Deploy via SSH ──
# Copies the zip to the server and installs it with WP-CLI. This replaces the
# plugin folder (no stale files left behind) and needs no stored password: it
# uses the SSH key behind the host alias in ~/.ssh/config.
if ($Deploy) {
    $remoteZip = "/tmp/$zipName"
    Write-Host "Deploying to ${SshHost}:$WpPath ..." -ForegroundColor Cyan

    & scp -q "$zipPath" "${SshHost}:$remoteZip"
    if ($LASTEXITCODE -ne 0) { Write-Error "Deploy failed: could not copy the zip to $SshHost"; exit 1 }

    $live = & ssh $SshHost "cd '$WpPath' && wp plugin install '$remoteZip' --force --quiet; rm -f '$remoteZip'; wp plugin get $pluginName --field=version"
    $live = "$live".Trim()
    if ($live -ne $version) { Write-Error "Deploy failed: live site reports '$live', expected $version"; exit 1 }
    Write-Host "Live site is on v$live" -ForegroundColor Green
}

Write-Host ""
Write-Host "Done! noodled-wp v$version" -ForegroundColor Cyan
if (!$Deploy) {
    Write-Host "Zip: $zipPath" -ForegroundColor Yellow
    Write-Host "To deploy: .\package.ps1 -Deploy" -ForegroundColor Yellow
}
