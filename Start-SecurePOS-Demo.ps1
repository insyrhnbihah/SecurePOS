[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = $PSScriptRoot
$cloudflaredPath = [System.IO.Path]::GetFullPath((Join-Path $projectRoot 'tools\cloudflared.exe'))
$configPath = Join-Path $projectRoot 'config\local.php'
$qrEndpointPath = Join-Path $projectRoot 'attendance_kiosk_qr.php'
$statePath = Join-Path $projectRoot 'tools\securepos-quick-tunnel.state.json'
$originUrl = 'http://127.0.0.1:80'
$tunnelHostPattern = 'https://[a-z0-9-]+\.trycloudflare\.com'

function Write-Status([string]$Message, [ConsoleColor]$Color = [ConsoleColor]::Gray) {
    Write-Host $Message -ForegroundColor $Color
}

function Get-WebStatus([string]$Uri, [int]$TimeoutSeconds = 15) {
    try {
        $request = [System.Net.HttpWebRequest]::Create($Uri)
        $request.Method = 'GET'
        $request.AllowAutoRedirect = $true
        $request.MaximumAutomaticRedirections = 8
        $request.Timeout = $TimeoutSeconds * 1000
        $request.ReadWriteTimeout = $TimeoutSeconds * 1000
        $request.UserAgent = 'SecurePOS-Demo-Launcher/1.0'
        $response = $request.GetResponse()
        try {
            return [pscustomobject]@{
                StatusCode = [int]$response.StatusCode
                FinalUri = $response.ResponseUri.AbsoluteUri
                ErrorType = $null
                ErrorMessage = $null
            }
        } finally {
            $response.Close()
        }
    } catch [System.Net.WebException] {
        if ($null -ne $_.Exception.Response) {
            $response = $_.Exception.Response
            try {
                return [pscustomobject]@{
                    StatusCode = [int]$response.StatusCode
                    FinalUri = $response.ResponseUri.AbsoluteUri
                    ErrorType = 'HttpError'
                    ErrorMessage = $_.Exception.Message
                }
            } finally {
                $response.Close()
            }
        }
        return [pscustomobject]@{
            StatusCode = $null
            FinalUri = $Uri
            ErrorType = $_.Exception.Status.ToString()
            ErrorMessage = $_.Exception.Message
        }
    } catch {
        return [pscustomobject]@{
            StatusCode = $null
            FinalUri = $Uri
            ErrorType = $_.Exception.GetType().Name
            ErrorMessage = $_.Exception.Message
        }
    }
}

function Test-WebResultSuccess($Result) {
    return $null -ne $Result -and $null -ne $Result.StatusCode -and
        $Result.StatusCode -ge 200 -and $Result.StatusCode -lt 400
}

function Format-WebResult($Result) {
    if ($null -ne $Result.StatusCode) { return "HTTP $($Result.StatusCode)" }
    if ($Result.ErrorType -eq 'NameResolutionFailure') { return "DNS unresolved: $($Result.ErrorMessage)" }
    if ($Result.ErrorType -eq 'Timeout') { return "Timeout: $($Result.ErrorMessage)" }
    if ($Result.ErrorType -in @('TrustFailure', 'SecureChannelFailure')) { return "TLS error: $($Result.ErrorMessage)" }
    return "$($Result.ErrorType): $($Result.ErrorMessage)"
}

function Wait-TunnelRegistration([string]$LogPath, $Process, [int]$TimeoutSeconds = 30) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        if ($null -eq (Get-Process -Id ([int]$Process.ProcessId) -ErrorAction SilentlyContinue)) {
            throw "cloudflared exited before registering the tunnel connection. See: $LogPath"
        }
        if (Test-Path -LiteralPath $LogPath -PathType Leaf) {
            $logText = Get-Content -LiteralPath $LogPath -Raw
            if (-not [string]::IsNullOrEmpty($logText) -and $logText -match 'Registered tunnel connection') {
                return
            }
        }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    throw "cloudflared did not register the tunnel connection within $TimeoutSeconds seconds. See: $LogPath"
}

function Get-SecurePOSProcess([int]$ProcessId) {
    if ($ProcessId -le 0) { return $null }
    try {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $ProcessId" -ErrorAction Stop
    } catch {
        return $null
    }
    if ($null -eq $process) { return $null }

    $actualPath = if ($process.ExecutablePath) { [System.IO.Path]::GetFullPath($process.ExecutablePath) } else { '' }
    $pathMatches = [string]::Equals($actualPath, $cloudflaredPath, [System.StringComparison]::OrdinalIgnoreCase)
    $commandMatches = $process.CommandLine -match '(?i)\btunnel\b' -and
        $process.CommandLine -match '(?i)--url(?:\s+|=)["'']?http://127\.0\.0\.1(?::80)?(?:["'']?\s|["'']?$)'
    if (-not ($pathMatches -and $commandMatches)) { return $null }
    return $process
}

function Get-AllSecurePOSProcesses {
    try {
        $all = Get-CimInstance Win32_Process -Filter "Name = 'cloudflared.exe'" -ErrorAction Stop
    } catch {
        throw 'Windows did not allow cloudflared command-line inspection. The launcher will not stop or reuse a process it cannot verify.'
    }
    return @($all | Where-Object {
        $actualPath = if ($_.ExecutablePath) { [System.IO.Path]::GetFullPath($_.ExecutablePath) } else { '' }
        [string]::Equals($actualPath, $cloudflaredPath, [System.StringComparison]::OrdinalIgnoreCase) -and
        $_.CommandLine -match '(?i)\btunnel\b' -and
        $_.CommandLine -match '(?i)--url(?:\s+|=)["'']?http://127\.0\.0\.1(?::80)?(?:["'']?\s|["'']?$)'
    })
}

function Read-TunnelState {
    if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) { return $null }
    try { return Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json } catch { return $null }
}

function Find-TunnelUrl([string]$LogPath, [int]$Attempts = 1) {
    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        if (Test-Path -LiteralPath $LogPath -PathType Leaf) {
            $logText = Get-Content -LiteralPath $LogPath -Raw
            if (-not [string]::IsNullOrEmpty($logText)) {
                $match = [regex]::Match($logText, $tunnelHostPattern, 'IgnoreCase')
                if ($match.Success) { return $match.Value.ToLowerInvariant() }
            }
        }
        if ($attempt -lt $Attempts) { Start-Sleep -Seconds 1 }
    }
    return $null
}

function Test-PublicLogin([string]$TunnelUrl) {
    $result = Get-WebStatus "$TunnelUrl/SecurePOS/login.php" 15
    return Test-WebResultSuccess $result
}

try {
    Write-Status 'SecurePOS FYP Demo - Quick Tunnel' Cyan
    Write-Status 'Checking local Apache...'
    $apache = Get-WebStatus $originUrl 5
    if (-not (Test-WebResultSuccess $apache)) {
        Write-Status 'Please start Apache and MySQL in XAMPP first.' Yellow
        exit 2
    }

    if (-not (Test-Path -LiteralPath $cloudflaredPath -PathType Leaf)) {
        throw "The signed cloudflared executable was not found at: $cloudflaredPath"
    }
    $signature = Get-AuthenticodeSignature -LiteralPath $cloudflaredPath
    if ($signature.Status -ne [System.Management.Automation.SignatureStatus]::Valid -or
        $null -eq $signature.SignerCertificate -or
        $signature.SignerCertificate.Subject -notmatch '(?i)Cloudflare, Inc\.') {
        throw 'tools\cloudflared.exe does not have a valid Cloudflare Authenticode signature. It will not be run or replaced.'
    }
    if (-not (Test-Path -LiteralPath $configPath -PathType Leaf)) { throw 'Copy config\local.example.php to config\local.php before running the local demo.' }

    $tunnelUrl = $null
    $tunnelProcess = $null
    $tunnelLogPath = $null
    $state = Read-TunnelState
    $secureProcesses = @(Get-AllSecurePOSProcesses)
    $currentConfig = [System.IO.File]::ReadAllText($configPath)
    $currentUrlMatch = [regex]::Match($currentConfig, "(?m)^\s*['`"]base_url['`"]\s*=>\s*['`"]($tunnelHostPattern)/SecurePOS['`"]\s*,")

    foreach ($candidateProcess in $secureProcesses) {
        $candidateUrl = $null
        if ($null -ne $state -and [int]$state.ProcessId -eq [int]$candidateProcess.ProcessId -and $state.TunnelUrl -match "^$tunnelHostPattern`$") {
            $candidateUrl = $state.TunnelUrl.ToLowerInvariant()
        } elseif ($currentUrlMatch.Success) {
            $candidateUrl = $currentUrlMatch.Groups[1].Value.ToLowerInvariant()
        }
        if ($candidateUrl -and (Test-PublicLogin $candidateUrl)) {
            $tunnelProcess = $candidateProcess
            $tunnelUrl = $candidateUrl
            if ($null -ne $state -and [int]$state.ProcessId -eq [int]$candidateProcess.ProcessId) {
                $tunnelLogPath = [string]$state.ErrorLog
            }
            Write-Status "Reusing healthy SecurePOS tunnel (PID $($tunnelProcess.ProcessId))." Green
            break
        }
    }

    if (-not $tunnelUrl) {
        foreach ($staleProcess in $secureProcesses) {
            # Every item was admitted only after executable path and command-line confirmation.
            Write-Status "Stopping stale SecurePOS tunnel (PID $($staleProcess.ProcessId))..." Yellow
            Stop-Process -Id $staleProcess.ProcessId -ErrorAction Stop
            Wait-Process -Id $staleProcess.ProcessId -Timeout 10 -ErrorAction SilentlyContinue
        }
    }

    if (-not $tunnelUrl) {
        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
        $stdoutLog = Join-Path $projectRoot "tools\cloudflared-$stamp.stdout.log"
        $stderrLog = Join-Path $projectRoot "tools\cloudflared-$stamp.stderr.log"
        Write-Status 'Starting a new Cloudflare Quick Tunnel...'
        $started = Start-Process -FilePath $cloudflaredPath -ArgumentList @('tunnel', '--url', $originUrl, '--no-autoupdate') -WorkingDirectory (Split-Path $cloudflaredPath) -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog -WindowStyle Hidden -PassThru
        $tunnelProcess = Get-SecurePOSProcess $started.Id
        if ($null -eq $tunnelProcess) {
            if (-not $started.HasExited) { Stop-Process -Id $started.Id -ErrorAction SilentlyContinue }
            throw 'The new process could not be confirmed as the expected SecurePOS cloudflared command.'
        }
        $tunnelUrl = Find-TunnelUrl $stderrLog 45
        if (-not $tunnelUrl) {
            if (-not $started.HasExited) { Stop-Process -Id $started.Id -ErrorAction SilentlyContinue }
            throw "Cloudflare did not provide a Quick Tunnel hostname. See: $stderrLog"
        }
        $tunnelLogPath = $stderrLog
        [pscustomobject]@{
            ProcessId = $started.Id
            TunnelUrl = $tunnelUrl
            ExecutablePath = $cloudflaredPath
            Command = "tunnel --url $originUrl --no-autoupdate"
            ErrorLog = $stderrLog
            StartedAt = (Get-Date).ToString('o')
        } | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding UTF8
    }

    Write-Status "Tunnel hostname: $tunnelUrl"
    $publicBaseUrl = "$tunnelUrl/SecurePOS"

    if ($tunnelLogPath) {
        Write-Status 'Waiting for cloudflared to register the tunnel connection...'
        Wait-TunnelRegistration $tunnelLogPath $tunnelProcess 30
        Write-Status 'Tunnel connection registered.' Green
    }

    $configText = [System.IO.File]::ReadAllText($configPath)
    $baseUrlPattern = "(?m)^\s*['`"]base_url['`"]\s*=>\s*['`"]([^'`"]*)['`"]\s*,"
    $matches = [regex]::Matches($configText, $baseUrlPattern)
    if ($matches.Count -ne 1) { throw "Expected exactly one SECUREPOS_BASE_URL definition; found $($matches.Count). No configuration was changed." }
    if ($matches[0].Groups[1].Value -ne $publicBaseUrl) {
        $backupStamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
        $backupPath = "$configPath.$backupStamp.bak"
        Copy-Item -LiteralPath $configPath -Destination $backupPath -ErrorAction Stop
        $newConfig = [regex]::Replace($configText, $baseUrlPattern, "    'base_url' => '$publicBaseUrl',")
        $tempConfig = "$configPath.securepos-tmp"
        try {
            [System.IO.File]::WriteAllText($tempConfig, $newConfig, (New-Object System.Text.UTF8Encoding($false)))
            Move-Item -LiteralPath $tempConfig -Destination $configPath -Force
        } finally {
            if (Test-Path -LiteralPath $tempConfig) { Remove-Item -LiteralPath $tempConfig -Force }
        }
        Write-Status "Updated config (backup: $backupPath)" Green
    } else {
        Write-Status 'SECUREPOS_BASE_URL is already current; no configuration change was needed.' Green
    }

    $qrSource = [System.IO.File]::ReadAllText($qrEndpointPath)
    if ($qrSource -notmatch "rtrim\(SECUREPOS_BASE_URL,\s*'/'\)\s*\.\s*'/attendance_scan\.php\?token='") {
        throw 'Attendance QR verification failed: its endpoint is not derived from SECUREPOS_BASE_URL and attendance_scan.php.'
    }

    Write-Status 'Waiting for public routes to become ready (up to 3 minutes)...'
    $readinessDeadline = (Get-Date).AddMinutes(3)
    $retrySeconds = 3
    $loginAttempt = 0
    $loginResult = $null
    do {
        $loginAttempt++
        $loginResult = Get-WebStatus "$publicBaseUrl/login.php" 10
        $loginStatus = Format-WebResult $loginResult
        if ((Test-WebResultSuccess $loginResult)) {
            Write-Status "Login route ready: $loginStatus" Green
            break
        }
        if ($loginAttempt -eq 1 -or $loginAttempt % 5 -eq 0) {
            Write-Status "Login route not ready (attempt $loginAttempt): $loginStatus" Yellow
        }
        if ((Get-Date) -lt $readinessDeadline) { Start-Sleep -Seconds $retrySeconds }
    } while ((Get-Date) -lt $readinessDeadline)

    if (-not (Test-WebResultSuccess $loginResult)) {
        throw "Quick Tunnel provisioning/readiness failure for $publicBaseUrl/login.php. Last error: $(Format-WebResult $loginResult)"
    }

    Write-Status 'Verifying public attendance route...'
    $kioskAttempt = 0
    $kioskResult = $null
    do {
        $kioskAttempt++
        $kioskResult = Get-WebStatus "$publicBaseUrl/attendance_kiosk.php" 10
        $kioskStatus = Format-WebResult $kioskResult
        if ((Test-WebResultSuccess $kioskResult)) {
            Write-Status "Attendance route ready: $kioskStatus" Green
            break
        }
        if ($kioskAttempt -eq 1 -or $kioskAttempt % 5 -eq 0) {
            Write-Status "Attendance route not ready (attempt $kioskAttempt): $kioskStatus" Yellow
        }
        if ((Get-Date) -lt $readinessDeadline) { Start-Sleep -Seconds $retrySeconds }
    } while ((Get-Date) -lt $readinessDeadline)

    if (-not (Test-WebResultSuccess $kioskResult)) {
        throw "Public verification failed for $publicBaseUrl/attendance_kiosk.php. Last error: $(Format-WebResult $kioskResult)"
    }

    Write-Host ''
    Write-Status 'SECUREPOS READY FOR DEMO' Green
    Write-Status $publicBaseUrl Cyan
    Write-Status 'Attendance QR URL generation is using this HTTPS hostname and /SecurePOS/attendance_scan.php.' Green
    exit 0
} catch {
    Write-Host ''
    Write-Status 'SecurePOS demo preparation failed.' Red
    Write-Status $_.Exception.Message Red
    exit 1
}
