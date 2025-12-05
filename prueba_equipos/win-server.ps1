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
                            username = $Config.Username
                            nombre_equipo = $Config.IdEquipo
                            mac_address = $SharedState.MacAddress
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
            # Procesar cola de mensajes salientes
            try {
                while ($SharedState.ContainsKey('OutgoingQueue') -and $SharedState.OutgoingQueue.Count -gt 0) {
                    $out = $SharedState.OutgoingQueue.Dequeue()
                    Send-WSMessage -WsClient $WsClient -Payload $out | Out-Null
                }
            } catch { }

            try {
                $result = $WsClient.ReceiveAsync([ArraySegment[byte]]$buffer, [Threading.CancellationToken]::None).Result
                
                if ($result.Count -gt 0) {
                    $mensaje = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $result.Count)
                    Write-WSLog "📩 Recibido: $mensaje" -Tipo Info
                    
                    try {
                        $data = $mensaje | ConvertFrom-Json
                        
                        # Validar origen
                        if ($data.origen -ne "server") {
                            Write-WSLog "⛔ Origen no autorizado: $($data.origen)" -Tipo Warning
                            continue
                        }
                        
                        # ============================================================
                        # 🎯 MANEJO ESPECIAL DE RESPUESTA DE ESTADO
                        # ============================================================
                        if ($data.tipo -eq "respuesta_estado") {
                            Write-WSLog "📊 Respuesta de estado recibidaa: $($data.estado)" -Tipo Success
                            $SharedState.CommandQueue.Enqueue($data)
                            continue
                        }
                        
                        # ============================================================
                        # 🎯 MANEJO DE CONFIRMACIÓN DE REGISTRO
                        # ============================================================
                        if ($data.tipo -eq "confirmacion_registro") {
                            Write-WSLog "✅ Registro confirmado por servidor" -Tipo Success
                            # Si viene con respuesta_estado incluida, también encolarla
                            if ($data.estado) {
                                $SharedState.CommandQueue.Enqueue($data)
                            }
                            continue
                        }
                        
                        # Ping/Pong
                        if ($data.tipo -eq "ping") {
                            Send-WSMessage -WsClient $WsClient -Payload @{
                                tipo = "pong"
                                id = $Config.IdEquipo
                                timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                            } | Out-Null
                            Write-WSLog "🏓 Pong enviado" -Tipo Info
                            continue
                        }
                        
                        # Otros mensajes
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
            
            # Espera con señal
            try {
                if ($SharedState.ContainsKey('OutgoingSignal') -and $SharedState.OutgoingSignal) {
                    $null = $SharedState.OutgoingSignal.WaitOne(100)
                } else {
                    Start-Sleep -Milliseconds 100
                }
            } catch {
                Start-Sleep -Milliseconds 100
            }
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
            mac_address = $Global:SharedState.MacAddress
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
                            mac_address = $Global:SharedState.MacAddress
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
    Start-Sleep -Seconds 2
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
                                Start-Sleep -Seconds 3
                            break;}
                            "renovar"{
                                Invoke-EstadoRenovado -Controles $controles -Response $response
                                Start-Sleep -Seconds 3
                                Write-Host "Respuesta API renovación: $($resp | ConvertTo-Json -Compress)"
                            break;
                            }
                            "bloquear"{
                                                      # Si por alguna razón llega aquí, manejarlo
                        Invoke-EstadoBloqueadoIntentoAcceso -Controles $controles -Response $response
                        $Global:SharedState.SessionActive = $false
                        start-sleep -Seconds 3
                        break
                            }
                            "suspender"{
                                Invoke-PantallaCompleta -Controles $controles -Response $response
                                break;
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
    $labelInfo.Text = "Usuario: $($Global:Config.Username)`nMAC: $($Global:SharedState.MacAddress)"
    $form.Controls.Add($labelInfo)

    # --- TIMER ---
    $labelTimer = New-Object System.Windows.Forms.Label
    $labelTimer.Font = New-Object System.Drawing.Font("Segoe UI",14,[System.Drawing.FontStyle]::Bold)
    $labelTimer.Location = [System.Drawing.Point]::new(30,100)
    $labelTimer.AutoSize = $true
    $labelTimer.Text = "Inicializando..."
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
function Invoke-EstadoBloqueadoIntentoAcceso {
    param($Response)
    
    Write-Log "🚫 Usuario bloqueado intentando acceder" -Tipo Warning
    
    # Calcular hora de desbloqueo
    $fechaBloqueo = if ($Response.fecha_bloqueo) {
        [DateTime]::Parse($Response.fecha_bloqueo)
    } else {
        Get-Date
    }
    

    $horaDesbloqueo = $fechaBloqueo.AddMinutes($tiempoBloqueo)
    $minutosRestantes = [Math]::Ceiling(($horaDesbloqueo - (Get-Date)).TotalMinutes)
    
    if ($minutosRestantes -le 0) {
        Write-Log "✅ Bloqueo expirado, permitiendo acceso" -Tipo Success
        return $null # Permitir continuar
    }
    
    # Mostrar mensaje de bloqueo
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show(
        "🚫 TU CUENTA ESTÁ TEMPORALMENTE BLOQUEADA`n`n" +
        "⏰ Podrás iniciar sesión después de las: $($horaDesbloqueo.ToString('HH:mm'))`n" +
        "⏱️ Tiempo restante: $minutosRestantes minutos`n`n" +
        "Motivo: Sesión anterior cerrada sin renovación",
        "Acceso Bloqueado",
        "OK",
        "Warning"
    )
    start-sleep -Seconds 2
    
    Write-Log "⏳ Usuario debe esperar $minutosRestantes minutos más" -Tipo Warning
    
    # Retornar error para detener el flujo
    return @{ estado = "bloqueado"; debe_esperar = $true }
}
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
}

function Invoke-EstadoSuspendido {
    param($Controles, $Response)

    Write-Log "Estado: SUSPENDIDO - Pantalla de decisión" -Tipo Warning

    try {
        # =======================================
        # CONFIGURACIÓN INICIAL
        # =======================================
        $tiempoLimite = 120  # 2 minutos en segundos
        $script:tiempoRestante = $tiempoLimite
        $script:formClosed = $false
        $script:sesionRenovada = $false
        $script:cierreYaEnviado = $false
        $timerCountdown = $null
        $timerRespuestas = $null
        
        # =======================================
        # FUNCIÓN DE LIMPIEZA Y CIERRE
        # =======================================
        $cierreSesionAction = {
            param(
                [string]$Motivo = "manual"
            )
            
            if ($script:cierreYaEnviado) {
                return
            }
            
            $script:cierreYaEnviado = $true
            Write-Log "🚪 Cerrando sesión - Motivo: $Motivo" -Tipo Warning
            
            # Detener timers
            if ($timerCountdown) {
                $timerCountdown.Stop()
            }
            if ($timerRespuestas) {
                $timerRespuestas.Stop()
            }
            
            # Si la sesión no fue renovada, finalizar
            if (-not $script:sesionRenovada) {
                # Llamar al API para finalizar la sesión (checkin en FOLIO)
                $payload = @{
                    tipo = "finalizar"
                    username = $Global:Config.Username
                    mac_address = $Global:SharedState.MacAddress
                    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                    motivo = $Motivo
                }
                
                try {
                    $uri = "$($Global:Config.ApiUrl)?tipo=finalizar&username=$([uri]::EscapeDataString($Global:Config.Username))&mac_address=$([uri]::EscapeDataString($Global:SharedState.MacAddress))"
                    $response = Invoke-WebRequest -Uri $uri -Method POST -ContentType 'application/json' -Body ($payload | ConvertTo-Json) -TimeoutSec 5 -ErrorAction SilentlyContinue
                    
                    if ($response.StatusCode -eq 200) {
                        Write-Log "✅ Sesión finalizada correctamente - $Motivo" -Tipo Success
                    } else {
                        Write-Log "⚠️ Respuesta inesperada del servidor: $($response.StatusCode)" -Tipo Warning
                    }
                } catch {
                    Write-Log "⚠️ Error finalizando sesión: $_" -Tipo Warning
                }
                
                # Notificar al WebSocket
                $accion = if ($Motivo -eq "timeout") { "expirado" } else { "cerrar" }
                $payloadWS = @{
                    tipo = "solicitud"
                    origen = "equipo"
                    accion = $accion
                    nombre_equipo = $Global:Config.IdEquipo
                    username = $Global:Config.Username
                    mac_address = $Global:SharedState.MacAddress
                    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                    motivo = $Motivo
                }
                
                try {
                    $wsClient = $Global:SharedState.WSClientReference
                    if ($wsClient -and $wsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                        $json = $payloadWS | ConvertTo-Json -Depth 10 -Compress
                        $buffer = [System.Text.Encoding]::UTF8.GetBytes($json)
                        $segment = New-Object System.ArraySegment[byte] (,$buffer)
                        
                        $task = $wsClient.SendAsync(
                            $segment,
                            [System.Net.WebSockets.WebSocketMessageType]::Text,
                            $true,
                            [System.Threading.CancellationToken]::None
                        )
                        
                        $task.Wait(2000) | Out-Null
                        Write-Log "✅ Notificación de cierre enviada al WebSocket" -Tipo Success
                    }
                } catch {
                    Write-Log "⚠️ No se pudo notificar cierre al WebSocket: $_" -Tipo Warning
                }
                
                # Marcar sesión como inactiva
                $Global:SharedState.SessionActive = $false
            }
            
            # Cerrar el formulario si existe
            if ($form -and -not $form.IsDisposed) {
                $script:formClosed = $true
                $form.Close()
            }
        }
        
        # =======================================
        # CREAR FORM FULLSCREEN
        # =======================================
        $form = New-Object System.Windows.Forms.Form
        $form.WindowState = 'Maximized'
        $form.FormBorderStyle = 'None'
        $form.ControlBox = $false
        $form.TopMost = $true
        $form.BackColor = [System.Drawing.Color]::FromArgb(20, 20, 20)
        $form.KeyPreview = $true
        $form.ShowInTaskbar = $false
        
        # Bloquear teclas de salida
        $form.Add_KeyDown({
            if ($_.KeyCode -eq 'Escape' -or ($_.Control -and $_.KeyCode -eq 'F4')) {
                $_.Handled = $true
                $_.SuppressKeyPress = $true
            }
        })

        # Evento al cerrar el formulario
        $form.Add_FormClosing({
            param($sender, $e)
            
            # Si no se ha enviado cierre aún, hacerlo ahora
            if (-not $script:cierreYaEnviado) {
                & $cierreSesionAction -Motivo "manual"
            }
        })

        # =======================================
        # PANEL CENTRAL PRINCIPAL
        # =======================================
        $panelCentral = New-Object System.Windows.Forms.Panel
        $panelCentral.BackColor = [System.Drawing.Color]::FromArgb(40, 40, 40)
        $panelCentral.Dock = 'Fill'
        $panelCentral.AutoScroll = $false
        $form.Controls.Add($panelCentral)

        # =======================================
        # LOGO (SUPERIOR)
        # =======================================
        $logo = New-Object System.Windows.Forms.PictureBox
        $logo.SizeMode = 'StretchImage'
        $logo.Size = [System.Drawing.Size]::new(120, 120)
        $logo.Location = [System.Drawing.Point]::new(([System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width / 2) - 60, 40)
        
        if (Test-Path $Global:Config.LogoPath) {
            try {
                $logo.Image = [System.Drawing.Image]::FromFile($Global:Config.LogoPath)
            } catch { }
        }
        $panelCentral.Controls.Add($logo)

        # =======================================
        # TÍTULO PRINCIPAL
        # =======================================
        $labelTitulo = New-Object System.Windows.Forms.Label
        $labelTitulo.Text = "⏸️  SESIÓN SUSPENDIDA"
        $labelTitulo.Font = New-Object System.Drawing.Font("Segoe UI", 32, [System.Drawing.FontStyle]::Bold)
        $labelTitulo.ForeColor = [System.Drawing.Color]::White
        $labelTitulo.AutoSize = $false
        $labelTitulo.TextAlign = 'MiddleCenter'
        $labelTitulo.Location = [System.Drawing.Point]::new(0, 180)
        $labelTitulo.Size = [System.Drawing.Size]::new([System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width, 60)
        $panelCentral.Controls.Add($labelTitulo)

        # =======================================
        # MENSAJE INFORMATIVO
        # =======================================
        $labelMensaje = New-Object System.Windows.Forms.Label
        $labelMensaje.Text = "Tu sesión ha sido suspendida.`n`nDebes renovarla para continuar trabajando.`n`nElige una de las siguientes opciones:"
        $labelMensaje.Font = New-Object System.Drawing.Font("Segoe UI", 14)
        $labelMensaje.ForeColor = [System.Drawing.Color]::LightGray
        $labelMensaje.AutoSize = $false
        $labelMensaje.TextAlign = 'MiddleCenter'
        $labelMensaje.Location = [System.Drawing.Point]::new(100, 260)
        $labelMensaje.Size = [System.Drawing.Size]::new([System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width - 200, 100)
        $panelCentral.Controls.Add($labelMensaje)

        # =======================================
        # TIMER DISPLAY (BIEN VISIBLE)
        # =======================================
        $labelTimer = New-Object System.Windows.Forms.Label
        $labelTimer.Text = "⏱️  2:00"
        $labelTimer.Font = New-Object System.Drawing.Font("Segoe UI", 48, [System.Drawing.FontStyle]::Bold)
        $labelTimer.ForeColor = [System.Drawing.Color]::FromArgb(255, 200, 0)
        $labelTimer.AutoSize = $false
        $labelTimer.TextAlign = 'MiddleCenter'
        $labelTimer.Location = [System.Drawing.Point]::new(0, 370)
        $labelTimer.Size = [System.Drawing.Size]::new([System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width, 80)
        $panelCentral.Controls.Add($labelTimer)

        # =======================================
        # PANEL DE BOTONES
        # =======================================
        $panelBotones = New-Object System.Windows.Forms.Panel
        $panelBotones.BackColor = 'Transparent'
        $panelBotones.Location = [System.Drawing.Point]::new(0, 480)
        $panelBotones.Size = [System.Drawing.Size]::new([System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width, 200)
        $panelCentral.Controls.Add($panelBotones)

        # Ancho disponible y cálculo de posiciones
        $screenWidth = [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width
        $btnWidth = 250
        $spacing = 30
        $totalWidth = ($btnWidth * 3) + ($spacing * 2)
        $startX = ($screenWidth - $totalWidth) / 2

        # =======================================
        # BOTÓN 1: SOLICITAR RENOVACIÓN (VERDE)
        # =======================================
        $btnSolicitar = New-Object System.Windows.Forms.Button
        $btnSolicitar.Text = "📋 Solicitar Renovación`nAl Administrador"
        $btnSolicitar.Size = [System.Drawing.Size]::new($btnWidth, 150)
        $btnSolicitar.Location = [System.Drawing.Point]::new($startX, 25)
        $btnSolicitar.Font = New-Object System.Drawing.Font("Segoe UI", 11, [System.Drawing.FontStyle]::Bold)
        $btnSolicitar.BackColor = [System.Drawing.Color]::FromArgb(76, 175, 80)
        $btnSolicitar.ForeColor = [System.Drawing.Color]::White
        $btnSolicitar.FlatStyle = 'Flat'
        $btnSolicitar.FlatAppearance.BorderSize = 0
        $btnSolicitar.Cursor = 'Hand'
        $btnSolicitar.Add_MouseEnter({ $btnSolicitar.BackColor = [System.Drawing.Color]::FromArgb(56, 142, 60) })
        $btnSolicitar.Add_MouseLeave({ $btnSolicitar.BackColor = [System.Drawing.Color]::FromArgb(76, 175, 80) })
        
        $btnSolicitar.Add_Click({
            Write-Log "🟢 Botón: Solicitar Renovación" -Tipo Success
            $btnSolicitar.Enabled = $false
            
            # Enviar solicitud al servidor
            $payload = @{
                tipo = "solicitud"
                origen = "equipo"
                destino = "server"
                accion = "solicitar_renovacion"
                nombre_equipo = $Global:Config.IdEquipo
                username = $Global:Config.Username
                mac_address = $Global:SharedState.MacAddress
                timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
            }
            
            try {
                $wsClient = $Global:SharedState.WSClientReference
                if ($wsClient -and $wsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                    $json = $payload | ConvertTo-Json -Depth 10 -Compress
                    $buffer = [System.Text.Encoding]::UTF8.GetBytes($json)
                    $segment = New-Object System.ArraySegment[byte] (,$buffer)
                    
                    $task = $wsClient.SendAsync(
                        $segment,
                        [System.Net.WebSockets.WebSocketMessageType]::Text,
                        $true,
                        [System.Threading.CancellationToken]::None
                    )
                    
                    $task.Wait(3000) | Out-Null
                    Write-Log "✅ Solicitud de renovación enviada al dashboard" -Tipo Success
                    $btnSolicitar.Text = "✅ Solicitud Enviada`nEsperando Respuesta..."
                }
            } catch {
                Write-Log "❌ Error enviando solicitud: $_" -Tipo Error
                $btnSolicitar.Enabled = $true
            }
        })
        
        $panelBotones.Controls.Add($btnSolicitar)

        # =======================================
        # BOTÓN 2: RENOVAR CON CLAVE (AZUL)
        # =======================================
        $btnClave = New-Object System.Windows.Forms.Button
        $btnClave.Text = "🔑 Renovar con Clave`nde Administrador"
        $btnClave.Size = [System.Drawing.Size]::new($btnWidth, 150)
        $btnClave.Location = [System.Drawing.Point]::new($startX + $btnWidth + $spacing, 25)
        $btnClave.Font = New-Object System.Drawing.Font("Segoe UI", 11, [System.Drawing.FontStyle]::Bold)
        $btnClave.BackColor = [System.Drawing.Color]::FromArgb(33, 150, 243)
        $btnClave.ForeColor = [System.Drawing.Color]::White
        $btnClave.FlatStyle = 'Flat'
        $btnClave.FlatAppearance.BorderSize = 0
        $btnClave.Cursor = 'Hand'
        $btnClave.Add_MouseEnter({ $btnClave.BackColor = [System.Drawing.Color]::FromArgb(21, 101, 192) })
        $btnClave.Add_MouseLeave({ $btnClave.BackColor = [System.Drawing.Color]::FromArgb(33, 150, 243) })
        
        $btnClave.Add_Click({
            Write-Log "🔵 Botón: Renovar con Clave" -Tipo Success
            
            # Modal de entrada de clave
            $formClave = New-Object System.Windows.Forms.Form
            $formClave.Text = "Ingresar Clave de Administrador"
            $formClave.Size = [System.Drawing.Size]::new(400, 200)
            $formClave.StartPosition = 'CenterScreen'
            $formClave.FormBorderStyle = 'FixedDialog'
            $formClave.MaximizeBox = $false
            $formClave.MinimizeBox = $false
            $formClave.TopMost = $true
            $formClave.BackColor = [System.Drawing.Color]::FromArgb(40, 40, 40)
            
            # Label
            $lbl = New-Object System.Windows.Forms.Label
            $lbl.Text = "Ingresa la clave de administrador:"
            $lbl.ForeColor = [System.Drawing.Color]::White
            $lbl.Location = [System.Drawing.Point]::new(20, 20)
            $lbl.AutoSize = $true
            $formClave.Controls.Add($lbl)
            
            # TextBox (ocultar caracteres)
            $txtClave = New-Object System.Windows.Forms.TextBox
            $txtClave.UseSystemPasswordChar = $true
            $txtClave.Location = [System.Drawing.Point]::new(20, 50)
            $txtClave.Size = [System.Drawing.Size]::new(360, 30)
            $txtClave.Font = New-Object System.Drawing.Font("Segoe UI", 12)
            $txtClave.BackColor = [System.Drawing.Color]::FromArgb(60, 60, 60)
            $txtClave.ForeColor = [System.Drawing.Color]::White
            $formClave.Controls.Add($txtClave)
            
            # Botón Aceptar
            $btnAceptar = New-Object System.Windows.Forms.Button
            $btnAceptar.Text = "✅ Validar"
            $btnAceptar.Location = [System.Drawing.Point]::new(120, 100)
            $btnAceptar.Size = [System.Drawing.Size]::new(80, 35)
            $btnAceptar.BackColor = [System.Drawing.Color]::FromArgb(76, 175, 80)
            $btnAceptar.ForeColor = [System.Drawing.Color]::White
            $btnAceptar.FlatStyle = 'Flat'
            
            $btnAceptar.Add_Click({
                $claveIngresada = $txtClave.Text
                Write-Log "🔐 Clave ingresada, validando..." -Tipo Info
                
                # Enviar clave al servidor para validación y renovación
                $payload = @{
                    tipo = "comando_api"
                    accion = "validar_admin"
                    origen = "equipo"
                    nombre_equipo = $Global:Config.IdEquipo
                    username = $Global:Config.Username
                    mac_address = $Global:SharedState.MacAddress
                    clave_admin = $claveIngresada
                    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                }
                
                try {
                    $wsClient = $Global:SharedState.WSClientReference
                    if ($wsClient -and $wsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
                        $json = $payload | ConvertTo-Json -Depth 10 -Compress
                        $buffer = [System.Text.Encoding]::UTF8.GetBytes($json)
                        $segment = New-Object System.ArraySegment[byte] (,$buffer)
                        
                        $task = $wsClient.SendAsync(
                            $segment,
                            [System.Net.WebSockets.WebSocketMessageType]::Text,
                            $true,
                            [System.Threading.CancellationToken]::None
                        )
                        
                        $task.Wait(3000) | Out-Null
                        Write-Log "✅ Validación de clave enviada al servidor" -Tipo Success
                        $formClave.Close()
                    }
                } catch {
                    Write-Log "❌ Error enviando validación: $_" -Tipo Error
                }
            })
            
            $formClave.Controls.Add($btnAceptar)
            
            # Botón Cancelar
            $btnCancelarClave = New-Object System.Windows.Forms.Button
            $btnCancelarClave.Text = "❌ Cancelar"
            $btnCancelarClave.Location = [System.Drawing.Point]::new(210, 100)
            $btnCancelarClave.Size = [System.Drawing.Size]::new(80, 35)
            $btnCancelarClave.BackColor = [System.Drawing.Color]::FromArgb(244, 67, 54)
            $btnCancelarClave.ForeColor = [System.Drawing.Color]::White
            $btnCancelarClave.FlatStyle = 'Flat'
            $btnCancelarClave.Add_Click({ $formClave.Close() })
            $formClave.Controls.Add($btnCancelarClave)
            
            $formClave.ShowDialog() | Out-Null
        })
        
        $panelBotones.Controls.Add($btnClave)

        # =======================================
        # BOTÓN 3: CANCELAR (ROJO)
        # =======================================
        $btnCancelar = New-Object System.Windows.Forms.Button
        $btnCancelar.Text = "❌ Cancelar`nSesión"
        $btnCancelar.Size = [System.Drawing.Size]::new($btnWidth, 150)
        $btnCancelar.Location = [System.Drawing.Point]::new($startX + ($btnWidth + $spacing) * 2, 25)
        $btnCancelar.Font = New-Object System.Drawing.Font("Segoe UI", 11, [System.Drawing.FontStyle]::Bold)
        $btnCancelar.BackColor = [System.Drawing.Color]::FromArgb(244, 67, 54)
        $btnCancelar.ForeColor = [System.Drawing.Color]::White
        $btnCancelar.FlatStyle = 'Flat'
        $btnCancelar.FlatAppearance.BorderSize = 0
        $btnCancelar.Cursor = 'Hand'
        $btnCancelar.Add_MouseEnter({ $btnCancelar.BackColor = [System.Drawing.Color]::FromArgb(211, 47, 47) })
        $btnCancelar.Add_MouseLeave({ $btnCancelar.BackColor = [System.Drawing.Color]::FromArgb(244, 67, 54) })
        
        $btnCancelar.Add_Click({
            Write-Log "🔴 Botón: Cancelar Sesión" -Tipo Warning
            & $cierreSesionAction -Motivo "cancelado"
        })
        
        $panelBotones.Controls.Add($btnCancelar)

        # =======================================
        # TIMER PARA PROCESAR RESPUESTAS
        # (Hilo separado - frecuencia más baja)
        # =======================================
        $timerRespuestas = New-Object System.Windows.Forms.Timer
        $timerRespuestas.Interval = 300
        $timerRespuestas.Add_Tick({
            try {
                # Evitar procesamiento si ya se cerró
                if ($script:cierreYaEnviado) {
                    return
                }
                
                while ($Global:SharedState.CommandQueue.Count -gt 0) {
                    $comando = $Global:SharedState.CommandQueue.Dequeue()
                    
                    # Respuesta de solicitud de renovación (desde dashboard)
                    if ($comando.tipo -eq "respuesta_solicitud_renovacion") {
                        $estado = $comando.estado
                        Write-Log "📨 Respuesta de solicitud: $estado" -Tipo Success
                        
                        if ($estado -eq "aceptada") {
                            [System.Windows.Forms.MessageBox]::Show(
                                "✅ El administrador aprobó tu solicitud.`n`nTu sesión ha sido renovada. ¡Puedes continuar!",
                                "Renovación Aprobada",
                                "OK",
                                "Information"
                            )
                            $script:sesionRenovada = $true
                            & $cierreSesionAction -Motivo "renovado"
                        } elseif ($estado -eq "rechazada") {
                            [System.Windows.Forms.MessageBox]::Show(
                                "❌ El administrador rechazó tu solicitud.`n`nTu sesión será finalizada.",
                                "Solicitud Rechazada",
                                "OK",
                                "Warning"
                            )
                            & $cierreSesionAction -Motivo "rechazado"
                        }
                    }
                    
                    # Respuesta de validación de clave (desde API)
                    if ($comando.tipo -eq "confirmacion_comando" -and $comando.accion -eq "validar_admin") {
                        $estado = $comando.estado
                        Write-Log "🔐 Respuesta de validación de clave: $estado" -Tipo Success
                        
                        if ($estado -eq "Renovado") {
                            [System.Windows.Forms.MessageBox]::Show(
                                "✅ Clave validada correctamente.`n`nTu sesión ha sido renovada. ¡Puedes continuar!",
                                "Renovación Exitosa",
                                "OK",
                                "Information"
                            )
                            $script:sesionRenovada = $true
                            & $cierreSesionAction -Motivo "renovado"
                        } else {
                            [System.Windows.Forms.MessageBox]::Show(
                                "❌ Clave incorrecta o error en validación.`n`nIntenta nuevamente.",
                                "Error de Validación",
                                "OK",
                                "Error"
                            )
                            $btnClave.Enabled = $true
                        }
                    }
                }
            } catch {
                Write-Log "⚠️ Error procesando respuestas: $_" -Tipo Warning
            }
        })
        $timerRespuestas.Start()

        # =======================================
        # TIMER DE COUNTDOWN (2 MINUTOS)
        # (Hilo completamente separado - frecuencia exacta)
        # =======================================
        $timerCountdown = New-Object System.Windows.Forms.Timer
        $timerCountdown.Interval = 1000  # Exactamente 1 segundo
        $timerCountdown.Add_Tick({
            try {
                # Evitar decrementar si ya se cerró
                if ($script:cierreYaEnviado) {
                    return
                }
                
                # Decrementar ANTES de actualizar UI
                $script:tiempoRestante--
                
                # Verificar si llegó a cero
                if ($script:tiempoRestante -le 0) {
                    Write-Log "⏰ Tiempo agotado (2 minutos) - Cerrando automáticamente" -Tipo Warning
                    & $cierreSesionAction -Motivo "timeout"
                    return
                }
                
                # Calcular minutos y segundos
                $minutos = [Math]::Floor($script:tiempoRestante / 60)
                $segundos = $script:tiempoRestante % 60
                
                # Cambiar color según tiempo restante
                if ($script:tiempoRestante -le 30) {
                    $labelTimer.ForeColor = [System.Drawing.Color]::FromArgb(255, 87, 34)  # Rojo-naranja
                } elseif ($script:tiempoRestante -le 60) {
                    $labelTimer.ForeColor = [System.Drawing.Color]::FromArgb(255, 152, 0)  # Naranja
                } else {
                    $labelTimer.ForeColor = [System.Drawing.Color]::FromArgb(255, 200, 0)  # Amarillo
                }
                
                # Actualizar display con formato correcto
                $labelTimer.Text = "⏱️  $($minutos):$($segundos.ToString('00'))"
                
            } catch {
                Write-Log "⚠️ Error en timer countdown: $_" -Tipo Warning
            }
        })
        $timerCountdown.Start()

        # =======================================
        # MOSTRAR FORM
        # =======================================
        $result = $form.ShowDialog()

        # =======================================
        # LIMPIEZA FINAL
        # =======================================
        if ($timerCountdown) {
            $timerCountdown.Stop()
            $timerCountdown.Dispose()
        }
        if ($timerRespuestas) {
            $timerRespuestas.Stop()
            $timerRespuestas.Dispose()
        }
        
        Write-Log "✅ Pantalla de suspensión cerrada correctamente" -Tipo Success
        
    } catch {
        Write-Log "❌ Error en estado suspendido: $_" -Tipo Error
    }
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
    param(
        [hashtable]$Controles,
        [hashtable]$Response
    )

    Write-Log "🔚 Ejecutando estado finalizado..." -Tipo Info

    # Aquí va tu lógica
    try {
        if ($Global:AutoInicio -eq $true) {
            Write-Log "⏳ No cerramos el shell porque auto-inicio está activo" -Tipo Info
            return
        } else {
            Write-Log "Estado: FINALIZADO - Sesión completada" -Tipo Success

            # Actualizar UI con estado final
            try {
                $Controles.LabelInfo.ForeColor = [System.Drawing.Color]::Blue
                $Controles.LabelTimer.Text = "✅ Sesión finalizada correctamente"
                $Controles.LabelTimer.ForeColor = [System.Drawing.Color]::Green
                $Controles.Form.Refresh()
            } catch {
                # Si los controles ya no existen, no interrumpimos el flujo
            }

            # Enviar confirmación al servidor para evitar re-intentos o reenvíos
            try {
                if (Get-Command -Name Send-Confirmacion -ErrorAction SilentlyContinue) {
                    Send-Confirmacion -Resultado "finalizado" -Mensaje "Sesión finalizada localmente"
                }
            } catch {
                Write-Log "⚠️ Error enviando confirmación al servidor: $_" -Tipo Warning
            }

            # Dar un breve margen para que el servidor procese la finalización
            Start-Sleep -Seconds 3

            # Marca global para que otros timers/loops sepan que la sesión terminó
            $Global:SessionTerminated = Get-Date

            # Cerrar formulario de forma ordenada
            try {
                $Controles.Form.Close()
            } catch {
                Write-Log "⚠️ Error cerrando formulario (se ignorará): $_" -Tipo Warning
            }
        }
    }
    catch {
        Write-Log "Error cerrando formulario: $_" -Tipo Error
    }
}

# ============================================================
# 🔄 NUEVA FUNCIÓN: Solicitar Estado via WebSocket
# ============================================================
function Request-EstadoViaWS {
    param(
        [int]$TimeoutSeconds = 30
    )
    
    Write-Log "📡 Solicitando estado via WebSocket..." -Tipo Info
    
    # Verificar que WebSocket esté conectado
    $wsClient = $Global:SharedState.WSClientReference
    if (-not $wsClient -or $wsClient.State -ne [System.Net.WebSockets.WebSocketState]::Open) {
        Write-Log "❌ WebSocket no conectado" -Tipo Error
        return @{
            estado = "Error"
            mensaje = "WebSocket no conectado"
        }
    }
    
    # Crear payload de solicitud
    $payload = @{
        tipo = "solicitar_estado"
        nombre_equipo = $Global:Config.IdEquipo
        username = $Global:Config.Username
        mac_address = $Global:SharedState.MacAddress
        origen = "equipo"
        destino = "server"
        timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    }
    
    try {
        # Enviar solicitud
        $json = $payload | ConvertTo-Json -Compress
        $buffer = [System.Text.Encoding]::UTF8.GetBytes($json)
        $segment = New-Object System.ArraySegment[byte] (,$buffer)
        
        $sendTask = $wsClient.SendAsync(
            $segment,
            [System.Net.WebSockets.WebSocketMessageType]::Text,
            $true,
            [System.Threading.CancellationToken]::None
        )
        
        $sendCompleted = $sendTask.Wait(3000)
        if (-not $sendCompleted) {
            Write-Log "⚠️ Timeout enviando solicitud" -Tipo Warning
            return @{ estado = "Error"; mensaje = "Timeout al enviar solicitud" }
        }
        
        Write-Log "✅ Solicitud de estado enviada" -Tipo Success
        
        # Esperar respuesta en la cola de comandos
        $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $timeoutMs = $TimeoutSeconds * 1000
        
        while ($stopwatch.ElapsedMilliseconds -lt $timeoutMs) {
            # Revisar cola de comandos
            if ($Global:SharedState.CommandQueue.Count -gt 0) {
                try {
                    $respuesta = $Global:SharedState.CommandQueue.Dequeue()
                    
                    # Convertir PSCustomObject a Hashtable si es necesario
                    if ($respuesta -is [PSCustomObject]) {
                        $respuesta = ConvertTo-Hashtable $respuesta
                    }
                    
                    # Verificar si es la respuesta de estado
                    if ($respuesta.tipo -eq "respuesta_estado") {
                        Write-Log "📥 Respuesta de estado recibida: $($respuesta.estado)" -Tipo Success
                        return $respuesta
                    }
                    
                    # Si no es respuesta de estado, volver a encolar
                    $Global:SharedState.CommandQueue.Enqueue($respuesta)
                    
                } catch {
                    Write-Log "⚠️ Error procesando cola: $_" -Tipo Warning
                }
            }
            
            # Pequeña pausa para no saturar CPU
            Start-Sleep -Milliseconds 50
        }
        
        Write-Log "⏰ Timeout esperando respuesta de estado" -Tipo Warning
        return @{
            estado = "Error"
            mensaje = "Timeout esperando respuesta del servidor"
        }
        
    } catch {
        Write-Log "❌ Error solicitando estado: $_" -Tipo Error
        return @{
            estado = "Error"
            mensaje = "Error de comunicación: $_"
        }
    }
}

# ============================================================
# 🔄 FUNCIÓN Start-SessionLoop MODIFICADA
# ============================================================
function Start-SessionLoop {
    Write-Log "Iniciando bucle principal de sesión..." -Tipo Info
    
    try {
        # Verificar que WebSocket esté conectado
        if (-not $Global:SharedState.WebSocketConnected) {
            Write-Log "❌ WebSocket no conectado, esperando..." -Tipo Warning
            
            $waited = 0
            while (-not $Global:SharedState.WebSocketConnected -and $waited -lt 10) {
                Start-Sleep -Seconds 1
                $waited++
            }
            
            if (-not $Global:SharedState.WebSocketConnected) {
                Write-Log "❌ No se pudo establecer conexión WebSocket" -Tipo Error
                [System.Windows.Forms.MessageBox]::Show(
                    "No se pudo conectar al servidor.`n`nVerifica que el servidor WebSocket esté en ejecución.",
                    "Error de Conexión",
                    "OK",
                    "Error"
                )
                return
            }
        }
        
        # Crear controles de UI
        $controles = New-SessionForm
        
        # ============================================================
        # 📡 SOLICITAR ESTADO INICIAL VIA WEBSOCKET
        # ============================================================
        Write-Log "📡 Obteniendo estado inicial via WebSocket..." -Tipo Info
        
        $reintentos = 0
        $maxReintentos = 2
        while ($reintentos -lt $maxReintentos) {
            $response = Request-EstadoViaWS -TimeoutSeconds 30
            if ($null -ne $response -and $response.estado) {
                break
            }
            $reintentos++
            if ($reintentos -lt $maxReintentos) {
                Start-Sleep -Seconds 2
            }
        }
        
        # Validar respuesta
        if (-not $response) {
            Write-Log "❌ No se recibió respuesta del servidor" -Tipo Error
            [System.Windows.Forms.MessageBox]::Show(
                "No se pudo obtener respuesta del servidor.`n`nIntenta nuevamente más tarde.",
                "Error de Comunicación",
                "OK",
                "Error"
            )
            return
        }
        
        if ($response.estado -eq "Error") {
            Write-Log "❌ Error en respuesta: $($response.mensaje)" -Tipo Error
            [System.Windows.Forms.MessageBox]::Show(
                "Error del servidor:`n`n$($response.mensaje)",
                "Error del Sistema",
                "OK",
                "Error"
            )
            return
        }
        
        Write-Log "✅ Estado inicial recibido: $($response.estado)" -Tipo Success

        # ============================================================
        # 🚀 DETECTAR AUTO-INICIO
        # ============================================================
        if ($response.estado -eq "Finalizado" -and $response.auto_iniciada -eq $true) {
            Write-Log "🚀 Sesión auto-iniciada detectada" -Tipo Success
            # Mostrar notificación breve
            $controles.LabelTimer.ForeColor = [System.Drawing.Color]::DarkGreen
            $controles.LabelTimer.Text = "🟢 Sesión iniciada automáticamente"
            $controles.Form.Refresh()  
            Start-Sleep -Seconds 2
        }

        # ============================================================
        # ⚠️ MANEJAR ESTADOS TERMINALES ANTES DEL BUCLE
        # ============================================================
        
        # Si el estado inicial es Finalizado y NO hay auto-inicio, salir inmediatamente
        if ($response.estado -eq "Finalizado" -and -not $response.auto_iniciada) {
            Write-Log "🏁 Estado inicial es Finalizado sin auto-inicio, saliendo..." -Tipo Info
            
            # Mostrar mensaje breve
            $controles.LabelInfo.ForeColor = [System.Drawing.Color]::Blue
            $controles.LabelTimer.Text = "✅ Sesión finalizada - Puedes cerrar esta ventana"
            $controles.LabelTimer.ForeColor = [System.Drawing.Color]::Green
            $controles.Form.Show()
            $controles.Form.Refresh()
            
            Start-Sleep -Seconds 3
            
            # Cerrar y salir
            try {
                if ($controles.Form -and -not $controles.Form.IsDisposed) {
                    $controles.Form.Close()
                    $controles.Form.Dispose()
                }
            } catch { }
            
            Write-Log "✅ Programa finalizado correctamente" -Tipo Success
            return
        }

        # Manejar caso de bloqueo al inicio
        if ($response.estado -eq "Bloqueado") {
            Write-Log "🚫 Usuario bloqueado al intentar iniciar sesión" -Tipo Warning
            
            $bloqueadoHasta =$response.bloqueado_hasta;
            
            $minutosRestantes = [Math]::Ceiling(($bloqueadoHasta - (Get-Date)).TotalMinutes)
            
            if ($minutosRestantes -gt 0) {
                [System.Windows.Forms.MessageBox]::Show(
                    "🚫 TU CUENTA ESTÁ TEMPORALMENTE BLOQUEADA`n`n" +
                    "⏰ Podrás iniciar sesión después de las: $bloqueadoHasta`n" +
                    "⏱️ Tiempo restante: $minutosRestantes minutos`n`n" +
                    "Motivo: Sesión anterior cerrada sin renovación",
                    "Acceso Bloqueado",
                    "OK",
                    "Warning"
                )
                
                Write-Log "⏳ Usuario debe esperar $minutosRestantes minutos" -Tipo Warning
                
                # Cerrar formulario y salir
                try {
                    if ($controles.Form -and -not $controles.Form.IsDisposed) {
                        $controles.Form.Close()
                        $controles.Form.Dispose()
                    }
                } catch { }
                
                return
            }
        }
        
        # Manejar usuario restringido en FOLIO
        if ($response.estado -eq "Restringido") {
            Write-Log "🚫 Usuario restringido en FOLIO" -Tipo Warning
            
            [System.Windows.Forms.MessageBox]::Show(
                $response.mensaje,
                "Usuario Restringido",
                "OK",
                "Warning"
            )
            
            # Cerrar formulario y salir
            try {
                if ($controles.Form -and -not $controles.Form.IsDisposed) {
                    $controles.Form.Close()
                    $controles.Form.Dispose()
                }
            } catch { }
            
            return
        }

        # Monitor de comandos WebSocket
        $queueMonitor = Start-CommandQueueMonitor
        
        # Mostrar formulario
        $controles.Form.Show()

        # ============================================================
        # BUCLE PRINCIPAL DE ESTADOS - SOLO PARA ESTADOS ACTIVOS
        # ============================================================
        Write-Log "🔄 Iniciando bucle de procesamiento de estados" -Tipo Info
        
        $iteraciones = 0
        $maxIteraciones = 1000
        $debeTerminar = $false
        
        # Solo entrar al bucle si el estado NO es terminal
        while ($response -and 
               -not $debeTerminar -and
               $iteraciones -lt $maxIteraciones) {
            
            $iteraciones++
            Write-Log "Estado actual (#$iteraciones): $($response.estado)" -Tipo Info
            
            try {
                switch ($response.estado) {
                    "Abierto" {
                        Write-Log "🟢 Procesando estado: ABIERTO" -Tipo Success
                        Invoke-EstadoAbierto -Controles $controles -Response $response
                        # Solicitar nuevo estado via WebSocket
                        Write-Log "📡 Solicitando nuevo estado..." -Tipo Info
                        $response = Request-EstadoViaWS -TimeoutSeconds 15
                    }
                    
                    "Suspendido" {
                        Write-Log "🟡 Procesando estado: SUSPENDIDO" -Tipo Warning
                        Invoke-EstadoSuspendido -Controles $controles -Response $response
                        
                        # Solicitar nuevo estado
                        Write-Log "📡 Solicitando nuevo estado..." -Tipo Info
                        $response = Request-EstadoViaWS -TimeoutSeconds 15
                    }
                    
                    "Renovado" {
                        Write-Log "🔄 Procesando estado: RENOVADO" -Tipo Success
                        Invoke-EstadoRenovado -Controles $controles -Response $response
                        
                        # Solicitar nuevo estado
                        $response = Request-EstadoViaWS -TimeoutSeconds 15
                    }
                    
                    "Bloqueado" {
                        Write-Log "🚫 Procesando estado: BLOQUEADO (durante sesión)" -Tipo Warning
                        # Mostrar mensaje de bloqueo
                        $bloqueadoHasta = if ($response.bloqueado_hasta) {
                            try { [DateTime]::Parse($response.bloqueado_hasta) }
                            catch { (Get-Date).AddMinutes(10) }
                        } else {
                            (Get-Date).AddMinutes(10)
                        }
                        
                        $minutosRestantes = [Math]::Ceiling(($bloqueadoHasta - (Get-Date)).TotalMinutes)
                        
                        [System.Windows.Forms.MessageBox]::Show(
                            "🚫 Tu cuenta ha sido bloqueada temporalmente.`n`n" +
                            "⏰ Podrás volver a iniciar sesión después de las: $($bloqueadoHasta.ToString('HH:mm'))`n" +
                            "⏱️ Tiempo restante: $minutosRestantes minutos",
                            "Cuenta Bloqueada",
                            "OK",
                            "Warning"
                        )
                        start-sleep -Seconds 2
                        
                        # Marcar para salir del bucle
                        $debeTerminar = $true
                    }
                    
                    "Finalizado" {
                            # Si server/Api dice que puede iniciar o que auto_iniciada true => no limpiar
                        if ($response.puede_iniciar -eq $true -or $response.auto_iniciada -eq $true) {
                            Write-Log "🚀 Auto-inicio disponible. Solicitar inicio o aceptar respuesta auto_iniciada" -Tipo Info

                            # Si server ya auto_inició, te llegó 'Abierto' mezclado; si no, pide iniciar
                            if ($response.auto_iniciada -eq $true -or $response.estado -eq 'Abierto') {
                                Invoke-EstadoAbierto -Controles $controles -Response $response
                                continue
                            } else {
                                # intentar pedir iniciar_auto al server
                                $cmd = @{ tipo='comando_api'; accion='iniciar_auto'; origen='equipo'; username=$Global:Config.Username; mac_address=$Global:SharedState.MacAddress }
                                $resp = Invoke-Api -Payload $cmd
                                if ($resp.estado -eq 'Abierto' -or $resp.auto_iniciada -eq $true) {
                                    Invoke-EstadoAbierto -Controles $controles -Response $resp
                                    continue
                                } else {
                                    Write-Log "❌ Auto-inicio denegado por server: $($resp.mensaje)" -Tipo Warning
                                }
                            }
                        }

                        # Si no puede iniciar -> flujo normal de finalizado y limpieza
                        Invoke-EstadoFinalizado -Controles $controles -Response $response
                        break
                    }
                    
                    "Restringido" {
                        Write-Log "🚫 Usuario restringido durante la sesión" -Tipo Warning
                        
                        [System.Windows.Forms.MessageBox]::Show(
                            $response.mensaje,
                            "Usuario Restringido",
                            "OK",
                            "Warning"
                        )
                        
                        # Marcar para salir del bucle
                        $debeTerminar = $true
                    }
                    
                    "Error" {
                        Write-Log "❌ Estado de error recibido: $($response.mensaje)" -Tipo Error
                        
                        [System.Windows.Forms.MessageBox]::Show(
                            "Error del sistema:`n`n$($response.mensaje)",
                            "Error",
                            "OK",
                            "Error"
                        )
                        
                        # Marcar para salir del bucle
                        $debeTerminar = $true
                    }
                    
                    default {
                        Write-Log "❓ Estado desconocido: $($response.estado)" -Tipo Warning
                        Start-Sleep -Seconds 2
                        $response = Request-EstadoViaWS -TimeoutSeconds 15
                    }
                }
                
                # Procesar eventos de UI
                [System.Windows.Forms.Application]::DoEvents()
                
            } catch {
                Write-Log "❌ Error procesando estado: $_" -Tipo Error
                Write-Log "Stack: $($_.ScriptStackTrace)" -Tipo Error
                Start-Sleep -Seconds 2
                
                try {
                    $response = Request-EstadoViaWS -TimeoutSeconds 15
                } catch {
                    Write-Log "❌ No se pudo recuperar: $_" -Tipo Error
                    $debeTerminar = $true
                }
            }
            
            Start-Sleep -Milliseconds 200
        }

        # ============================================================
        # LIMPIEZA
        # ============================================================
        Write-Log "🧹 Limpieza de recursos de sesión" -Tipo Info
        
        try {
            if ($queueMonitor) {
                $queueMonitor.Stop()
                $queueMonitor.Dispose()
                Write-Log "✅ Queue monitor detenido" -Tipo Success
            }
        } catch {
            Write-Log "⚠️ Error deteniendo monitor: $_" -Tipo Warning
        }
        
        try {
            if ($controles.Form -and -not $controles.Form.IsDisposed) {
                $controles.Form.Close()
                $controles.Form.Dispose()
                Write-Log "✅ Formulario cerrado" -Tipo Success
            }
        } catch {
            Write-Log "⚠️ Error cerrando formulario: $_" -Tipo Warning
        }

        Write-Log "✅ Bucle de sesión finalizado correctamente" -Tipo Success
        
    } catch {
        Write-Log "❌ Error crítico: $($_.Exception.Message)" -Tipo Error
        Write-Log "Stack: $($_.ScriptStackTrace)" -Tipo Error
        
        try {
            if ($controles -and $controles.Form -and -not $controles.Form.IsDisposed) {
                $controles.Form.Close()
            }
        } catch { }
    }
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