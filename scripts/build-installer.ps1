param(
    [string]$Output = (Join-Path (Split-Path -Parent $PSScriptRoot) 'SoundMatic-Setup-v1.1.102.exe'),
    [string]$PublishDirectory = (Join-Path $PSScriptRoot 'installer\bin\publish-v1.1.102'),
    [string]$WorkerDistDirectory = (Join-Path (Split-Path -Parent $PSScriptRoot) 'automation-worker\dist'),
    [string]$StageDirectory = (Join-Path $PSScriptRoot 'installer\stage')
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$stage = $StageDirectory
$installer = Join-Path $PSScriptRoot 'installer'
$sevenZip = 'C:\Program Files\7-Zip\7z.exe'
$sevenZipLibrary = 'C:\Program Files\7-Zip\7z.dll'
if (!(Test-Path $sevenZip) -or !(Test-Path $sevenZipLibrary)) { throw '7-Zip beserta 7z.dll diperlukan untuk membuat installer.' }

# Always package the current frontend and worker sources, not an earlier build.
Push-Location $root
try {
    & npm.cmd run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
    if (! $PSBoundParameters.ContainsKey('WorkerDistDirectory')) {
        Push-Location (Join-Path $root 'automation-worker')
        try {
            # A running Node worker can keep files in dist busy on Windows.
            # Compile into a fresh directory so packaging never rewrites them.
            $WorkerDistDirectory = Join-Path $root ('automation-worker\storage\installer-builds\' + [guid]::NewGuid().ToString('N'))
            & npm.cmd run build -- --outDir $WorkerDistDirectory
            if ($LASTEXITCODE -ne 0) { throw 'Automation worker build failed.' }
        } finally { Pop-Location }
    }
} finally { Pop-Location }
if (!(Test-Path -LiteralPath (Join-Path $WorkerDistDirectory 'server.js'))) { throw 'Automation worker server.js was not created.' }
if (!(Test-Path -LiteralPath (Join-Path $root 'public\build\manifest.json'))) { throw 'Frontend manifest was not created.' }

$launcherProject = Join-Path $PSScriptRoot 'desktop\SoundOnMatic.Desktop.csproj'
# Publish separately because a running desktop app can lock its executable.
$launcherPublishDirectory = Join-Path $PSScriptRoot ('desktop\bin\installer-builds\' + [guid]::NewGuid().ToString('N'))
dotnet publish $launcherProject -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:DebugType=None -p:DebugSymbols=false -o $launcherPublishDirectory
if ($LASTEXITCODE -ne 0) { throw 'Desktop launcher build failed.' }
$launcherExe = Join-Path $launcherPublishDirectory 'SoundMatic.exe'
if (!(Test-Path -LiteralPath $launcherExe)) { throw 'Desktop launcher output was not created.' }

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
$errorDataDirectory = Join-Path $stage 'data error'
New-Item -Path $errorDataDirectory -ItemType Directory -Force | Out-Null
# Only ship instructions; empty log files would overwrite existing error history.
Copy-Item -LiteralPath (Join-Path $root 'resources\diagnostics\BACA SAYA.txt') -Destination $errorDataDirectory
Copy-Item $launcherExe (Join-Path $stage 'SoundMatic.exe')

$workerStage = Join-Path $stage 'automation-worker'; New-Item $workerStage -ItemType Directory | Out-Null
foreach ($dir in @('config','node_modules')) { Copy-Item (Join-Path $root "automation-worker\$dir") $workerStage -Recurse -Force }
if (!(Test-Path -LiteralPath $WorkerDistDirectory)) { throw "Automation worker distribution not found: $WorkerDistDirectory" }
Copy-Item -LiteralPath $WorkerDistDirectory -Destination (Join-Path $workerStage 'dist') -Recurse -Force
# Compiled tests are development artifacts.
Get-ChildItem -LiteralPath (Join-Path $workerStage 'dist') -Recurse -File -Filter '*.test.js' | Remove-Item -Force
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
# Machine-specific config/routes must be regenerated on the installed machine.
Get-ChildItem -LiteralPath (Join-Path $stage 'bootstrap\cache') -File -Filter '*.php' | Remove-Item -Force
# Database state is local user data, never application payload. In particular,
# a copied WAL file without its matching database makes a first installation
# appear corrupt before Laravel can create its schema.
$stageDatabase = Join-Path $stage 'database'
Get-ChildItem -LiteralPath $stageDatabase -File -Filter 'database.sqlite*' | Remove-Item -Force

$payload = Join-Path $installer 'payload.7z'
if (Test-Path $payload) { Remove-Item $payload -Force }
& $sevenZip a -t7z -mx=5 -mmt=on $payload (Join-Path $stage '*') | Out-Host
if ($LASTEXITCODE -ne 0) { throw 'Installer payload compression failed.' }
Copy-Item $sevenZip (Join-Path $installer '7z.exe') -Force
Copy-Item $sevenZipLibrary (Join-Path $installer '7z.dll') -Force
dotnet publish (Join-Path $installer 'SoundOnMatic.Installer.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:DebugType=None -p:DebugSymbols=false -o $PublishDirectory
if ($LASTEXITCODE -ne 0) { throw 'Installer build failed.' }
Copy-Item (Join-Path $PublishDirectory 'SoundMatic-Setup.exe') $Output -Force
Write-Output $Output
