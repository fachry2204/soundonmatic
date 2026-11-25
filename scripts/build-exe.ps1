param(
    [string]$Output = (Join-Path (Split-Path -Parent $PSScriptRoot) 'SoundMatic.exe')
)

$ErrorActionPreference = 'Stop'
$project = Join-Path $PSScriptRoot 'desktop\SoundOnMatic.Desktop.csproj'

$publish = Join-Path $PSScriptRoot ('desktop\bin\standalone-builds\' + [guid]::NewGuid().ToString('N'))
dotnet publish $project -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:DebugType=None -p:DebugSymbols=false -o $publish
if ($LASTEXITCODE -ne 0) { throw 'Desktop build failed.' }
Copy-Item -LiteralPath (Join-Path $publish 'SoundMatic.exe') -Destination $Output -Force

Write-Output $Output
