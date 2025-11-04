# Cargar librerías necesarias
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

# ============================
# Crear formulario si no existe
# ============================
if (-not $form) {
    $form = New-Object System.Windows.Forms.Form
    $form.Text = "Gestión de Sesión"
    $form.Size = [System.Drawing.Size]::new(400,200)
    $form.StartPosition = "CenterScreen"
}

# ============================
# Logo (descargado de servidor)
# ============================
$logo = New-Object System.Windows.Forms.PictureBox
$logo.SizeMode = "StretchImage"
$logo.Size     = [System.Drawing.Size]::new(80,80)
$logo.Location = [System.Drawing.Point]::new(10,10)

try {
    $localPath = Join-Path $env:TEMP "logo.png"

    # Descargar imagen
    $wc = New-Object System.Net.WebClient
    $wc.DownloadFile("http://192.168.56.100/prueba_equipos/logo.png", $localPath)

    # Verificar que se descargó correctamente
    if (-not (Test-Path $localPath) -or ((Get-Item $localPath).Length -eq 0)) {
        Write-Host "❌ El archivo no se descargó correctamente o está vacío."
        # no return aquí: dejamos que el script siga (si prefieres cortar, descomenta la línea siguiente)
        # return
    } else {
        # Cargar imagen desde archivo local
        $logo.Image = [System.Drawing.Image]::FromFile($localPath)
        Write-Host "✅ Logo cargado correctamente desde $localPath"
    }
}
catch {
    Write-Host "⚠️ Error al cargar el logo: $($_.Exception.Message)"
}

# Agregar al formulario si está definido
if ($form -and $logo.Image) {
    $form.Controls.Add($logo)
}

function Show-VentanaSesion {
    param (
        [string]$Titulo = "Gestion de Seccion",
        [string]$ClaveAdmin = "clave123",
        [string]$LogoPath   = "http://192.168.56.100/prueba_equipos/logo.png",
        [string]$ApiUrl     = "http://192.168.56.100/prueba_equipos/api.php"
    )

    function Format-Time([int]$segundos) {
        $ts = [TimeSpan]::FromSeconds($segundos)
        return "{0:00}:{1:00}:{2:00}" -f $ts.Hours, $ts.Minutes, $ts.Seconds
    }

    $logFile = "$env:ProgramData\SesionEquipo\sesion.log"
function Log($msg) {
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -Path $logFile -Value "[$timestamp] $msg"
}

    # ===== Obtener usuario/IP/MAC =====
    $Username = $env:USERNAME
    $ip = (Get-NetIPAddress -AddressFamily IPv4 |
        Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" -and $_.PrefixOrigin -ne "WellKnown" } |
        Sort-Object InterfaceIndex | Select-Object -First 1 -ExpandProperty IPAddress) -as [string]
    if ($ip) { $ip = $ip.Trim() }

    $mac = (Get-NetAdapter | Where-Object { $_.Status -eq "Up" } |
        Sort-Object InterfaceIndex | Select-Object -First 1 -ExpandProperty MacAddress) -as [string]
    if ($mac) { $mac = $mac.Replace("-", ":") }

    if (-not $ip -or -not $mac) {
        Log "❌ No se pudo obtener IP o MAC. Abortando."
        return
    }

    # ===== Función API (se usa también para validación inicial) =====
    function Call-Api($extraBody = @{}) {
        $body = @{ username = $Username; ip_address = $ip } + $extraBody
        $json = $body | ConvertTo-Json -Compress
        $headers = @{ "Content-Type" = "application/json" }

        try {
            $resp = Invoke-RestMethod -Uri $ApiUrl -Method Post -Headers $headers -Body $json -TimeoutSec 60
            Log ("✅ Respuesta API: " + ($resp | ConvertTo-Json -Compress))
            return $resp
        }
        catch [System.Net.WebException] {
            $errorMsg = "❌ Error de conexión con la API: $($_.Exception.Message)"
            Log $errorMsg
            return @{ estado="Error"; mensaje=$errorMsg }
        }
        catch {
            $errorMsg = "🚨 Error inesperado: $($_.Exception.Message)"
            Log $errorMsg
            return @{ estado="Error"; mensaje=$errorMsg }
        }
    }

    # ===== Ventana principal =====
    $form = New-Object System.Windows.Forms.Form
    $form.Text = $Titulo
    $form.Size = [System.Drawing.Size]::new(400,200)
    $form.StartPosition = "Manual"
    $form.FormBorderStyle = [System.Windows.Forms.FormBorderStyle]::FixedDialog
    $form.ShowInTaskbar = $true
    $form.MinimizeBox = $false
    $form.MaximizeBox = $false
    $form.ControlBox = $true
    $form.Location = [System.Drawing.Point]::new(
        [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Width - 400,
        [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea.Height - 250
    )

    $form.Add_FormClosing({
        if ($_.CloseReason -eq [System.Windows.Forms.CloseReason]::UserClosing) {
            # impedir cierre por usuario
            $_.Cancel = $true
        }
    })

    $btnReducir = New-Object System.Windows.Forms.Button
    $btnReducir.Text = "Reducir"
    $btnReducir.Size = [System.Drawing.Size]::new(80,28)
    $btnReducir.Location = [System.Drawing.Point]::new($form.Width - 100, $form.Height - 68)
    $btnReducir.Anchor = [System.Windows.Forms.AnchorStyles]::Bottom -bor [System.Windows.Forms.AnchorStyles]::Right
    $btnReducir.Add_Click({
        if ($form.Height -gt 120) {
            $form.Refresh()
            $logo.Size = [System.Drawing.Size]::new(170,60)
            $logo.Location = [System.Drawing.Point]::new(0,0)
            $form.Size = [System.Drawing.Size]::new(280,100)
            $labelInfo.Font = New-Object System.Drawing.Font("Segoe UI",13,[System.Drawing.FontStyle]::Bold)
            $labelInfo.Location = [System.Drawing.Point]::new(170,5)
            $labelInfo.Text = "$($response.estado)"
            $labelInfo.ForeColor = [System.Drawing.Color]::DarkGreen
            $btnReducir.Text = "Maximizar"
        } else {
            $form.Refresh()
            $logo.Size = [System.Drawing.Size]::new(110,80)
            $logo.Location = [System.Drawing.Point]::new(10,10)
            $labelInfo.Location = [System.Drawing.Point]::new(150,10)
            $labelInfo.Font = New-Object System.Drawing.Font("Segoe UI",12,[System.Drawing.FontStyle]::Bold)
            $labelInfo.Text = "Usuario: $Username`nIP: $ip`n MAC: $mac"
            $form.Size = [System.Drawing.Size]::new(400,200)
            $btnReducir.Text = "Minimizar"
        }
    })
    $form.Controls.Add($btnReducir)

    $logo = New-Object System.Windows.Forms.PictureBox
    $logo.SizeMode = "StretchImage"
    $logo.Size = [System.Drawing.Size]::new(80,80)
    $logo.Location = [System.Drawing.Point]::new(10,10)
    # si $LogoPath es una URL, el FromFile fallará: preferible usar la descarga previa; si falla, se intenta usar $LogoPath directo
    try {
        $logo.Image = [System.Drawing.Image]::FromFile($LogoPath)
    } catch {
        # ignore, ya cargamos antes desde $localPath si estaba disponible
        if ($logo.Image -eq $null -and (Test-Path $localPath)) {
            $logo.Image = [System.Drawing.Image]::FromFile($localPath)
        }
    }
    $form.Controls.Add($logo)

    $labelInfo = New-Object System.Windows.Forms.Label
    $labelInfo.Font = New-Object System.Drawing.Font("Segoe UI",12)
    $labelInfo.Location = [System.Drawing.Point]::new(100,10)
    $labelInfo.AutoSize = $true
    $form.Controls.Add($labelInfo)

    $labelInfo2 = New-Object System.Windows.Forms.Label
    $labelInfo2.Font = New-Object System.Drawing.Font("Segoe UI",12)
    $labelInfo2.Location = [System.Drawing.Point]::new(100,10)
    $labelInfo2.AutoSize = $true
    $form.Controls.Add($labelInfo2)

    $labelTimer = New-Object System.Windows.Forms.Label
    $labelTimer.Font = New-Object System.Drawing.Font("Segoe UI",14,[System.Drawing.FontStyle]::Bold)
    $labelTimer.Location = [System.Drawing.Point]::new(30,100)
    $labelTimer.AutoSize = $true
    $form.Controls.Add($labelTimer)

    $form.Show()

    # ===== Inicio Sesión =====
    $response = Call-Api @{ confirmar_inicio = $true  }
    if ($response){
        Start-Process -FilePath "explorer.exe"
    }else { 
        shutdown /l 
    return
}

    # ===== Bucle principal =====
    while ($response -and $response.estado -ne "Finalizado") {
        $global:SuspendidoProcesado = $false
        switch ($response.estado) {
            "Abierto" {
                $labelInfo.Text = "Usuario: $Username`nIP: $ip`n MAC: $mac"
                $labelTimer.ForeColor = [System.Drawing.Color]::DarkGreen
                $tiempo = if ($response.tiempo_restante) { $response.tiempo_restante } else { 30 }
                for ($i=$tiempo; $i -ge 0; $i--) {
                    $labelTimer.Text = "🟢 SESIÓN ACTIVA - Restante: $(Format-Time $i)"
                    $form.Refresh()
                    $waitUntil = (Get-Date).AddSeconds(1)
                    while ((Get-Date) -lt $waitUntil) {
                        [System.Windows.Forms.Application]::DoEvents()
                        Start-Sleep -Milliseconds 50
                    }
                }
                $response = Call-Api
            }

            "Suspendido" {
                if (-not $global:SuspendidoProcesado) {
                    $global:SuspendidoProcesado = $true

                    # Crear modal de desbloqueo (ya lo habías; aquí lo usamos sin romper flujo)
                    $modal = New-Object System.Windows.Forms.Form
                    $modal.FormBorderStyle = 'None'
                    $modal.StartPosition   = 'CenterScreen'
                    $modal.Size            = [System.Drawing.Size]::new(400,250)
                    $modal.BackColor       = [System.Drawing.Color]::FromArgb(240,240,240)
                    $modal.TopMost         = $true

                    $labelModal = New-Object System.Windows.Forms.Label
                    $labelModal.Font      = New-Object System.Drawing.Font("Segoe UI",12,[System.Drawing.FontStyle]::Bold)
                    $labelModal.Text      = "🔒 Sesión suspendida. Ingrese clave:"
                    $labelModal.AutoSize  = $true
                    $labelModal.Location  = [System.Drawing.Point]::new(50,30)
                    $modal.Controls.Add($labelModal)

                    $script:progress = New-Object System.Windows.Forms.ProgressBar
                    $script:progress.Location = [System.Drawing.Point]::new(50,70)
                    $script:progress.Size     = [System.Drawing.Size]::new(300,20)
                    $script:progress.Maximum  = 30
                    $modal.Controls.Add($script:progress)

                    $textBox = New-Object System.Windows.Forms.TextBox
                    $textBox.UseSystemPasswordChar = $true
                    $textBox.Width    = 200
                    $textBox.Location = [System.Drawing.Point]::new(100,110)
                    $modal.Controls.Add($textBox)

                    $btnValidar = New-Object System.Windows.Forms.Button
                    $btnValidar.Text     = "Validar"
                    $btnValidar.Location = [System.Drawing.Point]::new(80,160)
                    $modal.Controls.Add($btnValidar)

                    $btnCancelar = New-Object System.Windows.Forms.Button
                    $btnCancelar.Text     = "Cancelar"
                    $btnCancelar.Location = [System.Drawing.Point]::new(200,160)
                    $modal.Controls.Add($btnCancelar)

                    # Flags de control
                    $script:desbloqueado = $false
                    $script:cancelado    = $false
                    $script:segundos     = 30

                    # Eventos botones
                    $btnValidar.Add_Click({
                        if ($textBox.Text -eq $ClaveAdmin) {
                            $script:desbloqueado = $true
                            Log "✅ Clave correcta ingresada"
                            $modal.Close()
                        } else {
                            Log "❌ Clave incorrecta"
                            $textBox.Clear(); $textBox.Focus()
                        }
                    })
                    $btnCancelar.Add_Click({
                        $script:cancelado = $true
                        Log "🚫 Usuario canceló -> Bloqueado"
                        $modal.Close()
                    })

                    # Timer visual
                    $script:timer = New-Object System.Windows.Forms.Timer
                    $script:timer.Interval = 1000
                    $script:timer.Add_Tick({
                        if ($script:segundos -ge 0) {
                            $script:progress.Value = $script:progress.Maximum - $script:segundos
                            $script:segundos--
                        } else {
                            $script:timer.Stop()
                            $modal.Close()
                        }
                    })
                    $script:timer.Start()

                    # Mostrar ventana sin bloquear flujo
                    $modal.Show()

                    # Nuevo bucle no bloqueante
                    while ($response.estado -eq "Suspendido" -and $modal.Visible) {
                        [System.Windows.Forms.Application]::DoEvents()
                        Start-Sleep -Milliseconds 100

                        if ($script:desbloqueado) {
                            $response = Call-Api @{ clave_admin = $ClaveAdmin }
                            if (-not $response -or -not $response.estado) {
                                $response = @{ estado = "Renovado"; mensaje = "Renovación forzada" }
                            } elseif ($response.estado -ne "Renovado" -and $response.estado -ne "Abierto") {
                                $response.estado = "Renovado"
                            }
                            break
                        }

                        if ($script:cancelado) {
                            $response = Call-Api @{ cancel_suspend = "Cancelar" }
                            break
                        }

                        if ($script:segundos -le 0) {
                            $response = Call-Api @{ cancel_suspend = "Expirado" }
                            break
                        }
                    }
                }
            }

            "Bloqueado" {
                $labelTimer.ForeColor = [System.Drawing.Color]::Red
                # Verificar si el check-in fue exitoso
                if ($response.folioCheckin -and $response.folioCheckin.raw -and $response.folioCheckin.raw.loan.status.name -eq "Closed") {
                    Log "✅ Check-in exitoso detectado en estado Bloqueado"
                    # Hacer una llamada adicional a la API para obtener el estado Finalizado
                    $response = Call-Api
                    $waitUntil = (Get-Date).AddSeconds(1)
                    while ((Get-Date) -lt $waitUntil) {
                        [System.Windows.Forms.Application]::DoEvents()
                        Start-Sleep -Milliseconds 50
                    }
                    continue
                }

                $tiempo = if ($response.tiempo_restante) { $response.tiempo_restante } else { 10 }
                for ($i=10; $i -ge 0; $i--) {
                    $labelTimer.Text = "🔴 BLOQUEADO - Restante: $(Format-Time $i)"
                    $form.Refresh()
                    $waitUntil = (Get-Date).AddSeconds(1)
                    while ((Get-Date) -lt $waitUntil) {
                        [System.Windows.Forms.Application]::DoEvents()
                        Start-Sleep -Milliseconds 50
                    }
                }
                $response = Call-Api
            }

            "Error" {
                # Mostrar mensaje de error
                [System.Windows.Forms.MessageBox]::Show(
                    $response.mensaje,
                    "❌ Error de sesión",
                    [System.Windows.Forms.MessageBoxButtons]::OK,
                    [System.Windows.Forms.MessageBoxIcon]::Error
                )
                # Cerrar formulario inmediatamente
                $form.Close()
                return
            }

            "Renovado" {
                $labelTimer.ForeColor = [System.Drawing.Color]::Blue
                $labelTimer.Text = "🔄 Renovación en curso..."
                $form.Refresh()

                # Repetir consultas hasta que la API devuelva "Abierto" (o un estado distinto a 'Renovado'/'Renovando')
                do {
                    $response = Call-Api
                    # esperar 1s amigable con UI
                    $waitUntil = (Get-Date).AddSeconds(1)
                    while ((Get-Date) -lt $waitUntil) {
                        [System.Windows.Forms.Application]::DoEvents()
                        Start-Sleep -Milliseconds 50
                    }
                } while ($response.estado -eq "Renovado" -or $response.estado -eq "Renovando")
                $response = Call-Api 
                Log "✅ $response"
            }

            Default {
                # Si FOLIO devolvió un préstamo cerrado directamente
                if ($response.folioResp -and $response.folioResp.raw.loan.status.name -eq "Closed") {
                    Log "✅ Sesión finalizada por FOLIO (Closed)"
                    $form.Close()
                    return
                }
            }
        } # end switch
    } # end while

    if ($response.estado -eq "Finalizado") {
        $labelInfo.ForeColor = [System.Drawing.Color]::Blue
        $labelInfo.Text = "$($response.estado)"
        $labelTimer.Text = "✅ Sesión finalizada correctamente"
        $form.Refresh()
        $waitUntil = (Get-Date).AddSeconds(1)
        while ((Get-Date) -lt $waitUntil) {
            [System.Windows.Forms.Application]::DoEvents()
            Start-Sleep -Milliseconds 50
        }
        $form.Close()
        Log "Script finalizado completamente"
        return
        shutdown /l
    }
}

# Ejecutar
Show-VentanaSesion -Titulo "Gestión de Sesión con API"
