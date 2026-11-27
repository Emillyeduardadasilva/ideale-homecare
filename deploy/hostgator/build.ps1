$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$output = Join-Path $repo 'dist-hostgator'
New-Item -ItemType Directory -Force -Path $output | Out-Null

# O build usa o próprio domínio e restaura as variáveis locais ao terminar.
$previousApi = $env:VITE_API_BASE_URL
$previousReverb = $env:VITE_REVERB_APP_KEY
try {
    $env:VITE_API_BASE_URL = '/api/v1'
    $env:VITE_REVERB_APP_KEY = ''
    Push-Location (Join-Path $repo 'frontend')
    try {
        if (-not (Test-Path -LiteralPath 'node_modules')) {
            & npm.cmd ci --no-audit --no-fund
            if ($LASTEXITCODE -ne 0) { throw 'Falha ao instalar as dependências Vue.' }
        }
        & npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Falha ao compilar a interface.' }
    } finally { Pop-Location }

    & docker build -f (Join-Path $PSScriptRoot 'Dockerfile.build') -t ideale-homecare-package:php8.5 $PSScriptRoot
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao preparar o empacotador.' }
    & docker run --rm --env COMPOSER_ALLOW_SUPERUSER=1 --mount "type=bind,source=$repo,target=/source,readonly" --mount "type=bind,source=$output,target=/output" ideale-homecare-package:php8.5
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao gerar o pacote HostGator.' }
    Write-Output (Join-Path $output 'ideale-homecare-hostgator.zip')
} finally {
    $env:VITE_API_BASE_URL = $previousApi
    $env:VITE_REVERB_APP_KEY = $previousReverb
}
