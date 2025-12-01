# ============================================================
# 🎓 SISTEMA DE AUTOPRÉSTAMOS - ARQUITECTURA DUAL PROCESS
# ============================================================
# Versión: 2.3 (Mejoras: runspace limpio, limpieza)
# ============================================================

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

# ============================================================
# 📦 CONFIGURACIÓN GLOBAL
# ============================================================

$Global:Config = @{
    ServidorWS        = "ws://localhost:8081"
    ApiUrl            = "http://localhost/autoprestamos/prueba_equipos/api.php"
    LogoPath          = "C:\xampp\htdocs\autoprestamos\dashboard-unisimon\assets\img\logo.png"
    IdEquipo          = $env:COMPUTERNAME
    Username          = $env:USERNAME
    ClaveAdmin        = "S1m0n_2025"
    MaxReintentos     = 5
    TiempoReintento   = 3
}

# Variables de sincronización entre procesos
$Global:SharedState = [hashtable]::Synchronized(@{
    WebSocketConnected      = $false
    LastMessage             = $null
    CommandQueue            = [System.Collections.Queue]::Synchronized((New-Object System.Collections.Queue))
    LogQueue                = [System.Collections.Queue]::Synchronized((New-Object System.Collections.Queue))
    MacAddress              = $null
    SessionActive           = $true
    WSClientReference       = $null
    LastActivity            = (Get-Date)

    OutgoingQueue           = [System.Collections.Queue]::Synchronized((New-Object System.Collections.Queue))
    OutgoingSignal          = [System.Threading.AutoResetEvent]::new($false)
})

# ============================================================
# 🖥️ Detección de inactividad a nivel sistema (Win32)
# ============================================================
Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class IdleTime {
    [StructLayout(LayoutKind.Sequential)]
    struct LASTINPUTINFO {
        public uint cbSize;
        public uint dwTime;
    }
    [DllImport("user32.dll")]
    static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);
    public static uint GetIdleTime() {
        LASTINPUTINFO lastInputInfo = new LASTINPUTINFO();
        lastInputInfo.cbSize = (uint)Marshal.SizeOf(lastInputInfo);
        GetLastInputInfo(ref lastInputInfo);
        return ((uint)Environment.TickCount - lastInputInfo.dwTime) / 1000;
    }
}
"@

function Get-SystemIdleTime {
    return [IdleTime]::GetIdleTime()
}

# ============================================================
# 🔍 UTILIDADES
# ============================================================
function Write-Log {
    param(
        [string]$Mensaje,
        [ValidateSet('Info','Warning','Error','Success')][string]$Tipo = 'Info'
    )

    $timestamp = Get-Date -Format 'HH:mm:ss'
    $prefijo = switch ($Tipo) {
        'Info' { "ℹ️" }; 'Warning' { "⚠️" }; 'Error' { "❌" }; 'Success' { "✅" }
    }
    $color = switch ($Tipo) {
        'Info' { 'White' }; 'Warning' { 'Yellow' }; 'Error' { 'Red' }; 'Success' { 'Green' }
    }
    try { Write-Host "[$timestamp] $prefijo [$Tipo] $Mensaje" -ForegroundColor $color }
    catch { }
}

# Encolar mensajes salientes para que el runspace WebSocket los procese (thread-safe)
function Enqueue-WSMessage {
    param([hashtable]$Payload)
    try {
        if (-not $Global:SharedState.ContainsKey('OutgoingQueue')) {
            $Global:SharedState.OutgoingQueue = [System.Collections.Queue]::Synchronized((New-Object System.Collections.Queue))
        }
        $Global:SharedState.OutgoingQueue.Enqueue($Payload)
        # Señalizar al runspace WS para que procese inmediatamente
        try {
            if ($Global:SharedState.ContainsKey('OutgoingSignal') -and $Global:SharedState.OutgoingSignal) {
                $Global:SharedState.OutgoingSignal.Set() | Out-Null
            }
        } catch { }
        return $true
    }
    catch {
        Write-Log "⚠️ Error encolar mensaje WS: $_" -Tipo Warning
        return $false
    }
}

function Format-TimeSpan {
    param([int]$Segundos)
    $ts = [TimeSpan]::FromSeconds($Segundos)
    return "{0:00}:{1:00}:{2:00}" -f $ts.Hours, $ts.Minutes, $ts.Seconds
}

function Convert-ToColombiaDate {
    param([string]$UtcString)
    try {
        $dtUtc = [System.Xml.XmlConvert]::ToDateTime($UtcString, [System.Xml.XmlDateTimeSerializationMode]::Utc)
        $tzCol = [System.TimeZoneInfo]::FindSystemTimeZoneById("SA Pacific Standard Time")
        $dtCol = [System.TimeZoneInfo]::ConvertTimeFromUtc($dtUtc, $tzCol)
        return $dtCol.ToString("dddd, dd 'de' MMMM 'de' yyyy", [System.Globalization.CultureInfo]::GetCultureInfo("es-CO"))
    } catch { return $UtcString }
}

function Get-ActiveNetworkInterface {
    Write-Log "Detectando interfaz de red activa..." -Tipo Info
    $interfazActiva = Get-NetIPConfiguration |
        Where-Object { $_.IPv4DefaultGateway -ne $null -and $_.NetAdapter.Status -eq "Up" } |
        Select-Object -First 1
    if ($interfazActiva) {
        $mac = $interfazActiva.NetAdapter.MacAddress
        $nombre = $interfazActiva.NetAdapter.InterfaceAlias
        $ip = $interfazActiva.IPv4Address.IPAddress
        Write-Log "Interfaz detectada: $nombre (MAC: $mac)" -Tipo Success
        return @{ MAC = $mac; Nombre = $nombre; IP = $ip }
    }
    Write-Log "No se encontró interfaz con conexión a Internet" -Tipo Error
    return $null
}

# ============================================================
# 🔌 PROCESO WEBSOCKET (RUNSPACE) - INDEPENDIENTE
# ============================================================
$Global:WebSocketRunspace = $null
$Global:WebSocketPowerShell = $null

function Start-WebSocketProcess {
    Write-Log "🔄 Iniciando proceso WebSocket independiente..." -Tipo Info

    $Global:WebSocketRunspace = [runspacefactory]::CreateRunspace()
    $Global:WebSocketRunspace.Open()

    $Global:WebSocketRunspace.SessionStateProxy.SetVariable("Config", $Global:Config)
    $Global:WebSocketRunspace.SessionStateProxy.SetVariable("SharedState", $Global:SharedState)

    $Global:WebSocketPowerShell = [powershell]::Create()
    $Global:WebSocketPowerShell.Runspace = $Global:WebSocketRunspace

    $wsScript = {
        function Write-WSLog {
            param([string]$Mensaje, [string]$Tipo = 'Info')
            $timestamp = Get-Date -Format 'HH:mm:ss'
            $prefijo = switch ($Tipo) {
                'Info' { "🌐" }; 'Warning' { "⚠️" }; 'Error' { "❌" }; 'Success' { "✅" }
            }
            # Mostrar en consola del runspace
            Write-Host "[$timestamp] $prefijo [WS-PROCESS] $Mensaje"
            # Encolar log en la cola compartida (si existe)
            try {
                if (-not $SharedState.ContainsKey('LogQueue')) {
                    $SharedState.LogQueue = [System.Collections.Queue]::Synchronized((New-Object System.Collections.Queue))
                }
                $SharedState.LogQueue.Enqueue(@{ Mensaje = "[$timestamp] $Mensaje"; Tipo = $Tipo })
            } catch { }
        }

        function Send-WSMessage {
            param($WsClient, [hashtable]$Payload)
            if (-not $WsClient -or $WsClient.State -ne [System.Net.WebSockets.WebSocketState]::Open) {
                return $false
            }
            try {
                $json = $Payload | ConvertTo-Json -Compress
                $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
                $segment = [System.ArraySegment[byte]]::new($bytes)
                $WsClient.SendAsync($segment, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).Wait(3000)
                return $true
            } catch {
                Write-WSLog "Error al enviar mensaje: $_" -Tipo Error
                return $false
            }
        }

        function Connect-WSClient {
            param([int]$MaxReintentos = 5)
            $intentos = 0
            $uri = [System.Uri]$Config.ServidorWS

            while ($intentos -lt $MaxReintentos -and $SharedState.SessionActive) {
                try {
                    Write-WSLog "Conectando a $($Config.ServidorWS) (intento $($intentos + 1)/$MaxReintentos)..." -Tipo Info
                    $ws = [System.Net.WebSockets.ClientWebSocket]::new()
                    $ws.ConnectAsync($uri, [Threading.CancellationToken]::None).Wait()
                    if ($ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                        Write-WSLog "✅ Conectado exitosamente" -Tipo Success
                        # Registrar cliente
                        $registrado = Send-WSMessage -WsClient $ws -Payload @{
                            tipo = "registro"
                            accion = "getRegistro"
                            origen = "equipo"
                            nombre_equipo = $Config.IdEquipo
                        }
                        if ($registrado) {
                            Write-WSLog "📝 Cliente registrado: $($Config.IdEquipo)" -Tipo Success
                            $SharedState.WebSocketConnected = $true
                            $SharedState.WSClientReference = $ws
                            return $ws
                        }
                    }
                } catch {
                    Write-WSLog "Error de conexión: $_" -Tipo Error
                }

                $intentos++
                if ($intentos -ge $MaxReintentos) {
                    Write-WSLog "⛔ Máximo de intentos alcanzado, abortando conexión WebSocket." -Tipo Error
                    break
                }
                Start-Sleep -Seconds $Config.TiempoReintento
            }

            Write-WSLog "❌ No se pudo conectar después de $MaxReintentos intentos" -Tipo Error
            $SharedState.WebSocketConnected = $false
            return $null
        }

        function Start-WSListener {
            param($WsClient)
            Write-WSLog "👂 Iniciando escucha continua de mensajes..." -Tipo Success
            $buffer = New-Object Byte[] 8192

            while ($WsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open -and $SharedState.SessionActive) {
                # Procesar cola de mensajes salientes (si existen)
                try {
                    while ($SharedState.ContainsKey('OutgoingQueue') -and $SharedState.OutgoingQueue.Count -gt 0) {
                        $out = $SharedState.OutgoingQueue.Dequeue()
                        Send-WSMessage -WsClient $WsClient -Payload $out | Out-Null
                    }
                }
                catch { }

                try {
                    $result = $WsClient.ReceiveAsync([ArraySegment[byte]]$buffer, [Threading.CancellationToken]::None).Result
                    if ($result.Count -gt 0) {
                        $mensaje = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $result.Count)
                        Write-WSLog "📩 Recibido: $mensaje" -Tipo Info
                        try {
                            $data = $mensaje | ConvertFrom-Json
                            if ($data.origen -ne "server") {
                                Write-WSLog "⛔ Origen no autorizado: $($data.origen)" -Tipo Warning
                                continue
                            }
                            if ($data.tipo -eq "ping") {
                                Send-WSMessage -WsClient $WsClient -Payload @{
                                    tipo = "pong"
                                    id = $Config.IdEquipo
                                    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                                } | Out-Null
                                Write-WSLog "🏓 Pong enviado" -Tipo Info
                                continue
                            }
                            $SharedState.CommandQueue.Enqueue($data)
                            $SharedState.LastMessage = $mensaje
                            Write-WSLog "✅ Encolado: $($data.tipo) - $($data.accion)" -Tipo Success
                        } catch {
                            Write-WSLog "⚠️ Error JSON: $_" -Tipo Warning
                        }
                    }
                } catch {
                    Write-WSLog "❌ Error escucha: $_" -Tipo Error
                    break
                }
                try {
                    if ($SharedState.ContainsKey('OutgoingSignal') -and $SharedState.OutgoingSignal) {
                        # Espera hasta que haya una señal de salida o hasta 100ms
                        $null = $SharedState.OutgoingSignal.WaitOne(100)
                    } else {
                        Start-Sleep -Milliseconds 100
                    }
                } catch { Start-Sleep -Milliseconds 100 }
            }
            Write-WSLog "⚠️ Listener finalizado. Estado: $($WsClient.State)" -Tipo Warning
        }

        # BUCLE PRINCIPAL WS
        Write-WSLog "🚀 Proceso WebSocket iniciado" -Tipo Success
        while ($SharedState.SessionActive) {
            $ws = Connect-WSClient -MaxReintentos 5
            if ($ws) {
                Start-WSListener -WsClient $ws
                try {
                    if ($ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                        $ws.CloseAsync('NormalClosure', 'Reconexión', [Threading.CancellationToken]::None).Wait(2000)
                    }
                    $ws.Dispose()
                } catch {
                    Write-WSLog "⚠️ Error al cerrar: $_" -Tipo Warning
                }
                $SharedState.WebSocketConnected = $false
                $SharedState.WSClientReference = $null
            }
            if ($SharedState.SessionActive) {
                Write-WSLog "🔄 Reintentando en 5 segundos..." -Tipo Warning
                Start-Sleep -Seconds 5
            }
        }
        Write-WSLog "🛑 Proceso WebSocket finalizado" -Tipo Info
    }

    $Global:WebSocketPowerShell.AddScript($wsScript) | Out-Null
    $Global:WebSocketPowerShell.BeginInvoke() | Out-Null

    Write-Log "✅ Proceso WebSocket iniciado en runspace independiente" -Tipo Success
}

function Stop-WebSocketProcess {
    Write-Log "🛑 Deteniendo proceso WebSocket..." -Tipo Warning

    # 1) Marcar inactividad
    $Global:SharedState.SessionActive = $false

    # 2) Señalizar cola saliente para que el runspace no se quede esperando
    try {
        if ($Global:SharedState.ContainsKey('OutgoingSignal') -and $Global:SharedState.OutgoingSignal) {
            $Global:SharedState.OutgoingSignal.Set() | Out-Null
        }
    } catch { }

    # 3) Cerrar el ClientWebSocket si existe (esto desbloquea ReceiveAsync dentro del runspace)
    try {
        $wsClient = $Global:SharedState.WSClientReference
        if ($wsClient -and ($wsClient -is [System.Net.WebSockets.ClientWebSocket])) {
            try {
                Write-Log "🔌 Cerrando socket remoto..." -Tipo Info
                $closeTask = $wsClient.CloseAsync([System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure, "Shutdown", [Threading.CancellationToken]::None)
                $waitOk = $closeTask.Wait(2000)
                if (-not $waitOk) {
                    Write-Log "⚠️ CloseAsync no respondió, intentado Abort/Dispose" -Tipo Warning
                    try { $wsClient.Dispose() } catch {}
                } else {
                    Write-Log "✅ Socket cerrado correctamente" -Tipo Success
                }
            } catch {
                Write-Log "⚠️ Error cerrando socket: $_ - Forzando Dispose()" -Tipo Warning
                try { $wsClient.Dispose() } catch {}
            }
        }
    } catch {
        Write-Log "⚠️ No se pudo acceder a WSClientReference: $_" -Tipo Warning
    }

    # 4) Intentar detener el objeto PowerShell que creó el runspace
    try {
        if ($Global:WebSocketPowerShell) {
            try { $Global:WebSocketPowerShell.Stop() } catch {}
            # Esperar un poco a que termine
            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            while ($Global:WebSocketPowerShell.InvocationStateInfo.State -eq "Running" -and $sw.Elapsed.TotalSeconds -lt 4) {
                Start-Sleep -Milliseconds 150
            }
            if ($Global:WebSocketPowerShell.InvocationStateInfo.State -eq "Running") {
                Write-Log "🧨 PowerShell pipeline no responde. Forzando Commands.Clear() y Dispose()" -Tipo Warning
                try { $Global:WebSocketPowerShell.Commands.Clear() } catch {}
                try { $Global:WebSocketPowerShell.Dispose() } catch {}
            } else {
                try { $Global:WebSocketPowerShell.Dispose() } catch {}
            }
            $Global:WebSocketPowerShell = $null
        }
    } catch {
        Write-Log "⚠️ Error deteniendo WebSocketPowerShell: $_" -Tipo Warning
    }

    # 5) Intento de cierre limpio del runspace
    try {
        if ($Global:WebSocketRunspace) {
            try { $Global:WebSocketRunspace.Close() } catch {}
            $sw2 = [System.Diagnostics.Stopwatch]::StartNew()
            while ($Global:WebSocketRunspace.RunspaceStateInfo.State -ne "Closed" -and $sw2.Elapsed.TotalSeconds -lt 4) {
                Start-Sleep -Milliseconds 150
            }
            if ($Global:WebSocketRunspace.RunspaceStateInfo.State -ne "Closed") {
                Write-Log "🧨 Runspace no cerrado, forzando Dispose()" -Tipo Warning
                try { $Global:WebSocketRunspace.Dispose() } catch {}
            }
            $Global:WebSocketRunspace = $null
        }
    } catch {
        Write-Log "⚠️ Error cerrando runspace: $_" -Tipo Warning
    }

    Write-Log "✅ Proceso WebSocket detenido (Stop-WebSocketProcess completado)" -Tipo Success
}

# Variable global para mensajes confirmacion
if (-not $Global:ConfirmacionesRecibidas) { $Global:ConfirmacionesRecibidas = @() }

function On-WSMessageReceived {
    param([string]$jsonMsg)

    $msg = ConvertFrom-Json $jsonMsg
    if ($msg.tipo -eq 'proceso_comando' -or $msg.tipo -eq 'confirmacion_comando') {
        # Guardar confirmaciones relevantes
        $Global:ConfirmacionesRecibidas += $msg
    }
}

# ============================================================
# 🎮 PROCESADOR DE COMANDOS WEBSOCKET (Acciones)
# ============================================================
function Invoke-AccionControl {
    param([string]$Accion, [hashtable]$Detalles = @{})
    Write-Log "🎯 Ejecutando acción: $Accion" -Tipo Info

    function Send-Confirmacion {
        param([string]$Resultado, [string]$Mensaje)
        $payload = @{
            tipo = "confirmacion"
            origen = "equipo"
            usuario = $env:USERNAME
            mac_eq = $Global:SharedState.MacAddress
            nombre_equipo = $Global:Config.IdEquipo
            accion = $Accion
            resultado = $Resultado
            mensaje = $Mensaje
            timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        }
        if ($Detalles.corr) { $payload.corr = $Detalles.corr }
        try {
            $wsClient = $Global:SharedState.WSClientReference
                    if ($wsClient -and $wsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                        $payload = @{
                            tipo = "confirmacion"
                            origen = "equipo"
                            usuario = $env:USERNAME
                            mac_eq = $Global:SharedState.MacAddress
                            nombre_equipo = $Global:Config.IdEquipo
                            accion = $Accion
                            resultado = $Resultado
                            mensaje = $Mensaje
                            timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                        }
                        if ($Detalles.corr) { $payload.corr = $Detalles.corr }
try {
    $json = ConvertTo-Json $payload -Depth 10
    $buffer = [System.Text.Encoding]::UTF8.GetBytes($json)

    # Crear ArraySegment correctamente (con coma para evitar descomposición)
    $segment = New-Object System.ArraySegment[byte] (,$buffer)

    $wsClient.SendAsync(
        $segment,
        [System.Net.WebSockets.WebSocketMessageType]::Text,
        $true,
        [System.Threading.CancellationToken]::None
    ) | Out-Null

    Write-Log "⚡ Confirmación enviada inmediatamente" -Tipo Success
    $controles.Form.close();
    continue
}
catch {
    Write-Log "❌ Error enviando confirmación inmediata: $_" -Tipo Error
}

                    } else { Write-Log "⚠️ WebSocket no disponible para encolar confirmación" -Tipo Warning }
        } catch { Write-Log "⚠️ Error al enviar confirmación: $_" -Tipo Warning }
    }

    switch ($Accion) {
        "bloquear" {
            Write-Log "🔒 Bloqueando sesion..." -Tipo Warning
            Send-Confirmacion -Resultado "ejecutando" -Mensaje "Bloqueando equipo..."
 }
        "suspender" {
            Write-Log "💤 Suspendiendo equipo..." -Tipo Warning
            Send-Confirmacion -Resultado "ejecutando" -Mensaje "Preparando suspensión..."
        }
        "finalizar" {
            Write-Log "⛔ Iniciando flujo FINALIZAR (API -> UI -> CONFIRM)" -Tipo Info
            Send-Confirmacion -Resultado "ejecutando" -Mensaje "Solicitando finalización"
        }
        "renovar" {
            Write-Log "♻️ Sesión renovada" -Tipo Success
            Send-Confirmacion -Resultado "ejecutando" -Mensaje "Renovación confirmada"
        }
        "mensaje" {
            $texto = if ($Detalles.texto) { $Detalles.texto } else { "Mensaje del administrador" }
            Invoke-Mensaje -Texto $texto -LogoPath $Global:Config.LogoPath
        }
        "ver_info" {
            Write-Log "📊 Recopilando información..." -Tipo Info
            try {
                $info = @{
                    usuario = $env:USERNAME
                    equipo  = $env:COMPUTERNAME
                    ip      = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.InterfaceAlias -notmatch "Loopback"} | Select-Object -First 1).IPAddress
                    mac     = $Global:SharedState.MacAddress
                    so      = (Get-CimInstance Win32_OperatingSystem).Caption
                    memoria = [Math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB, 2)
                    procesador = (Get-CimInstance Win32_Processor).Name
                }
                $wsClient = $Global:SharedState.WSClientReference
                if ($wsClient -and $wsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                    $payload = @{ 
                        tipo = "info_respuesta"
                        id   = $Global:Config.IdEquipo
                        datos= $info
                    }
                    Enqueue-WSMessage -Payload $payload | Out-Null
                    Write-Log "✅ Información encolada para envío" -Tipo Success
                }
            } catch { Write-Log "❌ Error al recopilar info: $_" -Tipo Error; Send-Confirmacion -Resultado "error" -Mensaje "Error al obtener información" }
        }
        default { Write-Log "⚠️ Acción desconocida: $Accion" -Tipo Warning; Send-Confirmacion -Resultado "desconocida" -Mensaje "Acción no reconocida" }
    }
}

# Función auxiliar para convertir PSCustomObject a Hashtable
function ConvertTo-Hashtable {
    param([Parameter(ValueFromPipeline)]$InputObject)
    process {
        if ($null -eq $InputObject) { return $null }
        if ($InputObject -is [System.Collections.IDictionary]) { return $InputObject }
        if ($InputObject -is [PSCustomObject]) {
            $hash = @{}
            $InputObject.PSObject.Properties | ForEach-Object {
                $hash[$_.Name] = if ($_.Value -is [PSCustomObject]) { ConvertTo-Hashtable $_.Value } else { $_.Value }
            }
            return $hash
        }
        return $InputObject
    }
}

# Monitor de cola de comandos (Timer en UI thread)
function Start-CommandQueueMonitor {
    $timer = New-Object System.Windows.Forms.Timer
    $timer.Interval = 300
    $timer.Add_Tick({
        try {
            while ($Global:SharedState.CommandQueue.Count -gt 0) {
                try {
                    $comando = $Global:SharedState.CommandQueue.Dequeue()
                    if ($comando -is [PSCustomObject]) { $comando = ConvertTo-Hashtable $comando }
                    if ($comando.tipo -eq "control_server" -and $comando.destino -eq "shell") {
                        switch ($comando.manejo) {
                            "comandos" {
                                $detalles = ConvertTo-Hashtable $comando
                                Invoke-AccionControl -Accion $comando.accion -Detalles $detalles
                            }
                            "mensaje" {
                                Invoke-AccionControl -Accion "mensaje" -Detalles @{ texto = $comando.texto }
                            }
                            "info" {
                                Invoke-AccionControl -Accion "ver_info" -Detalles @{}
                            }
                            
                            default {
                                Write-Log "⚠️ Comando desconocido: $($comando.tipo)" -Tipo Warning
                            }
                        }
                    }
                    if($comando.tipo -eq "confirmacion_comando"){
                        switch($comando.accion){
                            "finalizar"{
                                Invoke-EstadoFinalizado -Controles $controles -Response $response
                                Start-Sleep -Seconds 4
                                #shutdown /h
                            }
                            "renovar"{
                                $Controles.Form.Refresh();
                                Invoke-EstadoRenovado -Controles $controles -Response $response
                                $resp = Invoke-ApiCall
                                Write-Host "Respuesta API renovación: $($resp | ConvertTo-Json -Compress)"
                                Start-Sleep -Seconds 3
                            }
                            "bloquear"{
                                Invoke-EstadoBloqueado -Controles $controles -Response $response
                            }
                            "suspender"{
                                Invoke-PantallaCompleta -Controles $controles -Response $response
                            }
                        }
                    }
                } catch { Write-Log "❌ Error procesando comando: $_" -Tipo Error }
            }
        } catch { }
    })
    $timer.Start()
    return $timer
}

# ============================================================
# 🌐 API REST
# ============================================================
function Invoke-ApiCall {
    param([hashtable]$ExtraBody = @{})
    $body = @{
        username    = $Global:Config.Username
        mac_address = $Global:SharedState.MacAddress
        origen      = "equipo"
        tipo        = "control"
    } + $ExtraBody

    $json = $body | ConvertTo-Json -Compress
    $headers = @{ "Content-Type" = "application/json" }

    try {
        $response = Invoke-RestMethod -Uri $Global:Config.ApiUrl -Method Post -Headers $headers -Body $json -TimeoutSec 60
        return $response
    } catch {
        Write-Log "Error API: $($_.Exception.Message)" -Tipo Error
        return @{ estado = "Error"; mensaje = $_.Exception.Message }
    }
}
function Invoke-Mensaje {
    param(
        $Texto = "Mensaje del administrador",
        $LogoPath = $Global:Config.LogoPath  # Ruta opcional al logo
    )

    Write-Log "💬 Mostrando mensaje con logo: {$Texto}" -Tipo Info

    try {
        # Crear formulario
        $form = New-Object System.Windows.Forms.Form
        $form.Text = "Notificación del Sistema"
        $form.Size = New-Object System.Drawing.Size(450,250)
        $form.StartPosition = "CenterScreen"
        $form.BackColor = [System.Drawing.Color]::LightGreen
        $form.FormBorderStyle = "FixedDialog"
        $form.TopMost = $true
        $form.ShowInTaskbar = $false

        # ---- Logo ----
        if (Test-Path $LogoPath) {
            $logo = New-Object System.Windows.Forms.PictureBox
            $logo.Image = [System.Drawing.Image]::FromFile($LogoPath)
            $logo.SizeMode = "Zoom"
            $logo.Size = New-Object System.Drawing.Size(100,100)
            $logo.Location = New-Object System.Drawing.Point(10,10)
            $form.Controls.Add($logo)
        }

        # ---- Texto no editable ----
        $lblTexto = New-Object System.Windows.Forms.Label
        $lblTexto.AutoSize = $false
        $lblTexto.Text = "Admin: $Texto"
        $lblTexto.Font = New-Object System.Drawing.Font("Segoe UI", 11, [System.Drawing.FontStyle]::Regular)
        $lblTexto.Location = New-Object System.Drawing.Point(120,20)
        $lblTexto.Size = New-Object System.Drawing.Size(300,120)
        $lblTexto.BackColor = [System.Drawing.Color]::PaleGreen
        $lblTexto.Padding = "10,10,10,10"
        $lblTexto.BorderStyle = "FixedSingle"
        $lblTexto.TextAlign = "TopLeft"
        $form.Controls.Add($lblTexto)

        # ---- Botón aceptar ----
        $btnCerrar = New-Object System.Windows.Forms.Button
        $btnCerrar.Text = "Aceptar"
        $btnCerrar.Size = New-Object System.Drawing.Size(100,30)
        $btnCerrar.Location = New-Object System.Drawing.Point(170,150)
        $btnCerrar.Add_Click({ $form.Close() })
        $form.Controls.Add($btnCerrar)

        # Mostrar sin bloquear
        $form.Show()

        # Tiempo límite de 10 segundos
        $limit = (Get-Date).AddSeconds(10)

        while (-not $form.IsDisposed -and (Get-Date) -lt $limit) {
            [System.Windows.Forms.Application]::DoEvents()
            Start-Sleep -Milliseconds 50
        }

        # Autocerrar si aún está abierta
        if (-not $form.IsDisposed) { $form.Close() }

        Send-Confirmacion -Resultado "ejecutado" -Mensaje "Mensaje con logo mostrado"
    }
    catch {
        Send-Confirmacion -Resultado "error" -Mensaje "Error al mostrar mensaje con logo"
    }
}

# ============================================================
# 🖥️ INTERFAZ GRÁFICA (Formulario de sesión)
# ============================================================
Add-Type @"
using System;
using System.Runtime.InteropServices;

public class WinAPI {
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
}
"@
function New-SessionForm {
    $form = New-Object System.Windows.Forms.Form
    $form.Text = "Gestión de Sesión - AutoPréstamos"
    $form.Size = [System.Drawing.Size]::new(400,200)
    $form.StartPosition = "Manual"

    # Usar minimización REAL
    $form.FormBorderStyle = [System.Windows.Forms.FormBorderStyle]::FixedSingle
    $form.MinimizeBox = $true
    $form.MaximizeBox = $false
    $form.ShowInTaskbar = $true

    $form.Location = [System.Drawing.Point]::new(
        [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width - 400,
        [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Height - 250
    )

    # ------------------------------
    # RESTAURACIÓN FORZADA
    # ------------------------------
    $form.Add_Resize({
        if ($form.WindowState -eq [System.Windows.Forms.FormWindowState]::Normal) {
            $hwnd = $form.Handle

            # Restaurar ventana
            [WinAPI]::ShowWindow($hwnd, 9) | Out-Null   # 9 = SW_RESTORE

            Start-Sleep -Milliseconds 50

            # Traer al frente SIEMPRE
            [WinAPI]::SetForegroundWindow($hwnd) | Out-Null
        }
    })

    # --- LOGO ---
    $logo = New-Object System.Windows.Forms.PictureBox
    $logo.SizeMode = "StretchImage"
    $logo.Size = [System.Drawing.Size]::new(80,80)
    $logo.Location = [System.Drawing.Point]::new(10,10)
    if (Test-Path $Global:Config.LogoPath) { try { $logo.Image = [System.Drawing.Image]::FromFile($Global:Config.LogoPath) } catch { } }
    $form.Controls.Add($logo)

    # --- INFO ---
    $labelInfo = New-Object System.Windows.Forms.Label
    $labelInfo.Font = New-Object System.Drawing.Font("Segoe UI",12)
    $labelInfo.Location = [System.Drawing.Point]::new(100,10)
    $labelInfo.AutoSize = $true
    $labelInfo.Text = "Usuario: $($Global:Config.Username)`nMAC: $($Global:Global.IdEquipo)"
    $form.Controls.Add($labelInfo)

    # --- TIMER ---
    $labelTimer = New-Object System.Windows.Forms.Label
    $labelTimer.Font = New-Object System.Drawing.Font("Segoe UI",14,[System.Drawing.FontStyle]::Bold)
    $labelTimer.Location = [System.Drawing.Point]::new(30,100)
    $labelTimer.AutoSize = $true
    $labelTimer.Text = "Cargando..."
    $form.Controls.Add($labelTimer)
    $script:labelTimer = $labelTimer   # ← ESTA LÍNEA SE AGREGA

    return @{
        Form = $form
        LabelInfo = $labelInfo
        LabelTimer = $labelTimer
        Logo = $logo
    }
}

# ============================================================
# 🎮 ESTADOS DE SESIÓN (UI handlers)
# ============================================================
function Invoke-EstadoAbierto {
    param($Controles,$Response)
    Write-Log "Estado: ABIERTO" -Tipo Success
    try { Start-Process -FilePath "explorer.exe" -ErrorAction SilentlyContinue } catch { }
    try {
        $Controles.LabelTimer.ForeColor = [System.Drawing.Color]::DarkGreen
        $tiempo = if ($Response.tiempo_restante) { $Response.tiempo_restante } else { 30 }
        for ($i = $tiempo; $i -ge 0; $i--) {
            $Controles.LabelTimer.Text = "🟢 SESIÓN ACTIVA - Restante: $(Format-TimeSpan $i)"
            $Controles.Form.Refresh()
            $waitUntil = (Get-Date).AddSeconds(1)
            while ((Get-Date) -lt $waitUntil) { [System.Windows.Forms.Application]::DoEvents(); Start-Sleep -Milliseconds 50 }
        }
    } catch { Write-Log "Error en countdown: $_" -Tipo Warning }
    return Invoke-ApiCall
}
function Invoke-EstadoBloqueado {
    param($Controles,$Response)
    Write-Log "Estado: BLOQUEADO" -Tipo Error
    try {
        $Controles.LabelTimer.ForeColor = [System.Drawing.Color]::Red
        if ($Response.folioCheckin -and $Response.folioCheckin.raw -and $Response.folioCheckin.raw.loan.status.name -eq "Closed") {
            Write-Log "Check-in detectado, actualizando estado..." -Tipo Info
            Start-Sleep -Seconds 1
            return Invoke-ApiCall
        }
        $tiempo = if ($Response.tiempo_restante) { $Response.tiempo_restante } else { 10 }
        for ($i = $tiempo; $i -ge 0; $i--) {
            $Controles.LabelTimer.Text = "🔒 BLOQUEADO - Restante: $(Format-TimeSpan $i)"
            $Controles.Form.Refresh()
            $waitUntil = (Get-Date).AddSeconds(1)
            while ((Get-Date) -lt $waitUntil) { [System.Windows.Forms.Application]::DoEvents(); Start-Sleep -Milliseconds 50 }
        }
    } catch { Write-Log "Error en countdown bloqueado: $_" -Tipo Warning }
    return Invoke-ApiCall
}
function Invoke-EstadoSuspendido {
    param($Controles, $Response)

    Write-Log "Estado: SUSPENDIDO" -Tipo Warning

    try {
        # =======================================
        # TIEMPO RESTANTE
        # =======================================
        $tiempo = if ($Response.tiempo_restante) { [int]$Response.tiempo_restante } else { 60 }

        # =======================================
        # FORM PANTALLA COMPLETA
        # =======================================
        $form              = New-Object System.Windows.Forms.Form
        $form.WindowState  = 'Maximized'
        $form.FormBorderStyle = 'None'
        $form.BackColor    = [System.Drawing.Color]::FromArgb(20,20,20)
        $form.TopMost      = $true
        $form.KeyPreview   = $true

        # Bloquear todo excepto Ctrl+F4
        $form.Add_KeyDown({
            if ($_.Control -and $_.KeyCode -eq 'F4') {
                $_.Handled = $false
            } else {
                $_.SuppressKeyPress = $true
                $_.Handled = $true
            }
        })

        # =======================================
        # LOGO
        # =======================================
        $logo = New-Object System.Windows.Forms.PictureBox
        $logo.Size = [System.Drawing.Size]::new(220,220)
        $logo.Location = [System.Drawing.Point]::new(($form.Width - 220)/2, 40)
        $logo.SizeMode = 'StretchImage'
        if (Test-Path $Global:Config.LogoPath) {
            try { $logo.Image = [System.Drawing.Image]::FromFile($Global:Config.LogoPath) } catch {}
        }
        $form.Controls.Add($logo)

        # =======================================
        # INFO
        # =======================================
        $labelInfo = New-Object System.Windows.Forms.Label
        $labelInfo.ForeColor = [System.Drawing.Color]::White
        $labelInfo.Font = New-Object System.Drawing.Font("Segoe UI",18,[System.Drawing.FontStyle]::Bold)
        $labelInfo.AutoSize = $true
        $labelInfo.Text = "Usuario: $($Global:Config.Username)`nEquipo: $($Global:Config.IdEquipo)"
        $labelInfo.Location = [System.Drawing.Point]::new(50,300)
        $form.Controls.Add($labelInfo)

        # =======================================
        # TIMER
        # =======================================
        $labelTimer = New-Object System.Windows.Forms.Label
        $labelTimer.ForeColor = [System.Drawing.Color]::Orange
        $labelTimer.Font = New-Object System.Drawing.Font("Segoe UI",36,[System.Drawing.FontStyle]::Bold)
        $labelTimer.AutoSize = $true
        $labelTimer.Location = [System.Drawing.Point]::new(50,420)
        $form.Controls.Add($labelTimer)

        # =======================================
        # BOTÓN SALIR VISUAL
        # =======================================
        $btnSalir = New-Object System.Windows.Forms.Button
        $btnSalir.Text = "X"
        $btnSalir.Font = New-Object System.Drawing.Font("Segoe UI",14,[System.Drawing.FontStyle]::Bold)
        $btnSalir.Size = [System.Drawing.Size]::new(60,40)
        $btnSalir.BackColor = [System.Drawing.Color]::DarkRed
        $btnSalir.ForeColor = [System.Drawing.Color]::White
        $btnSalir.Location = [System.Drawing.Point]::new($form.Width - 80, 20)
        $btnSalir.Add_Click({ $form.Close() })
        $form.Controls.Add($btnSalir)

        # =======================================
        # FUNCIÓN CREADORA DE BOTONES
        # =======================================
        function New-BigButton($text, $color, $y, $clickAction) {
            $btn = New-Object System.Windows.Forms.Button
            $btn.Text = $text
            $btn.Font = New-Object System.Drawing.Font("Segoe UI",22,[System.Drawing.FontStyle]::Bold)
            $btn.Size = [System.Drawing.Size]::new(500,85)
            $btn.BackColor = $color
            $btn.ForeColor = [System.Drawing.Color]::White
            $btn.Location = [System.Drawing.Point]::new(($form.Width - 500)/2, $y)
            $btn.Add_Click($clickAction)
            return $btn
        }

        # =======================================
        # ENVÍO WEBSOCKET: MISMO PATRÓN QUE Invoke-AccionControl
        # - Intenta envío inmediato con WSClientReference
        # - Si no está disponible, encola con Enqueue-WSMessage
        # =======================================
        function Send-WS-Payload {
            param([hashtable]$payload)

            try {
                $wsClient = $Global:SharedState.WSClientReference
                $json = $payload | ConvertTo-Json -Depth 10

                if ($wsClient -and $wsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                    try {
                        $buffer = [System.Text.Encoding]::UTF8.GetBytes($json)
                        # crear ArraySegment[byte] correctamente
                        $segment = New-Object System.ArraySegment[byte] (,$buffer)

                        # Envío asíncrono pero no bloqueante (como en Invoke-AccionControl)
                        $wsClient.SendAsync(
                            $segment,
                            [System.Net.WebSockets.WebSocketMessageType]::Text,
                            $true,
                            [System.Threading.CancellationToken]::None
                        ) | Out-Null

                        Write-Log "⚡ WS enviado inmediatamente (suspendido)" -Tipo Success
                        Start-Sleep -Milliseconds 500
                        return $true
                    } catch {
                        Write-Log "❌ Error envío inmediato WS (suspendido): $_" -Tipo Error
                        # caemos al fallback
                    }
                } else {
                    Write-Log "⚠️ WSClientReference no disponible, encolando payload (suspendido)" -Tipo Warning
                }
            } catch {
                Write-Log "❌ Error en Send-WS-Payload: $_" -Tipo Error
                return $false
            }
        }

# === BOTÓN PEDIR RENOVACIÓN (ENVÍO SIMPLE Y DIRECTO) ===
$btnRenovar = New-BigButton "🔄 Pedir renovación" ([System.Drawing.Color]::DarkOrange) 550 {
    try {
        $payload = @{
            tipo          = "comando"
            origen        = "equipo"
            accion        = "solicitar_renovacion"
            nombre_equipo = $Global:Config.IdEquipo
            timestamp     = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        }

        $json = $payload | ConvertTo-Json -Depth 10
        Write-Log "Enviando WS solicitud renovación: $json" -Tipo Info

        $ws = $Global:SharedState.WSClientReference

        if ($ws -and $ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {

            $buffer  = [System.Text.Encoding]::UTF8.GetBytes($json)
            $segment = New-Object System.ArraySegment[byte] (,$buffer)

            $ws.SendAsync(
                $segment,
                [System.Net.WebSockets.WebSocketMessageType]::Text,
                $true,
                [System.Threading.CancellationToken]::None
            ) | Out-Null

            Write-Log "Solicitud de renovación enviada exitosamente." -Tipo Success
        }
        else {
            Write-Log "❌ No se pudo enviar solicitud: WebSocket no disponible." -Tipo Error
        }

    } catch {
        Write-Log "❌ Error enviando solicitud de renovación: $_" -Tipo Error
    }
}
$form.Controls.Add($btnRenovar)


        # === BOTÓN RENOVAR CON CLAVE ===
        $btnClave = New-BigButton "🔐 Renovar con clave" ([System.Drawing.Color]::SteelBlue) 650 {
            $clave = [Microsoft.VisualBasic.Interaction]::InputBox("Ingrese clave admin:", "Renovación con clave")
            if ($clave) {
                $payload = @{
                    tipo = "comando"
                    origen = "equipo"
                    nombre_equipo = $Global:Config.IdEquipo
                    accion = "renovacion_clave"
                    clave = $clave
                    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                }
                Send-WS-Payload -payload $payload
            }
        }
        $form.Controls.Add($btnClave)

        # === BOTÓN CERRAR SESIÓN ===
        $btnCerrar = New-BigButton "⛔ Cerrar sesión" ([System.Drawing.Color]::Firebrick) 750 {
            $payload = @{
                tipo = "comando"
                origen = "equipo"
                nombre_equipo = $Global:Config.IdEquipo
                accion = "cerrar_sesion"
                timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
            }
            Send-WS-Payload -payload $payload
            $form.Close()
        }
        $form.Controls.Add($btnCerrar)

        # =======================================
        # COUNTDOWN
        # =======================================
        $timer = New-Object System.Windows.Forms.Timer
        $timer.Interval = 1000

        $timer.Add_Tick({
            if ($tiempo -le 0) {
                $timer.Stop()
                Write-Log "⏳ Tiempo suspendido agotado → cierre de sesión" -Tipo Warning

                $payload = @{
                    tipo = "comando"
                    origen = "equipo"
                    nombre_equipo = $Global:Config.IdEquipo
                    accion = "cerrar_sesion"
                    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                }

                Send-WS-Payload -payload $payload

                $form.Close()
            }

            $tiempo--
        })

        $timer.Start()
        $form.ShowDialog() | Out-Null

        # Asegurar que si la pantalla se cerró se limpien flags relevantes
        $Global:SharedState.IsHibernating = $false
        $Global:SharedState.HibernationStartTime = $null
        return Invoke-ApiCall
    }
    catch {
        Write-Log "Error en estado suspendido: $_" -Tipo Error
        return Invoke-ApiCall
    }
}
function Invoke-EstadoRenovado {
    param([Parameter(Mandatory=$true)][hashtable]$Controles,[Parameter(Mandatory=$true)][hashtable]$Response)
    Write-Log "Estado: RENOVADO - Sesión extendida" -Tipo Success
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show(
        "Tu sesión ha sido renovada exitosamente. ¡Puedes continuar tu trabajo!",
        "Sesión Renovada",
        "OK",
        "Information"
    ) | Out-Null
    return Invoke-ApiCall
}
function Invoke-EstadoError {
    param([Parameter(Mandatory=$true)]$Controles,[Parameter(Mandatory=$true)]$Response)
    Write-Log "Estado: ERROR - $($Response.mensaje)" -Tipo Error
    try {
        [System.Windows.Forms.MessageBox]::Show(
            $Response.mensaje,
            "❌ Error de Sesión",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
        $Controles.Form.Close()
    } catch { }
}
function Invoke-EstadoFinalizado {
    param([Parameter(Mandatory=$true)]$Controles,[Parameter(Mandatory=$true)]$Response)
    Write-Log "Estado: FINALIZADO - Sesión completada" -Tipo Success
    $Controles.LabelInfo.ForeColor = [System.Drawing.Color]::Blue
    $Controles.LabelTimer.Text = "✅ Sesión finalizada correctamente"; $Controles.LabelTimer.ForeColor = [System.Drawing.Color]::Green
    $Controles.Form.Refresh(); Start-Sleep -Seconds 2; $Controles.Form.Close()
}
# ============================================================
# 🚀 INICIALIZACIÓN Y BUCLE PRINCIPAL (Start-SessionLoop)
# ============================================================
function Start-SessionLoop {
    Write-Log "Iniciando bucle principal de sesión..." -Tipo Info
    try {
        $controles = New-SessionForm
        $response = Invoke-ApiCall -ExtraBody @{ confirmar_inicio = "true" }

        if (-not $response.estado) { Write-Log "No se pudo obtener estado inicial" -Tipo Error; return }

        # Monitor de comandos WebSocket
        $queueMonitor = Start-CommandQueueMonitor

        $controles.Form.Show()


        while ($response -and $response.estado -notin @("Finalizado","Restringido","Error")) {
            Write-Log "Estado actual: $($response.estado)" -Tipo Info
            try {
                # CONTROL GLOBAL DE INACTIVIDAD (usa Get-SystemIdleTime)

                # Procesamiento normal de estados (UI)
                switch ($response.estado) {
                    "Abierto"   { $response = Invoke-EstadoAbierto -Controles $controles -Response $response }
                    "Bloqueado" { $response = Invoke-EstadoBloqueado -Controles $controles -Response $response }
                    "Suspendido"{ $response = Invoke-EstadoSuspendido -Controles $controles -Response $response }
                    "Renovado"  { $response = Invoke-EstadoRenovado -Controles $controles -Response $response }
                    default {
                        if ($response.folioResp -and $response.folioResp.raw.loan.status.name -eq "Closed") {
                            Write-Log "Préstamo cerrado en FOLIO" -Tipo Info
                            break
                        }
                        Write-Log "Estado desconocido: $($response.estado)" -Tipo Warning
                        Start-Sleep -Seconds 2
                        $response = Invoke-ApiCall
                    }
                }
            } catch {
                Write-Log "Error procesando estado: $_" -Tipo Error
                Start-Sleep -Seconds 2
                $response = Invoke-ApiCall
            }
            Start-Sleep -Milliseconds 200
        }

        # Procesar estados finales
        if ($response.estado -eq "Finalizado") { Invoke-EstadoFinalizado -Controles $controles -Response $response }
        elseif ($response.estado -eq "Restringido") { Invoke-EstadoRestringido -Controles $controles -Response $response }
        elseif ($response.estado -eq "Error") { Invoke-EstadoError -Controles $controles -Response $response }

        # Limpieza final
        try {
            if ($queueMonitor) { $queueMonitor.Stop(); $queueMonitor.Dispose() }
            if ($controles.WSTimer) { $controles.WSTimer.Stop(); $controles.WSTimer.Dispose() } 2>$null
            if ($controles.Form) { $controles.Form.Close(); $controles.Form.Dispose() } 2>$null
        } catch { }

        Write-Log "Bucle de sesión finalizado" -Tipo Success
    } catch { Write-Log "Error crítico en bucle de sesión: $_" -Tipo Error }
}

# ============================================================
# 🧾 INICIALIZACIÓN Y LIMPIEZA (Initialize-System / Clear-Resources)
# ============================================================
function Initialize-System {
    Write-Log "╔══════════════════════════════════════════════════════╗" -Tipo Info
    Write-Log "  SISTEMA DE AUTOPRÉSTAMOS - UNIVERSIDAD SIMÓN BOLÍVAR" -Tipo Info
    Write-Log "╚══════════════════════════════════════════════════════╝" -Tipo Info

    Write-Log "Detectando configuración de red..." -Tipo Info
    $networkInfo = Get-ActiveNetworkInterface
    if (-not $networkInfo) { Write-Log "No se pudo detectar la red. Abortando..." -Tipo Error; return $false }
    $Global:SharedState.MacAddress = $networkInfo.MAC

    if (-not (Test-Path $Global:Config.LogoPath)) { Write-Log "Logo no encontrado (se continuará sin logo)" -Tipo Warning }

    Write-Log "Estableciendo conexión WebSocket..." -Tipo Info
    Start-WebSocketProcess
    Write-Log "⏳ Esperando inicialización de WebSocket (3 seg)..." -Tipo Info
    Start-Sleep -Seconds 3
    if ($Global:SharedState.WebSocketConnected) { Write-Log "✅ WebSocket conectado" -Tipo Success } else { Write-Log "⚠️ WebSocket no conectado (funcionará en modo degradado)" -Tipo Warning }

    Write-Log "✅ Inicialización completada" -Tipo Success
    return $true
}

function Clear-Resources {
    Write-Log "🧹 Limpiando recursos..." -Tipo Info

    try {
        # Primero solicitar parada ordenada
        Stop-WebSocketProcess
    } catch {
        Write-Log "⚠️ Stop-WebSocketProcess falló: $_" -Tipo Warning
    }

    # Asegurar que no hay referencias colgantes
    try {
        # Señal final por si hay algún listener esperando
        try {
            if ($Global:SharedState.ContainsKey('OutgoingSignal') -and $Global:SharedState.OutgoingSignal) {
                $Global:SharedState.OutgoingSignal.Set() | Out-Null
            }
        } catch {}

        # Limpiar referencias globales para que GC pueda recoger
        $Global:SharedState.WSClientReference = $null
        $Global:SharedState.WebSocketConnected = $false

        # Forzar GC (opcional pero ayuda en procesos largos)
        [System.GC]::Collect()
        [System.GC]::WaitForPendingFinalizers()

        # Última sanidad: si aun quedan objetos PowerShell/Runspace, disponer
        try { if ($Global:WebSocketPowerShell) { $Global:WebSocketPowerShell.Dispose(); $Global:WebSocketPowerShell = $null } } catch {}
        try { if ($Global:WebSocketRunspace) { $Global:WebSocketRunspace.Dispose(); $Global:WebSocketRunspace = $null } } catch {}
    } catch {
        Write-Log "⚠️ Error en limpieza final: $_" -Tipo Warning
    }

    Write-Log "✅ Recursos liberados completamente" -Tipo Success
}

# ============================================================
# 🎬 PUNTO DE ENTRADA PRINCIPAL
# ============================================================
try {
    Clear-Host
    Write-Host ""
    Write-Host "╔══════════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║    SISTEMA DE AUTOPRÉSTAMOS - UNISIMÓN              ║" -ForegroundColor Cyan
    Write-Host "║    v2.3 - Arquitectura Dual Process                 ║" -ForegroundColor Cyan
    Write-Host "╚══════════════════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""

    $initialized = Initialize-System
    if (-not $initialized) { Write-Log "Fallo en la inicialización. Abortando..." -Tipo Error; exit 1 }

    Start-SessionLoop

    Write-Log "✅ Ejecución completada exitosamente" -Tipo Success
} catch {
    Write-Log "❌ Error crítico: $($_.Exception.Message)" -Tipo Error
    Write-Log "Stack Trace: $($_.ScriptStackTrace)" -Tipo Error
} finally {
    # Limpieza final por si acaso
    Clear-Resources
}
# ============================================================
# FIN - v2.3
# ============================================================