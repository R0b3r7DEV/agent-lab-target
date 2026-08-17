# Arranque LOCAL del lab (sin Docker) para el disparo real del harness.
#
# Levanta PostgreSQL (scoop) + php -S en un comando, idempotente. Ligero: un proceso de BD
# + un servidor PHP embebido. La inferencia del LLM corre en la NUBE de Anthropic, no aqui.
#
# Uso (PowerShell, desde cualquier sitio):
#   .\scripts\run-local.ps1
#
# Para el ataque, en OTRA terminal (repo del harness):
#   .\.venv\Scripts\python -m harness.cli run --smoke --base-url http://localhost:8000
#
# SOLO LOCAL / RED AISLADA. Entorno deliberadamente vulnerable: nunca lo expongas.

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent $PSScriptRoot
$data = Join-Path $repo 'var\pgdata'
$port = 5432
# El script FUERZA la BD local: asi .env.local solo necesita la ANTHROPIC_API_KEY.
$env:DATABASE_URL = "postgresql://app:app@127.0.0.1:$port/lab?serverVersion=18&charset=utf8"
$env:APP_ENV = 'prod'    # prod = sin profiler que vuelque `$_ENV` con la key (ADR 11)
$env:APP_DEBUG = '0'

# 1) Localizar PostgreSQL (scoop, con fallback al PATH).
$pgbin = Join-Path $env:USERPROFILE 'scoop\apps\postgresql\current\bin'
if (-not (Test-Path (Join-Path $pgbin 'postgres.exe'))) {
    $pg = Get-Command postgres -ErrorAction SilentlyContinue
    if ($pg) { $pgbin = Split-Path $pg.Source }
    else { throw "No encuentro PostgreSQL. Instala con: scoop install postgresql" }
}

function Invoke-Pg([string]$exe, [string[]]$pgArgs) { & (Join-Path $pgbin $exe) @pgArgs }

# 2) Arrancar PG si no escucha (initdb la primera vez).
$listening = Test-NetConnection -ComputerName 127.0.0.1 -Port $port -InformationLevel Quiet -WarningAction SilentlyContinue
if (-not $listening) {
    if (-not (Test-Path (Join-Path $data 'PG_VERSION'))) {
        Write-Host '== initdb (primera vez; BD local en var/pgdata) ==' -ForegroundColor Cyan
        Invoke-Pg 'initdb.exe' @('-D', $data, '-U', 'app', '-A', 'trust', '--encoding=UTF8') | Out-Null
    }
    Write-Host '== arrancando PostgreSQL ==' -ForegroundColor Cyan
    Start-Process -FilePath (Join-Path $pgbin 'postgres.exe') -ArgumentList "-D `"$data`" -p $port" -WindowStyle Hidden
    for ($i = 0; $i -lt 40; $i++) {
        Invoke-Pg 'pg_isready.exe' @('-h', '127.0.0.1', '-p', "$port", '-q')
        if ($?) { break }
        Start-Sleep -Milliseconds 500
    }
}

# 3) Crear la BD `lab` si falta.
$exists = Invoke-Pg 'psql.exe' @('-U', 'app', '-h', '127.0.0.1', '-p', "$port", '-tAc', "SELECT 1 FROM pg_database WHERE datname='lab'")
if (-not ("$exists".Trim() -eq '1')) {
    Write-Host '== creando base de datos lab ==' -ForegroundColor Cyan
    Invoke-Pg 'createdb.exe' @('-U', 'app', '-h', '127.0.0.1', '-p', "$port", 'lab')
}

Push-Location $repo
try {
    # 4) Migraciones + cache de prod.
    Write-Host '== migraciones ==' -ForegroundColor Cyan
    php bin/console doctrine:migrations:migrate --no-interaction
    php bin/console cache:clear --env=prod | Out-Null

    # 5) Aviso de la API key (el target la lee; el harness JAMAS la ve).
    $hasKey = Test-Path (Join-Path $repo '.env.local')
    if ($hasKey) { $hasKey = Select-String -Path (Join-Path $repo '.env.local') -Pattern 'ANTHROPIC_API_KEY=sk-' -Quiet }
    if (-not $hasKey) {
        Write-Warning "Falta ANTHROPIC_API_KEY=sk-... en .env.local. Usa una key DEDICADA del lab con TOPE de gasto (ADR 11):"
        Write-Warning "  echo 'ANTHROPIC_API_KEY=sk-ant-...' > .env.local"
    }

    # 6) Servir (foreground; Ctrl+C para parar). max_execution_time alto por el bucle de tools.
    Write-Host '== target listo en http://localhost:8000 (APP_ENV=prod). Ctrl+C para parar. ==' -ForegroundColor Green
    php -d max_execution_time=180 -S 127.0.0.1:8000 -t public
}
finally {
    Pop-Location
    Write-Host 'Servidor parado. PostgreSQL sigue vivo; para pararlo: Get-Process postgres | Stop-Process' -ForegroundColor DarkGray
}
