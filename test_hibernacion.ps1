# 🧪 PRUEBA: Sistema de Hibernación Automática
# Este script prueba la lógica de detección de inactividad sin necesidad de esperar 5-10 minutos
# Uso: .\test_hibernacion.ps1

param(
    [int]$TestDuration = 20,  # segundos totales de prueba
    [int]$TimeMultiplier = 1   # multiplicador de tiempo (1 = 1s real = 1s prueba; 10 = 1s real = 10s prueba)
)

Write-Host "🧪 PRUEBA: Sistema de Hibernación Automática" -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan
Write-Host "Duración: $TestDuration segundos"
Write-Host "Multiplicador: ${TimeMultiplier}x (útil para acelerar pruebas)"
Write-Host ""

# Simular SharedState
$Global:TestState = @{
    LastActivity = (Get-Date)
    IsHibernating = $false
    HibernationStartTime = $null
    INACTIVITY_TIMEOUT = 5              # segundos reales para prueba
    HIBERNATION_MAX_DURATION = 10        # segundos adicionales para prueba
}

# Función de prueba simplificada del monitor
function Test-InactivityLogic {
    param($Controles)
    
    $timer = New-Object System.Timers.Timer
    $timer.Interval = 1000  # Cada segundo
    $timer.AutoReset = $true
    
    $testResults = @()
    
    $timer.Add_Elapsed({
        $ahora = Get-Date
        $tiempoSinActividad = ($ahora - $Global:TestState.LastActivity).TotalSeconds
        
        Write-Host "⏱️  Tiempo sin actividad: $($tiempoSinActividad.ToString('F1'))s - Estado hibernación: $($Global:TestState.IsHibernating)" -ForegroundColor Yellow
        
        # 🟡 ENTRAR EN HIBERNACIÓN (5 seg en prueba)
        if ($tiempoSinActividad -ge $Global:TestState.INACTIVITY_TIMEOUT -and -not $Global:TestState.IsHibernating) {
            Write-Host "😴 [EVENTO] Entrando en hibernación (inactividad: ${tiempoSinActividad}s)" -ForegroundColor Magenta
            Write-Host "📤 [SIMULADO] Enviando JSON al servidor: {""tipo"":""hibernado"",""accion"":""hibernar""}" -ForegroundColor Green
            $Global:TestState.IsHibernating = $true
            $Global:TestState.HibernationStartTime = $ahora
        }
        
        # 🔴 FINALIZAR SESIÓN (10 seg adicionales en prueba)
        if ($Global:TestState.IsHibernating -and $Global:TestState.HibernationStartTime) {
            $tiempoEnHibernacion = ($ahora - $Global:TestState.HibernationStartTime).TotalSeconds
            
            if ($tiempoEnHibernacion -ge $Global:TestState.HIBERNATION_MAX_DURATION) {
                Write-Host "⛔ [EVENTO] Finalizando sesión por inactividad prolongada (hibernación: ${tiempoEnHibernacion}s)" -ForegroundColor Red
                Write-Host "📤 [SIMULADO] Enviando JSON al servidor: {""tipo"":""hibernado"",""accion"":""finalizar_por_hibernacion""}" -ForegroundColor Green
                Write-Host "🔴 [SIMULADO] Cerrando aplicación" -ForegroundColor Red
                
                $testResults += @{
                    Evento = "Finalización"
                    TiempoHibernacion = $tiempoEnHibernacion
                    Exitoso = $true
                }
                
                $timer.Stop()
                return
            }
        }
    })
    
    Write-Host "🟢 Monitor iniciado. Presiona una tecla en esta ventana para simular actividad." -ForegroundColor Green
    Write-Host ""
    
    # Iniciar timer
    $timer.Start()
    
    # Monitorear teclado
    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $testStartTime = Get-Date
    
    while ($stopwatch.Elapsed.TotalSeconds -lt $TestDuration) {
        if ([Console]::KeyAvailable) {
            $key = [Console]::ReadKey($true)
            Write-Host "🎹 [ACTIVIDAD] Tecla presionada: $($key.KeyChar) - Reiniciando timer de inactividad" -ForegroundColor Cyan
            $Global:TestState.LastActivity = Get-Date
            
            # Si estaba hibernando, "despertar"
            if ($Global:TestState.IsHibernating) {
                Write-Host "⚡ [EVENTO] Reactivado por actividad de usuario - Saliendo de hibernación" -ForegroundColor Cyan
                $Global:TestState.IsHibernating = $false
                $Global:TestState.HibernationStartTime = $null
            }
        }
        
        Start-Sleep -Milliseconds 100
    }
    
    $timer.Stop()
    $stopwatch.Stop()
    
    Write-Host ""
    Write-Host "================================================" -ForegroundColor Cyan
    Write-Host "✅ Prueba completada" -ForegroundColor Green
    Write-Host "Estado final de hibernación: $($Global:TestState.IsHibernating)" -ForegroundColor Yellow
}

# Ejecutar prueba
Write-Host "⏳ Iniciando prueba en 2 segundos..." -ForegroundColor Yellow
Start-Sleep -Seconds 2

Test-InactivityLogic

Write-Host ""
Write-Host "📝 NOTAS PARA LA PRUEBA:"
Write-Host "- Si NO presionas nada durante $($Global:TestState.INACTIVITY_TIMEOUT) segundos → entra en hibernación"
Write-Host "- Si presionas una tecla → reinicia el contador de inactividad"
Write-Host "- Si permanece en hibernación $($Global:TestState.HIBERNATION_MAX_DURATION) segundos → finaliza"
Write-Host ""
Write-Host "🔗 En producción:"
Write-Host "- INACTIVITY_TIMEOUT = 300 segundos (5 minutos)"
Write-Host "- HIBERNATION_MAX_DURATION = 600 segundos (10 minutos adicionales)"
