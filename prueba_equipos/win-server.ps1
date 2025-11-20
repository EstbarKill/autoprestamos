# ============================================================
# 🎓 SISTEMA DE AUTOPRÉSTAMOS - ARQUITECTURA DUAL PROCESS
# ============================================================
# Versión: 2.3 (Mejoras: hibernación global, runspace limpio, limpieza)
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
    OutgoingQueue           = [System.Collections.Queue]::Synchronized((New-Object System.Collections.Queue))
    OutgoingSignal          = [System.Threading.AutoResetEvent]::new($false)
    # Timeouts / Durations (valores por defecto razonables)
INACTIVITY_TIMEOUT = 600 # segundos de inactividad antes de hibernar (10 min)
HIBERNATION_MAX_DURATION = 300 # segundos max en hibernación antes de finalizar (5 min)
IsHibernating = $false
HibernationStartTime = $null
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
                # Procesar cola de mensajes salientes (si existen)
                try {
                        $destino = $data.destino_equipo
    if ($null -ne $destino -and $destino -ne 'todos') {
        # comparar con el identificador del equipo (Config.IdEquipo) y con nombre del host esperado
        if (($destino -ne $Config.IdEquipo) -and ($destino -ne $SharedState.NombreEquipo) -and ($destino -ne $env:COMPUTERNAME)) {
            Write-WSLog "🔇 Mensaje destinado a $destino — no es este equipo ($($Config.IdEquipo)). Ignorando." -Tipo Info
            continue
        }
    }
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
    $Global:SharedState.SessionActive = $false
    Start-Sleep -Seconds 2

    if ($Global:WebSocketPowerShell) {
        try {
            $Global:WebSocketPowerShell.Stop()
            $Global:WebSocketPowerShell.Dispose()
        } catch { }
    }
    if ($Global:WebSocketRunspace) {
        try {
            $Global:WebSocketRunspace.Close()
            $Global:WebSocketRunspace.Dispose()
        } catch { }
    }
    Write-Log "✅ Proceso WebSocket detenido" -Tipo Success
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
            nombre_eq = $Global:Config.IdEquipo
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
                            nombre_eq = $Global:Config.IdEquipo
                            accion = $Accion
                            resultado = $Resultado
                            mensaje = $Mensaje
                            timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                        }
                        if ($Detalles.corr) { $payload.corr = $Detalles.corr }
                        Enqueue-WSMessage -Payload $payload | Out-Null
                        Write-Log "✅ Confirmación encolada para envío" -Tipo Success
                    } else { Write-Log "⚠️ WebSocket no disponible para encolar confirmación" -Tipo Warning }
        } catch { Write-Log "⚠️ Error al enviar confirmación: $_" -Tipo Warning }
    }

    switch ($Accion) {
        "bloquear" {
            Write-Log "🔒 Bloqueando sesion..." -Tipo Warning
            $apiResp = Invoke-ApiCall -ExtraBody @{ accion = 'bloquear'; corr = $Detalles.corr }
            Send-Confirmacion -Resultado "ejecutando" -Mensaje "Bloqueando equipo..."
            Start-Sleep -Milliseconds 500
            try { Send-Confirmacion -Resultado "ejecutado" -Mensaje "Equipo bloqueado exitosamente" } catch { Send-Confirmacion -Resultado "error" -Mensaje "Error al bloquear: $_" }
        }
        "suspender" {
            Write-Log "💤 Suspendiendo equipo..." -Tipo Warning
            $apiResp = Invoke-ApiCall -ExtraBody @{ accion = 'suspender'; corr = $Detalles.corr }
            Send-Confirmacion -Resultado "ejecutando" -Mensaje "Preparando suspensión..."
            try {
                [System.Windows.Forms.MessageBox]::Show(
                    "El equipo se suspenderá en 10 segundos.`n¿Desea guardar su trabajo?",
                    "Suspensión Programada",
                    [System.Windows.Forms.MessageBoxButtons]::OK,
                    [System.Windows.Forms.MessageBoxIcon]::Warning
                ) | Out-Null
                Start-Sleep -Seconds 10
                Send-Confirmacion -Resultado "ejecutado" -Mensaje "Suspendiendo..."
            } catch { Send-Confirmacion -Resultado "error" -Mensaje "Error al suspender: $_" }
        }
        "finalizar" {
            Write-Log "⛔ Iniciando flujo FINALIZAR (API -> UI -> CONFIRM)" -Tipo Info
            $apiResp = Invoke-ApiCall -ExtraBody @{estado_comando = 'true'; accion = 'finalizar'; corr = $Detalles.corr }
            if ($null -eq $apiResp -or $apiResp.estado -eq 'Error') {
                Write-Log "❌ API fallo al finalizar: $($apiResp.mensaje)" -Tipo Error
                Send-Confirmacion -Resultado "error" -Mensaje ("API error: " + ($apiResp.mensaje -as [string]))
            }
            if ($apiResp.estado -match 'FINALIZADO' -or $apiResp.estado -match 'OK') {
                [System.Windows.Forms.MessageBox]::Show("Su sesión fue finalizada. Gracias.","Finalizado",[System.Windows.Forms.MessageBoxButtons]::OK,[System.Windows.Forms.MessageBoxIcon]::Information)
                Send-Confirmacion -Resultado "ejecutado" -Mensaje "Finalizado con check-in en FOLIO"
                Start-Sleep -Seconds 3
            } else { Send-Confirmacion -Resultado "error" -Mensaje ("Respuesta API inesperada: " + ($apiResp.mensaje -as [string])) }
        }
        "renovar" {
            Write-Log "♻️ Sesión renovada" -Tipo Success
            $apiResp = Invoke-ApiCall -ExtraBody @{ accion = 'renovar'; corr = $Detalles.corr }
            Send-Confirmacion -Resultado "ejecutado" -Mensaje "Renovación confirmada"
            try { [System.Windows.Forms.MessageBox]::Show("✅ Su sesión ha sido renovada correctamente.","Renovación Exitosa") | Out-Null } catch { }
        }
        "mensaje" {
            $texto = if ($Detalles.texto) { $Detalles.texto } else { "Mensaje del administrador" }
            Write-Log "💬 Mostrando mensaje {$texto}" -Tipo Info
            try {
                [System.Windows.Forms.MessageBox]::Show($texto,"Notificación del Sistema",[System.Windows.Forms.MessageBoxButtons]::OK,[System.Windows.Forms.MessageBoxIcon]::Information) | Out-Null
                Send-Confirmacion -Resultado "ejecutado" -Mensaje "Mensaje mostrado"
            } catch { Send-Confirmacion -Resultado "error" -Mensaje "Error al mostrar mensaje" }
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
        if ($comando -is [PSCustomObject]) { 
            $comando = ConvertTo-Hashtable $comando 
        }
        
        # ⚡ FORMATO NUEVO DEL SERVER.PHP (manejo = comandos)
        if ($comando.tipo -eq "control_server") {
            if ($comando.destino -eq "shell") {
            switch ($comando.manejo) {
                "comandos" {
                    $detalles = ConvertTo-Hashtable $comando
                    Invoke-AccionControl -Accion $comando.accion -Detalles $detalles
                }
                "mensaje" {
                    Invoke-AccionControl -Accion "mensaje" -Detalles @{ texto = $comando.texto }
                }
                "info" {
                    Invoke-AccionControl -Accion "ver_info"
                }
                default {
                    Write-Log "⚠️ Manejo desconocido: $($comando.manejo)" -Tipo Warning
                }
            }
            break
        }

        # 🧩 SOPORTE LEGACY
        if ($comando.tipo -eq "control_server") {
            $detalles = ConvertTo-Hashtable $comando
            Invoke-AccionControl -Accion $comando.accion -Detalles $detalles
            continue
        }

        Write-Log "⚠️ Comando no reconocido: $($comando.tipo)" -Tipo Warning
    }
}
    catch {
        Write-Log "❌ Error procesando comando: $_" -Tipo Error
    }
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
        destino     = "server"
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

# ============================================================
# 🖥️ INTERFAZ GRÁFICA (Formulario de sesión)
# ============================================================
function New-SessionForm {
    $form = New-Object System.Windows.Forms.Form
    $form.Text = "Gestión de Sesión - AutoPréstamos"
    $form.Size = [System.Drawing.Size]::new(400,200)
    $form.StartPosition = "Manual"
    $form.FormBorderStyle = [System.Windows.Forms.FormBorderStyle]::FixedDialog
    $form.ShowInTaskbar = $false
    $form.MinimizeBox = $true
    $form.MaximizeBox = $false
    $form.Location = [System.Drawing.Point]::new([System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width - 400, [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Height - 250)

    $logo = New-Object System.Windows.Forms.PictureBox
    $logo.SizeMode = "StretchImage"
    $logo.Size = [System.Drawing.Size]::new(80,80)
    $logo.Location = [System.Drawing.Point]::new(10,10)
    if (Test-Path $Global:Config.LogoPath) { try { $logo.Image = [System.Drawing.Image]::FromFile($Global:Config.LogoPath) } catch { } }
    $form.Controls.Add($logo)

    $labelInfo = New-Object System.Windows.Forms.Label
    $labelInfo.Font = New-Object System.Drawing.Font("Segoe UI",12)
    $labelInfo.Location = [System.Drawing.Point]::new(100,10)
    $labelInfo.AutoSize = $true
    $labelInfo.Text = "Usuario: $($Global:Config.Username)`nMAC: $($Global:SharedState.MacAddress)"
    $form.Controls.Add($labelInfo)

    $labelTimer = New-Object System.Windows.Forms.Label
    $labelTimer.Font = New-Object System.Drawing.Font("Segoe UI",14,[System.Drawing.FontStyle]::Bold)
    $labelTimer.Location = [System.Drawing.Point]::new(30,100)
    $labelTimer.AutoSize = $true
    $labelTimer.Text = "Inicializando..."
    $form.Controls.Add($labelTimer)

    $btnReducir = New-Object System.Windows.Forms.Button
    $btnReducir.Text = "Minimizar"
    $btnReducir.Size = [System.Drawing.Size]::new(80,28)
    $btnReducir.Location = [System.Drawing.Point]::new($form.Width - 100, $form.Height - 68)
    $btnReducir.Add_Click({
        try {
            if ($form.Height -gt 120) {
                $form.Size = [System.Drawing.Size]::new(280,100)
                $logo.Size = [System.Drawing.Size]::new(170,60)
                $btnReducir.Text = "Maximizar"
            } else {
                $form.Size = [System.Drawing.Size]::new(400,200)
                $logo.Size = [System.Drawing.Size]::new(80,80)
                $btnReducir.Text = "Minimizar"
            }
            $form.Refresh()
        } catch { }
    })
    $form.Controls.Add($btnReducir)

    # Detectar actividad en formulario -> actualizar LastActivity global
    $form.Add_MouseMove({ param($s,$e) $Global:SharedState.LastActivity = Get-Date })
    $form.Add_KeyDown({ param($s,$e) $Global:SharedState.LastActivity = Get-Date })
    $form.Add_MouseDown({ param($s,$e) $Global:SharedState.LastActivity = Get-Date })

    return @{
        Form = $form
        LabelInfo = $labelInfo
        LabelTimer = $labelTimer
        Logo = $logo
        BtnReducir = $btnReducir
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
        $tiempo = if ($Response.tiempo_restante) { $Response.tiempo_restante } else { 90 }
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
    param($Controles,$Response)
    Write-Log "Estado: SUSPENDIDO" -Tipo Warning
    try {
        $Controles.LabelTimer.ForeColor = [System.Drawing.Color]::Orange
        $Controles.LabelTimer.Text = "⏸️ SESIÓN SUSPENDIDA"
        $Controles.Form.Refresh()
        $resultado = [System.Windows.Forms.MessageBox]::Show(
            "Sesión suspendida. Ingrese OK para desbloquear con clave admin.",
            "Sesión Suspendida",
            [System.Windows.Forms.MessageBoxButtons]::OKCancel,
            [System.Windows.Forms.MessageBoxIcon]::Warning
        )
        if ($resultado -eq [System.Windows.Forms.DialogResult]::OK) {
            return Invoke-ApiCall -ExtraBody @{ clave_admin = $Global:Config.ClaveAdmin }
        } else {
            return Invoke-ApiCall -ExtraBody @{ cancel_suspend = "Cancelar" }
        }
    } catch { Write-Log "Error en estado suspendido: $_" -Tipo Error; return Invoke-ApiCall }
}

function Invoke-EstadoRestringido {
    param([Parameter(Mandatory=$true)]$Controles,[Parameter(Mandatory=$true)]$Response)
    Write-Log "Estado: RESTRINGIDO - Usuario con bloqueos en FOLIO" -Tipo Error
    $Controles.Form.Location = [System.Drawing.Point]::new([System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width - 1400, [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Height - 800)
    $Controles.Form.Size = [System.Drawing.Size]::new(900,500)
    $Controles.LabelInfo.ForeColor = [System.Drawing.Color]::Red
    $Controles.LabelInfo.Font = New-Object System.Drawing.Font("Segoe UI",16,[System.Drawing.FontStyle]::Bold)
    $Controles.LabelInfo.Text = "🚫 ACCESO RESTRINGIDO: Usuario bloqueado en FOLIO"
    $Controles.LabelInfo.Location = [System.Drawing.Point]::new(50,20)
    $txtBloqueos = New-Object System.Windows.Forms.TextBox
    $txtBloqueos.Multiline = $true; $txtBloqueos.ScrollBars = "Vertical"; $txtBloqueos.Font = New-Object System.Drawing.Font("Consolas",12)
    $txtBloqueos.ReadOnly = $true; $txtBloqueos.BackColor = [System.Drawing.Color]::White
    $txtBloqueos.Size = [System.Drawing.Size]::new(850,350); $txtBloqueos.Location = [System.Drawing.Point]::new(20,100)
    $Controles.Form.Controls.Add($txtBloqueos)
    if ($Response.bloqueos.manuales) {
        $detalles = foreach ($m in $Response.bloqueos.manuales) {
            "📌 BLOQUEO MANUAL`r`n─────────────────────────────────────────────────────────`r`nTipo: $($m.type)`r`nDescripción: $($m.desc)`r`nMensaje al usuario: $($m.patronMessage)`r`nFecha de expiración: $(Convert-ToColombiaDate $m.expirationDate)`r`n══════════════════════════════════════════════════════════`r`n`r`n"
        }
        $txtBloqueos.Text = ($detalles -join "`r`n")
    } elseif ($Response.bloqueos.automaticos) {
        $detalles = foreach ($a in $Response.bloqueos.automaticos) { "⚡ BLOQUEO AUTOMÁTICO`r`nRazón: $($a.message)`r`n`r`n" }
        $txtBloqueos.Text = ($detalles -join "`r`n")
    }
    $Controles.BtnReducir.Size = [System.Drawing.Size]::new(120,40)
    $Controles.BtnReducir.Location = [System.Drawing.Point]::new(760,420)
    $Controles.BtnReducir.Font = New-Object System.Drawing.Font("Segoe UI",10,[System.Drawing.FontStyle]::Bold)
    $segundos = 6
    for ($i = $segundos; $i -ge 1; $i--) {
        Process-PendingMessages
        $Controles.BtnReducir.Text = "Cerrar ($i)"
        $Controles.Form.Refresh()
        $waitUntil = (Get-Date).AddSeconds(1)
        while ((Get-Date) -lt $waitUntil) { [System.Windows.Forms.Application]::DoEvents(); Process-PendingMessages; Start-Sleep -Milliseconds 50 }
    }
    $Controles.Form.Close()
}

function Invoke-EstadoFinalizado {
    param([Parameter(Mandatory=$true)]$Controles,[Parameter(Mandatory=$true)]$Response)
    Write-Log "Estado: FINALIZADO - Sesión completada" -Tipo Success
    $Controles.LabelInfo.ForeColor = [System.Drawing.Color]::Blue
    $Controles.LabelTimer.Text = "✅ Sesión finalizada correctamente"; $Controles.LabelTimer.ForeColor = [System.Drawing.Color]::Green
    $Controles.Form.Refresh(); Start-Sleep -Seconds 2; $Controles.Form.Close()
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

# ============================================================
# 💤 UI - Estado Hibernando (ventana bloqueante con contador y detección global)
# ============================================================
function Invoke-EstadoHibernando {
    param([Parameter(Mandatory)][hashtable]$Controles,[Parameter(Mandatory)][hashtable]$Response)
    Add-Type -AssemblyName PresentationFramework

    $window = New-Object System.Windows.Window
    $window.Title = "Modo Hibernación"
    $window.WindowStartupLocation = "CenterScreen"
    $window.WindowState = "Maximized"
    $window.WindowStyle = "None"
    $window.ResizeMode = "NoResize"
    $window.Topmost = $true
    $window.Background = "#111111"
    $window.Foreground = "White"
    $window.Focusable = $true

    $grid = New-Object System.Windows.Controls.Grid

    $labelMsg = New-Object System.Windows.Controls.TextBlock
    $labelMsg.Text = "💤 El equipo entró en modo de hibernación"
    $labelMsg.FontSize = 36; $labelMsg.FontWeight = 'Bold'; $labelMsg.HorizontalAlignment = "Center"; $labelMsg.VerticalAlignment = "Center"; $labelMsg.Margin = "0,0,0,100"

    $labelCountdown = New-Object System.Windows.Controls.TextBlock
    $labelCountdown.FontSize = 26; $labelCountdown.Text = "Finalizando en 60 segundos..."; $labelCountdown.HorizontalAlignment = "Center"; $labelCountdown.VerticalAlignment = "Center"; $labelCountdown.Margin = "0,100,0,0"

    $grid.Children.Add($labelMsg); $grid.Children.Add($labelCountdown)
    $window.Content = $grid

    $segundosRestantes = 60
    $hibernando = $true
    $idlePrevio = Get-SystemIdleTime

    $timer = New-Object System.Windows.Threading.DispatcherTimer
    $timer.Interval = [TimeSpan]::FromSeconds(1)

    $timer.Add_Tick({
        try {
            $segundosRestantes--
            $labelCountdown.Text = "Finalizando en $segundosRestantes segundos..."
            $idleActual = Get-SystemIdleTime

            # Detectar actividad global (idleActual baja) o combo Ctrl+1
            $actividadDetectada = $idleActual -lt 3 -or (
                [System.Windows.Input.Keyboard]::IsKeyDown('LeftCtrl') -and [System.Windows.Input.Keyboard]::IsKeyDown('D1')
            )

            if ($actividadDetectada) {
                Write-Log "🟢 Actividad detectada — cancelando hibernación." -Tipo Info
                # Actualizar timestamp global de actividad para evitar re-trigger inmediato
                $Global:SharedState.LastActivity = Get-Date

                $hibernando = $false
                $timer.Stop()
                $window.Close()
                # Reanudar sesión normal (mostramos mensaje)
                $script:Response = Invoke-EstadoRenovado -Controles $Controles -Response $Response
                return
            }

            if ($segundosRestantes -le 0) {
                Write-Log "⏰ Tiempo agotado — finalizando sesión automáticamente." -Tipo Warning
                $timer.Stop()
                $hibernando = $false
                $window.Close()
                Invoke-FinalizarSesionRemota
                return
            }
            $idlePrevio = $idleActual
        } catch { Write-Log "❌ Error en temporizador de hibernación: $_" -Tipo Error }
    })

    $timer.Start()
    Write-Log "😴 Ventana de hibernación mostrada (contador iniciado)" -Tipo Info
    $window.ShowDialog() | Out-Null

    if (-not $hibernando) { Write-Log "✅ Hibernación cancelada o finalizada correctamente." -Tipo Success }
    return $Response
}

# ============================================================
# 🧩 Cierre remoto desde hibernación
# ============================================================
function Invoke-FinalizarSesionRemota {
    try {
        Write-Host "[🔚] Finalizando sesión en servidor..."
        $payload = @{
            tipo = "comando_api"
            origen = "server"
            accion = "finalizar"
            username = $Global:Usuario
            mac_address = $Global:Mac
        }
        Invoke-RestMethod -Uri $Global:Config.ApiUrl -Method Post -Body ($payload | ConvertTo-Json) -ContentType "application/json" | Out-Null
        Write-Host "[✅] Sesión finalizada correctamente (hibernación)."
    } catch { Write-Host "[❌] Error al finalizar sesión remota: $_" }
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

        # Variables inactividad
        $hibernando = $false
        $inicioHibernacion = $null

        while ($response -and $response.estado -notin @("Finalizado","Restringido","Error")) {
            Write-Log "Estado actual: $($response.estado)" -Tipo Info
            try {
                # CONTROL GLOBAL DE INACTIVIDAD (usa Get-SystemIdleTime)
                $idle = Get-SystemIdleTime

                if (-not $hibernando -and $idle -ge $Global:SharedState.INACTIVITY_TIMEOUT) {
                    Write-Log "😴 Inactividad detectada ($idle s) → Entrando en modo hibernación" -Tipo Warning
                    # marcar estado y abrir ventana hibernación
                    $Global:SharedState.IsHibernating = $true
                    $Global:SharedState.HibernationStartTime = Get-Date
                    $response = Invoke-EstadoHibernando -Controles $controles -Response $response
                    $hibernando = $true
                    $inicioHibernacion = Get-Date
                }
                elseif ($hibernando) {
                    # Si hay actividad global (idle bajo) cancelamos hibernación
                    if ($idle -lt 3) {
                        Write-Log "🟢 Actividad detectada → Cancelando hibernación" -Tipo Info
                        $Global:SharedState.IsHibernating = $false
                        $Global:SharedState.HibernationStartTime = $null
                        $hibernando = $false
                        $inicioHibernacion = $null
                        $Global:SharedState.LastActivity = Get-Date
                        $response = Invoke-EstadoRenovado -Controles $controles -Response $response
                    }
                    # Si supera tiempo máximo en hibernación -> finalizar
                    elseif ((New-TimeSpan -Start $inicioHibernacion -End (Get-Date)).TotalSeconds -ge $Global:SharedState.HIBERNATION_MAX_DURATION) {
                        Write-Log "⛔ Inactividad prolongada (hibernación: >= $($Global:SharedState.HIBERNATION_MAX_DURATION)s) → Finalizando sesión" -Tipo Error
                        Invoke-FinalizarSesionRemota
                        break
                    }
                }

                # Procesamiento normal de estados (UI)
                switch ($response.estado) {
                    "Abierto"   { $response = Invoke-EstadoAbierto -Controles $controles -Response $response }
                    "Bloqueado" { $response = Invoke-EstadoBloqueado -Controles $controles -Response $response }
                    "Suspendido"{ $response = Invoke-EstadoSuspendido -Controles $controles -Response $response }
                    "Renovado"  { $response = Invoke-EstadoRenovado -Controles $controles -Response $response }
                    "Hibernando"{ $response = Invoke-EstadoHibernando -Controles $controles -Response $response }
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
    try { Stop-WebSocketProcess } catch { Write-Log "⚠️ Error al detener WebSocket: $_" -Tipo Warning }

    try {
        if ($Global:WebSocketPowerShell) { $Global:WebSocketPowerShell.Dispose(); Write-Log "🧠 WebSocket PowerShell liberado" -Tipo Info }
    } catch { Write-Log "⚠️ Error al liberar PowerShell: $_" -Tipo Warning }

    try {
        if ($Global:WebSocketRunspace) { $Global:WebSocketRunspace.Dispose(); Write-Log "🧠 Runspace liberado" -Tipo Info }
    } catch { Write-Log "⚠️ Error al liberar runspace: $_" -Tipo Warning }

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

    # ============================================================
    # 📝 PRE-EVALUACIÓN DEL SERVICIO (KIOSK) - SIN VALIDACIÓN
    # ============================================================

    Write-Log "Iniciando evaluación previa al inicio de sesión..." -Tipo Info

    $KioskBat = "C:\xampp\htdocs\autoprestamos\EVALUACION\run_kiosk.bat"

    if (!(Test-Path $KioskBat)) {
        Write-Log "❌ No se encontró run_kiosk.bat en: $KioskBat" -Tipo Error
        Start-SessionLoop
        return
    }

    Write-Log "Ejecutando Evaluación Kiosk..." -Tipo Info

    try {
        $proc = Start-Process -FilePath $KioskBat -WindowStyle Hidden -PassThru
        $proc.WaitForExit()

        Write-Log "✔ Chrome cerrado. Continuando con el flujo de sesión..." -Tipo Success

        Start-SessionLoop
    }
    catch {
        Write-Log "⚠️ Error ejecutando evaluación: $_" -Tipo Warning
        Start-SessionLoop
    }

    Write-Log "✅ Ejecución completada exitosamente" -Tipo Success
}
catch {
    Write-Log "❌ Error crítico: $($_.Exception.Message)" -Tipo Error
    Write-Log "Stack Trace: $($_.ScriptStackTrace)" -Tipo Error
}

# ============================================================
# FIN - v2.3
# ============================================================
