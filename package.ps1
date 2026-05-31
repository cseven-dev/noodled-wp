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

Write-Host "Packaging noodled-wp v$version" -ForegroundColor Cyan

# ── Build zip ──
$zipName = "$pluginName-$version.zip"
$zipPath = "$pluginDir\dist\$zipName"

if (!(Test-Path "$pluginDir\dist")) { New-Item -ItemType Directory -Path "$pluginDir\dist" | Out-Null }

# Remove old zip
if (Test-Path $zipPath) { Remove-Item $zipPath }

# Create zip excluding dev files
$exclude = @("dist", ".git", ".env", "CLAUDE.md", "package.ps1", "*.zip")
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

Compress-Archive -Path "$tempDir\$pluginName" -DestinationPath $zipPath -Force
Remove-Item $tempDir -Recurse -Force

$size = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "Created $zipName ($size KB)" -ForegroundColor Green

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

    Write-Host "Deploying to $FtpHost$FtpPath ..." -ForegroundColor Cyan
    $cred = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
    $ftpBase = "ftp://$FtpHost$FtpPath"

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
}

Write-Host ""
Write-Host "Done! noodled-wp v$version" -ForegroundColor Cyan
if (!$Deploy) {
    Write-Host "Zip: $zipPath" -ForegroundColor Yellow
    Write-Host "To deploy: .\package.ps1 -Deploy -FtpHost your.host -FtpUser user -FtpPass pass" -ForegroundColor Yellow
    Write-Host "Or create .env with FTP_HOST, FTP_USER, FTP_PASS, FTP_PATH" -ForegroundColor Yellow
}
