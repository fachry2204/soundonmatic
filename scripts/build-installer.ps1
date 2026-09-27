param([string]$Output = (Join-Path (Split-Path -Parent $PSScriptRoot) 'SoundMatic-Setup-v1.1.49.exe'))
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$stage = Join-Path $PSScriptRoot 'installer\stage'
$installer = Join-Path $PSScriptRoot 'installer'
$sevenZip = 'C:\Program Files\7-Zip\7z.exe'
$sevenZipLibrary = 'C:\Program Files\7-Zip\7z.dll'
if (!(Test-Path $sevenZip) -or !(Test-Path $sevenZipLibrary)) { throw '7-Zip beserta 7z.dll diperlukan untuk membuat installer.' }

$launcherExe = Join-Path $root 'SoundMatic-v1.1.49-portable.exe'
if (!(Test-Path $launcherExe)) {
    Write-Host "$launcherExe belum ada, membangun..."
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\build-exe.ps1') -Output $launcherExe
    if (!(Test-Path $launcherExe)) { throw 'Gagal membangun launcher.' }
}

if (Test-Path -LiteralPath $stage) {
    $resolvedRoot = (Resolve-Path -LiteralPath $root).Path
    $resolvedStage = (Resolve-Path -LiteralPath $stage).Path
    $expectedStagePrefix = Join-Path $resolvedRoot 'scripts\installer\'
    if (! $resolvedStage.StartsWith($expectedStagePrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to clear an unexpected installer stage path: $resolvedStage"
    }
    Remove-Item -LiteralPath $resolvedStage -Recurse -Force
}
New-Item $stage -ItemType Directory | Out-Null

$dirs = @('app','bootstrap','config','database','public','resources','routes','vendor')
foreach ($dir in $dirs) { Copy-Item (Join-Path $root $dir) $stage -Recurse -Force }
# `public/hot` is created only while the Vite development server is running.
# Shipping it makes Laravel request CSS/JS from localhost:5173 instead of the
# compiled assets bundled in public/build, leaving the desktop UI unstyled.
$hotFile = Join-Path $stage 'public\hot'
if (Test-Path -LiteralPath $hotFile) { Remove-Item -LiteralPath $hotFile -Force }
Copy-Item (Join-Path $root 'artisan') $stage
Copy-Item (Join-Path $root 'composer.json') $stage
Copy-Item (Join-Path $root '.env.install') $stage
Copy-Item $launcherExe (Join-Path $stage 'SoundMatic.exe')

$workerStage = Join-Path $stage 'automation-worker'; New-Item $workerStage -ItemType Directory | Out-Null
foreach ($dir in @('config','dist','node_modules')) { Copy-Item (Join-Path $root "automation-worker\$dir") $workerStage -Recurse -Force }
Copy-Item (Join-Path $root 'automation-worker\.env.install') $workerStage
Copy-Item (Join-Path $root 'automation-worker\package.json') $workerStage

$phpStage = Join-Path $stage 'runtime\php'; New-Item $phpStage -ItemType Directory -Force | Out-Null
Copy-Item 'D:\xampp\php\*' $phpStage -Recurse -Force
$certificateStage = Join-Path $phpStage 'extras\ssl'; New-Item $certificateStage -ItemType Directory -Force | Out-Null
$certificateSource = 'D:\xampp\apache\bin\curl-ca-bundle.crt'
if (!(Test-Path $certificateSource)) { throw 'Bundel sertifikat CA PHP tidak ditemukan.' }
Copy-Item $certificateSource (Join-Path $certificateStage 'cacert.pem') -Force
$phpIni = Get-Content (Join-Path $phpStage 'php.ini') -Raw
$phpIni = $phpIni.Replace('D:\xampp\php\ext', 'ext').Replace('D:\xampp\php\PEAR', 'PEAR').Replace('D:\xampp\apache\bin\curl-ca-bundle.crt', 'extras\ssl\cacert.pem')
$phpIni = $phpIni.Replace('upload_tmp_dir="D:\xampp\tmp"', ';upload_tmp_dir=').Replace('session.save_path="D:\xampp\tmp"', ';session.save_path=').Replace('error_log="D:\xampp\php\logs\php_error_log"', 'error_log="php_errors.log"').Replace('browscap="extras\browscap.ini"', ';browscap=')
Set-Content (Join-Path $phpStage 'php.ini') $phpIni -Encoding UTF8

$nodeStage = Join-Path $stage 'runtime\node'; New-Item $nodeStage -ItemType Directory -Force | Out-Null
Copy-Item 'C:\Program Files\nodejs\node.exe' $nodeStage
$mediaStage = Join-Path $stage 'runtime\media'; New-Item $mediaStage -ItemType Directory -Force | Out-Null
$ffmpegBin = Get-ChildItem (Join-Path $installer 'ffmpeg-extract') -Recurse -Directory | Where-Object Name -eq 'bin' | Select-Object -First 1
Copy-Item (Join-Path $ffmpegBin.FullName 'ffmpeg.exe') $mediaStage
Copy-Item (Join-Path $ffmpegBin.FullName 'ffprobe.exe') $mediaStage

foreach ($dir in @('storage\logs','storage\framework\cache','storage\framework\sessions','storage\framework\views','storage\app\private','bootstrap\cache','database')) { New-Item (Join-Path $stage $dir) -ItemType Directory -Force | Out-Null }
# Database state is local user data, never application payload. In particular,
# a copied WAL file without its matching database makes a first installation
# appear corrupt before Laravel can create its schema.
$stageDatabase = Join-Path $stage 'database'
Get-ChildItem -LiteralPath $stageDatabase -File -Filter 'database.sqlite*' | Remove-Item -Force

$payload = Join-Path $installer 'payload.7z'
if (Test-Path $payload) { Remove-Item $payload -Force }
& $sevenZip a -t7z -mx=5 -mmt=on $payload (Join-Path $stage '*') | Out-Host
Copy-Item $sevenZip (Join-Path $installer '7z.exe') -Force
Copy-Item $sevenZipLibrary (Join-Path $installer '7z.dll') -Force
dotnet publish (Join-Path $installer 'SoundOnMatic.Installer.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:DebugType=None -p:DebugSymbols=false -o (Join-Path $installer 'bin\publish')
if ($LASTEXITCODE -ne 0) { throw 'Installer build failed.' }
Copy-Item (Join-Path $installer 'bin\publish\SoundMatic-Setup.exe') $Output -Force
Write-Output $Output
