<#
.SYNOPSIS
    Despliega BMOS en Vercel con un output PREBUILT construido en Linux.

.DESCRIPTION
    Los deploys que Vercel construye en la nube salen "Ready" pero rotos (500 en todas las rutas) por un
    choque entre su bootstrap nuevo y el runtime vercel-php. Este camino construye en un contenedor,
    corrige la config de la función y sube el resultado. Explicación completa y flujo seguro en
    docs/DEPLOY_VERCEL_PREBUILT.md.

    Despliega HEAD (lo versionado), no el árbol de trabajo.

.PARAMETER Accion
    build   construye y guarda el output en un volumen de Docker.
    deploy  corrige la config y despliega el output ya construido.
    todo    build + deploy.

.PARAMETER Prod
    Construye y despliega para producción. Sin este parámetro es un preview.

.PARAMETER DiagnosticoPuente
    Añade al launcher puente un registro de la forma de cada petición (nunca cuerpos ni cabeceras).

.EXAMPLE
    .\scripts\vercel-prebuilt\run.ps1 todo            # preview
    .\scripts\vercel-prebuilt\run.ps1 todo -Prod      # producción (sin publicar: ver el doc)
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory, Position = 0)][ValidateSet('build', 'deploy', 'todo')][string]$Accion,
    [switch]$Prod,
    [switch]$DiagnosticoPuente,
    [ValidateSet('array', 'cookie')][string]$Sesion = 'array',
    [string]$SesionCli = (Join-Path $env:APPDATA 'xdg.data\com.vercel.cli'),
    [string]$Imagen = 'node:22'
)

$ErrorActionPreference = 'Stop'

$Raiz = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ProyectoVercel = Join-Path $Raiz '.vercel'
$Trabajo = Join-Path $env:TEMP 'bmos-vercel-prebuilt'
$Volumen = 'bmos-vercel-prebuilt'

function Falla([string]$Mensaje) {
    Write-Host "ERROR: $Mensaje" -ForegroundColor Red
    exit 1
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Falla 'Docker no está instalado o no está en el PATH.' }
if (-not (Test-Path $SesionCli)) { Falla "No encuentro la sesión del CLI de Vercel en $SesionCli. Ejecuta 'vercel login'." }
if (-not (Test-Path (Join-Path $ProyectoVercel 'project.json'))) { Falla "Falta .vercel\project.json. Vincula el proyecto con 'vercel link'." }

if (git -C $Raiz status --porcelain) {
    Write-Host 'AVISO: hay cambios sin commit. Se despliega HEAD (lo versionado), no tu árbol de trabajo.' -ForegroundColor Yellow
}

# El token del CLI dura poco: refrescarlo en el host justo antes evita que el contenedor tenga que hacerlo.
function RefrescarSesion {
    if (Get-Command vercel -ErrorAction SilentlyContinue) { vercel whoami | Out-Null }
}

function Construir {
    New-Item -ItemType Directory -Force -Path $Trabajo | Out-Null
    git -C $Raiz archive --format=tar -o (Join-Path $Trabajo 'src.tar') HEAD
    if ($LASTEXITCODE -ne 0) { Falla 'git archive falló.' }

    $target = if ($Prod) { 'production' } else { 'preview' }
    Write-Host "Construyendo (target: $target)..." -ForegroundColor Cyan
    RefrescarSesion
    docker run --rm -e "TARGET=$target" `
        -v "${PSScriptRoot}:/scripts:ro" -v "${Trabajo}:/src:ro" `
        -v "${SesionCli}:/vc-src:ro" -v "${ProyectoVercel}:/vcproj:ro" `
        -v "${Volumen}:/data" $Imagen bash /scripts/build.sh
    if ($LASTEXITCODE -ne 0) { Falla 'El build falló.' }
}

function Desplegar {
    $destino = if ($Prod) { 'PRODUCCIÓN' } else { 'preview' }
    Write-Host "Desplegando ($destino)..." -ForegroundColor Cyan
    RefrescarSesion
    docker run --rm `
        -e "PROD=$([int]$Prod.IsPresent)" -e "SESS=$Sesion" -e "BRIDGE_DEBUG=$([int]$DiagnosticoPuente.IsPresent)" `
        -v "${PSScriptRoot}:/scripts:ro" -v "${SesionCli}:/vc-src:ro" `
        -v "${Volumen}:/data" $Imagen bash /scripts/deploy.sh | Tee-Object -Variable capturado | Out-Host
    if ($LASTEXITCODE -ne 0) { Falla 'El deploy falló.' }

    $url = $capturado | Select-String -Pattern 'https://[a-z0-9-]+\.vercel\.app' | Select-Object -First 1
    if (-not $url) { Falla 'No encontré la URL del deploy en la salida.' }
    $direccion = $url.Matches[0].Value

    Write-Host ''
    Write-Host "Deploy creado: $direccion" -ForegroundColor Green
    Write-Host 'Siguiente, SIN publicarlo todavía:' -ForegroundColor Cyan
    Write-Host "  vercel curl /up --deployment $direccion --yes -- -sS -i     (en Git Bash: prefijar MSYS_NO_PATHCONV=1)"
    if ($Prod) {
        Write-Host '  Si responde bien, publicarlo:'
        Write-Host "  vercel promote $direccion --yes"
    }
}

switch ($Accion) {
    'build'  { Construir }
    'deploy' { Desplegar }
    'todo'   { Construir; Desplegar }
}
