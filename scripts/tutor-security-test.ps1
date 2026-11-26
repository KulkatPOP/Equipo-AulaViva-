
param(
    [Parameter(Mandatory = $true)]
    [SecureString]$Password
)

$ErrorActionPreference = "Stop"

$baseUrl = "http://localhost:8083"
$usuario = "alumno.demo"
$fecha = Get-Date -Format "yyyyMMdd-HHmmss"
$carpeta = Join-Path $PSScriptRoot "../docs/ai/evidencias"

New-Item -ItemType Directory -Path $carpeta -Force | Out-Null

if ($Password.Length -eq 0) {
    throw "La contraseña no puede estar vacía."
}

Add-Type -AssemblyName System.Net.Http

$handler = New-Object System.Net.Http.HttpClientHandler
$handler.AllowAutoRedirect = $false
$handler.UseCookies = $false

$cliente = New-Object System.Net.Http.HttpClient($handler)
$cliente.Timeout = [TimeSpan]::FromSeconds(90)

function Set-CookieSesion {
    param($Respuesta)

    $cookies = $Respuesta.Headers.GetValues("Set-Cookie") -join "; "
    $sesion = [regex]::Match($cookies, 'PHPSESSID=([^;\s]+)')

    if (-not $sesion.Success) {
        throw "No se recibió la cookie de sesión."
    }

    $cliente.DefaultRequestHeaders.Remove("Cookie") | Out-Null
    $cliente.DefaultRequestHeaders.TryAddWithoutValidation(
        "Cookie", $sesion.Value
    ) | Out-Null
}

function Consultar-Tutor {
    param([int]$AulaId)

    $json = @{
        aula_id = $AulaId
        pregunta = "¿Cuántos vasos requiere el experimento?"
    } | ConvertTo-Json -Compress

    $contenido = New-Object System.Net.Http.StringContent(
        $json,
        [System.Text.Encoding]::UTF8,
        "application/json"
    )

    try {
        $respuesta = $cliente.PostAsync(
            "$baseUrl/tutor_ask.php", $contenido
        ).GetAwaiter().GetResult()

        try {
            $texto = $respuesta.Content.ReadAsStringAsync().GetAwaiter().GetResult()
            $datos = $null

            try {
                $datos = $texto | ConvertFrom-Json
            }
            catch {
                # Una redirección puede no contener JSON.
            }

            return [PSCustomObject]@{
                http = [int]$respuesta.StatusCode
                estado = [string]$datos.status
                respuesta = [string]$datos.answer
                citas = @(
                    $datos.citations |
                    Where-Object { $null -ne $_ }
                )
                redireccion = [string]$respuesta.Headers.Location
            }
        }
        finally {
            $respuesta.Dispose()
        }
    }
    finally {
        $contenido.Dispose()
    }
}

function Consultar-Material {
    param([int]$MaterialId)

    $respuesta = $cliente.GetAsync(
        "$baseUrl/archivo_material.php?material_id=$MaterialId"
    ).GetAwaiter().GetResult()

    try {
        $tipo = [string]$respuesta.Content.Headers.ContentType
        $bytes = $respuesta.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()

        $esPdf = $false

        if ($bytes.Length -ge 5) {
            $inicio = [System.Text.Encoding]::ASCII.GetString(
                $bytes, 0, 5
            )

            $esPdf = $inicio -eq "%PDF-"
        }

        return [PSCustomObject]@{
            http = [int]$respuesta.StatusCode
            tipo = $tipo
            es_pdf = $esPdf
            bytes = $bytes.Length
        }
    }
    finally {
        $respuesta.Dispose()
    }
}

function Nuevo-Resultado {
    param(
        [string]$Id,
        [string]$Prueba,
        [string]$Esperado,
        $Datos,
        [bool]$Aprobado
    )

    if ($Aprobado) {
        Write-Host "$Id - PASS: $Prueba" -ForegroundColor Green
    }
    else {
        Write-Host "$Id - FAIL: $Prueba" -ForegroundColor Red
    }

    Write-Host "HTTP: $($Datos.http)"

    return [PSCustomObject]@{
        id = $Id
        prueba = $Prueba
        esperado = $Esperado
        http = $Datos.http
        aprobado = $Aprobado
        detalle = $Datos
    }
}

try {
    Write-Host "=== AULAVIVA - PRUEBAS DE SEGURIDAD ===" -ForegroundColor Cyan
    Write-Host "Entorno: DEMO local"
    Write-Host "Usuario: $usuario"

    # Obtener formulario y token CSRF.

    $login = $cliente.GetAsync(
        "$baseUrl/login.php"
    ).GetAwaiter().GetResult()

    if ([int]$login.StatusCode -ne 200) {
        throw "No se pudo cargar el login."
    }

    $html = $login.Content.ReadAsStringAsync().GetAwaiter().GetResult()

    $csrf = [regex]::Match(
        $html,
        'name="csrf_token"\s+value="([^"]+)"'
    ).Groups[1].Value

    if (-not $csrf) {
        throw "No se encontró el token CSRF."
    }

    Set-CookieSesion $login
    $login.Dispose()

    # Autenticación del alumno DEMO.

    $credencial = New-Object System.Management.Automation.PSCredential(
        $usuario, $Password
    )

    $claveTemporal = $credencial.GetNetworkCredential().Password

    $campos = New-Object 'System.Collections.Generic.Dictionary[string,string]'

    $campos.Add("identificador", $usuario)
    $campos.Add("password", $claveTemporal)
    $campos.Add("csrf_token", $csrf)

    $formulario = New-Object System.Net.Http.FormUrlEncodedContent($campos)

    try {
        $respuestaLogin = $cliente.PostAsync(
            "$baseUrl/login.php", $formulario
        ).GetAwaiter().GetResult()
    }
    finally {
        $campos.Clear()
        $formulario.Dispose()
        Remove-Variable claveTemporal -ErrorAction SilentlyContinue
    }

    if (
        [int]$respuestaLogin.StatusCode -ne 302 -or
        [string]$respuestaLogin.Headers.Location -notmatch "mis_cursos\.php$"
    ) {
        throw "No se pudo autenticar al alumno DEMO."
    }

    Set-CookieSesion $respuestaLogin
    $respuestaLogin.Dispose()

    $verificacion = $cliente.GetAsync(
        "$baseUrl/mis_cursos.php"
    ).GetAwaiter().GetResult()

    if ([int]$verificacion.StatusCode -ne 200) {
        throw "No se pudo verificar la sesión."
    }

    $verificacion.Dispose()

    Write-Host "Autenticación correcta." -ForegroundColor Green

    $resultados = @()

    # SEC01: Aula autorizada.

    $r = Consultar-Tutor 1

    $citasCorrectas = (
        $r.citas.Count -gt 0 -and
        @(
            $r.citas | Where-Object {
                $_.fragment_id -ne 39 -or $_.page -ne 1
            }
        ).Count -eq 0
    )

    $ok = (
        $r.http -eq 200 -and
        $r.estado -eq "ANSWERED" -and
        $r.respuesta -match "\b4\s+vasos\b" -and
        $citasCorrectas
    )

    $resultados += Nuevo-Resultado `
        "SEC01" "Tutor aula autorizada" `
        "HTTP 200, respuesta correcta y citas del fragmento 39" $r $ok

    # SEC02: Aula restringida existente.

    $r = Consultar-Tutor 2

    $ok = (
        $r.http -eq 403 -and
        $r.estado -eq "INSUFFICIENT_EVIDENCE" -and
        $r.citas.Count -eq 0
    )

    $resultados += Nuevo-Resultado `
        "SEC02" "Tutor aula restringida" `
        "HTTP 403, sin citas" $r $ok

    # SEC03: Aula inexistente.

    $r = Consultar-Tutor 2147483647

    $ok = (
        $r.http -eq 403 -and
        $r.estado -eq "INSUFFICIENT_EVIDENCE" -and
        $r.citas.Count -eq 0
    )

    $resultados += Nuevo-Resultado `
        "SEC03" "Tutor aula inexistente" `
        "HTTP 403, sin citas" $r $ok

    # SEC04: Material autorizado.

    $r = Consultar-Material 3

    $ok = (
        $r.http -eq 200 -and
        $r.tipo -match "^application/pdf" -and
        $r.es_pdf
    )

    $resultados += Nuevo-Resultado `
        "SEC04" "Material autorizado" `
        "HTTP 200 y PDF válido en su cabecera" $r $ok

    # SEC05: Material restringido.

    $r = Consultar-Material 4

    $ok = (
        $r.http -eq 404 -and
        -not $r.es_pdf -and
        $r.tipo -notmatch "^application/pdf"
    )

    $resultados += Nuevo-Resultado `
        "SEC05" "Material restringido" `
        "HTTP 404, sin contenido PDF" $r $ok

    # SEC06: Consulta sin autenticación.

    $cliente.DefaultRequestHeaders.Remove("Cookie") | Out-Null

    $r = Consultar-Tutor 1

    $redirigeLogin = (
        $r.http -eq 302 -and
        $r.redireccion -match "(^|/)login\.php(\?.*)?$"
    )

    $denegado = $r.http -in @(401, 403)

    $ok = (
        ($redirigeLogin -or $denegado) -and
        $r.citas.Count -eq 0 -and
        $r.estado -ne "ANSWERED"
    )

    $resultados += Nuevo-Resultado `
        "SEC06" "Tutor sin autenticación" `
        "Redirección al login o HTTP 401/403, sin citas" $r $ok

    # Guardar evidencias sin contraseñas ni cookies.

    $archivoJson = Join-Path $carpeta "security-test-$fecha.json"
    $archivoCsv = Join-Path $carpeta "security-test-$fecha.csv"

    $resultados |
        ConvertTo-Json -Depth 10 |
        Set-Content -Path $archivoJson -Encoding UTF8

    $resultados |
        Select-Object id, prueba, esperado, http, aprobado,
        @{
            Name = "detalle"
            Expression = {
                $_.detalle | ConvertTo-Json -Depth 10 -Compress
            }
        } |
        Export-Csv -Path $archivoCsv -NoTypeInformation -Encoding UTF8

    $aprobados = @(
        $resultados | Where-Object { $_.aprobado }
    ).Count

    Write-Host ""
    Write-Host "=== RESUMEN DE SEGURIDAD ===" -ForegroundColor Cyan

    $resultados |
        Format-Table id, prueba, http, aprobado -AutoSize

    Write-Host "Total: $($resultados.Count)"
    Write-Host "Aprobados: $aprobados"
    Write-Host "Tasa: $([Math]::Round(100 * $aprobados / $resultados.Count, 2)) %"

    Write-Host ""
    Write-Host "Evidencias generadas:"
    Write-Host $archivoJson
    Write-Host $archivoCsv

    if ($aprobados -ne $resultados.Count) {
        Write-Warning "Existen pruebas de seguridad pendientes de revisión."
    }
}
finally {
    $cliente.Dispose()
    $handler.Dispose()
}
