# ============================================================
# 🧪 TEST: Flujo solicitar_estado → respuesta_estado
# ============================================================
# Este script simula lo que hace el cliente PowerShell:
# 1. Conecta al servidor WebSocket
# 2. Se registra como equipo
# 3. Solicita estado
# 4. Espera respuesta_estado en una cola

param(
    [string]$ServerUrl = "ws://localhost:8081",
    [string]$IdEquipo = "TEST-PC-01",
    [string]$Username = "testuser",
    [string]$MacAddress = "AA:BB:CC:DD:EE:FF"
)

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "🧪 TEST: solicitar_estado → respuesta_estado" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Crear ClientWebSocket
$ws = [System.Net.WebSockets.ClientWebSocket]::new()

try {
    # Conectar
    Write-Host "🔌 Conectando a $ServerUrl..." -ForegroundColor Yellow
    $task = $ws.ConnectAsync([uri]$ServerUrl, [System.Threading.CancellationToken]::None)
    $task.Wait(5000) | Out-Null
    Write-Host "✅ Conectado" -ForegroundColor Green
    
    # Registrarse
    Write-Host "📝 Registrando como equipo..." -ForegroundColor Yellow
    $registro = @{
        tipo = "registro"
        origen = "equipo"
        nombre_equipo = $IdEquipo
        username = $Username
        mac_address = $MacAddress
        timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    } | ConvertTo-Json -Compress
    
    $buffer = [System.Text.Encoding]::UTF8.GetBytes($registro)
    $segment = New-Object System.ArraySegment[byte] (,$buffer)
    $ws.SendAsync($segment, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [System.Threading.CancellationToken]::None).Wait(3000) | Out-Null
    Write-Host "✅ Registro enviado" -ForegroundColor Green
    
    # Cola de respuestas
    $cmdQueue = New-Object System.Collections.Queue
    
    # Iniciar lectura en background
    Write-Host "📡 Iniciando receptor de mensajes..." -ForegroundColor Yellow
    $receiver = {
        param($ws, $queue)
        $buffer = New-Object byte[] 4096
        
        while ($ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
            try {
                $result = $ws.ReceiveAsync([System.ArraySegment[byte]]$buffer, [System.Threading.CancellationToken]::None).Result
                if ($result.Count -gt 0) {
                    $msg = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $result.Count)
                    Write-Host "📩 Recibido: $msg" -ForegroundColor Magenta
                    $data = $msg | ConvertFrom-Json
                    $queue.Enqueue($data)
                }
            } catch {
                Write-Host "❌ Error en receptor: $_" -ForegroundColor Red
                break
            }
        }
    }
    
    $job = Start-Job -ScriptBlock $receiver -ArgumentList $ws, $cmdQueue
    Start-Sleep -Milliseconds 500
    
    # Solicitar estado
    Write-Host "📬 Solicitando estado..." -ForegroundColor Yellow
    $solicitud = @{
        tipo = "solicitar_estado"
        origen = "equipo"
        destino = "server"
        nombre_equipo = $IdEquipo
        username = $Username
        mac_address = $MacAddress
        timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    } | ConvertTo-Json -Compress
    
    Write-Host "  Payload: $solicitud" -ForegroundColor Gray
    $buffer = [System.Text.Encoding]::UTF8.GetBytes($solicitud)
    $segment = New-Object System.ArraySegment[byte] (,$buffer)
    $ws.SendAsync($segment, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [System.Threading.CancellationToken]::None).Wait(3000) | Out-Null
    Write-Host "✅ Solicitud enviada" -ForegroundColor Green
    
    # Esperar respuesta
    Write-Host ""
    Write-Host "⏳ Esperando respuesta_estado (timeout: 30s)..." -ForegroundColor Yellow
    $timeout = 30
    $elapsed = 0
    $found = $false
    
    while ($elapsed -lt $timeout -and -not $found) {
        if ($cmdQueue.Count -gt 0) {
            $respuesta = $cmdQueue.Dequeue()
            if ($respuesta.tipo -eq "respuesta_estado" -and $respuesta.destino -eq "shell") {
                Write-Host ""
                Write-Host "✅ RESPUESTA RECIBIDA!" -ForegroundColor Green
                Write-Host "  tipo: $($respuesta.tipo)" -ForegroundColor Green
                Write-Host "  estado: $($respuesta.estado)" -ForegroundColor Green
                Write-Host "  mensaje: $($respuesta.mensaje)" -ForegroundColor Green
                Write-Host "  origen: $($respuesta.origen)" -ForegroundColor Green
                Write-Host "  destino: $($respuesta.destino)" -ForegroundColor Green
                Write-Host "  nombre_equipo: $($respuesta.nombre_equipo)" -ForegroundColor Green
                Write-Host "  username: $($respuesta.username)" -ForegroundColor Green
                $found = $true
            } else {
                # Volver a encolar si no es respuesta_estado
                $cmdQueue.Enqueue($respuesta)
                Start-Sleep -Milliseconds 100
            }
        } else {
            Start-Sleep -Milliseconds 100
            $elapsed += 0.1
        }
    }
    
    if (-not $found) {
        Write-Host "❌ TIMEOUT: No se recibió respuesta_estado en $timeout segundos" -ForegroundColor Red
        Write-Host "   Cola actual: $($cmdQueue.Count) mensajes" -ForegroundColor Yellow
    }
    
    Write-Host ""
    Stop-Job -Job $job -Force | Out-Null
    
} catch {
    Write-Host "❌ Error: $_" -ForegroundColor Red
} finally {
    Write-Host ""
    Write-Host "🔌 Cerrando WebSocket..." -ForegroundColor Yellow
    if ($ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
        $ws.CloseAsync([System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure, "Test completado", [System.Threading.CancellationToken]::None).Wait(3000) | Out-Null
    }
    $ws.Dispose()
    Write-Host "✅ Cerrado" -ForegroundColor Green
}

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "✅ Test finalizado" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
