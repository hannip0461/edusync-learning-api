[CmdletBinding()]
param(
    [ValidateRange(1, 65535)]
    [int] $Port = 8091,

    [string] $ResultPath = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$siteName = "EduSyncLegacyAsp"
$appPoolName = "EduSyncLegacyAspPool"
$connectionStringVariable = "EDUSYNC_LEGACY_CONNECTION_STRING"
$databaseLogin = "edusync_legacy_reader"
$projectRoot = Split-Path -Parent $PSScriptRoot
$legacyPath = Join-Path $projectRoot "legacy"
$driverVersion = "19.4.2"
$driverUri = "https://download.microsoft.com/download/7bf55274-18ac-4b26-9783-45453a1ab64f/amd64/1033/msoledbsql.msi"
$driverSha256 = "409ADFD93165DD3622B2D7CD0B9C4D96A27B04F9F3FB5599D99ACBE90ADE0638"
$driverDirectory = Join-Path $env:LOCALAPPDATA "Temp\EduSync-IIS-Setup"
$driverPath = Join-Path $driverDirectory "msoledbsql-$driverVersion-x64.msi"
$restartRequired = $false

function Write-SetupStep {
    param([string] $Message)

    Write-Host "[EduSync IIS] $Message"
}

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)

    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw "Run this script from an elevated PowerShell session."
    }
}

function Get-DotEnvValue {
    param(
        [string] $Path,
        [string] $Name,
        [string] $DefaultValue
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return $DefaultValue
    }

    foreach ($line in [IO.File]::ReadAllLines($Path, [Text.Encoding]::UTF8)) {
        if ($line.TrimStart().StartsWith("#")) {
            continue
        }

        $separator = $line.IndexOf("=")
        if ($separator -lt 1) {
            continue
        }

        if ($line.Substring(0, $separator).Trim() -ne $Name) {
            continue
        }

        $value = $line.Substring($separator + 1).Trim()
        if ($value.Length -ge 2) {
            $first = $value[0]
            $last = $value[$value.Length - 1]
            if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) {
                $value = $value.Substring(1, $value.Length - 2)
            }
        }

        return $value
    }

    return $DefaultValue
}

function New-DatabasePassword {
    $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789"
    $bytes = New-Object byte[] 28
    $random = New-Object Security.Cryptography.RNGCryptoServiceProvider

    try {
        $random.GetBytes($bytes)
    }
    finally {
        $random.Dispose()
    }

    $suffix = -join ($bytes | ForEach-Object {
        $alphabet[$_ % $alphabet.Length]
    })

    return "Aa1!$suffix"
}

function Invoke-DockerSql {
    param([string] $Sql)

    $docker = Get-Command docker.exe -ErrorAction Stop
    $processInfo = New-Object Diagnostics.ProcessStartInfo
    $processInfo.FileName = $docker.Source
    $processInfo.WorkingDirectory = $projectRoot
    $processInfo.Arguments = (
        'compose exec -T db bash -lc ' +
        '"/opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa ' +
        '-P \"$MSSQL_SA_PASSWORD\" -b -h -1 -W"'
    )
    $processInfo.UseShellExecute = $false
    $processInfo.CreateNoWindow = $true
    $processInfo.RedirectStandardInput = $true
    $processInfo.RedirectStandardOutput = $true
    $processInfo.RedirectStandardError = $true

    $process = New-Object Diagnostics.Process
    $process.StartInfo = $processInfo

    try {
        [void] $process.Start()
        $process.StandardInput.WriteLine($Sql)
        $process.StandardInput.Close()
        $standardOutput = $process.StandardOutput.ReadToEnd()
        $standardError = $process.StandardError.ReadToEnd()
        $process.WaitForExit()

        return [pscustomobject]@{
            ExitCode = $process.ExitCode
            StandardOutput = $standardOutput
            StandardError = $standardError
        }
    }
    finally {
        $process.Dispose()
    }
}

function Install-SqlOleDbDriver {
    if (Test-Path -LiteralPath "Registry::HKEY_CLASSES_ROOT\MSOLEDBSQL19") {
        Write-SetupStep "Microsoft OLE DB Driver 19 is already installed."
        return
    }

    Write-SetupStep "Verifying Microsoft OLE DB Driver $driverVersion installer."
    New-Item -ItemType Directory -Path $driverDirectory -Force | Out-Null

    if (-not (Test-Path -LiteralPath $driverPath)) {
        Invoke-WebRequest -Uri $driverUri -OutFile $driverPath -UseBasicParsing
    }

    $actualHash = (Get-FileHash -LiteralPath $driverPath -Algorithm SHA256).Hash
    if ($actualHash -ne $driverSha256) {
        throw "The OLE DB installer SHA-256 does not match the pinned Microsoft package."
    }

    $signature = Get-AuthenticodeSignature -LiteralPath $driverPath
    if ($signature.Status -ne "Valid" -or
        $signature.SignerCertificate.Subject -notmatch "^CN=Microsoft Corporation,") {
        throw "The OLE DB installer does not have a valid Microsoft signature."
    }

    $arguments = @(
        "/i"
        "`"$driverPath`""
        "IACCEPTMSOLEDBSQLLICENSETERMS=YES"
        "ADDLOCAL=ALL"
        "/qn"
        "/norestart"
    )
    $process = Start-Process -FilePath "msiexec.exe" -ArgumentList $arguments -Wait -PassThru

    if ($process.ExitCode -eq 3010) {
        $script:restartRequired = $true
    }
    elseif ($process.ExitCode -ne 0) {
        throw "OLE DB Driver installation failed with exit code $($process.ExitCode)."
    }

    if (-not (Test-Path -LiteralPath "Registry::HKEY_CLASSES_ROOT\MSOLEDBSQL19")) {
        throw "MSOLEDBSQL19 was not registered after driver installation."
    }
}

function Enable-IisFeatures {
    $features = @(
        "IIS-WebServerRole"
        "IIS-WebServer"
        "IIS-CommonHttpFeatures"
        "IIS-DefaultDocument"
        "IIS-HttpErrors"
        "IIS-StaticContent"
        "IIS-HealthAndDiagnostics"
        "IIS-HttpLogging"
        "IIS-Security"
        "IIS-RequestFiltering"
        "IIS-ApplicationDevelopment"
        "IIS-ASP"
        "IIS-ISAPIExtensions"
        "IIS-ISAPIFilter"
        "IIS-WebServerManagementTools"
        "IIS-ManagementConsole"
        "IIS-ManagementScriptingTools"
    )

    Write-SetupStep "Enabling IIS and Classic ASP Windows features."
    $result = Enable-WindowsOptionalFeature `
        -Online `
        -FeatureName $features `
        -All `
        -NoRestart `
        -ErrorAction Stop

    if ($result.RestartNeeded -contains $true) {
        $script:restartRequired = $true
    }

    foreach ($feature in @("IIS-WebServerRole", "IIS-ASP", "IIS-ISAPIExtensions")) {
        $state = Get-WindowsOptionalFeature -Online -FeatureName $feature
        if ($state.State -ne "Enabled") {
            throw "Windows feature $feature is not enabled."
        }
    }
}

function Wait-ForDatabase {
    Write-SetupStep "Starting Docker SQL Server and waiting for readiness."
    Push-Location $projectRoot

    try {
        & docker compose up -d db
        if ($LASTEXITCODE -ne 0) {
            throw "Docker SQL Server could not be started."
        }

        for ($attempt = 0; $attempt -lt 60; $attempt++) {
            $check = Invoke-DockerSql -Sql "SET NOCOUNT ON; SELECT 1;"
            if ($check.ExitCode -eq 0) {
                return
            }

            Start-Sleep -Seconds 2
        }

        throw "Docker SQL Server did not become ready in time."
    }
    finally {
        Pop-Location
    }
}

function Set-DatabaseReader {
    param(
        [string] $Database,
        [string] $Password
    )

    Write-SetupStep "Configuring the least-privilege SQL login for Classic ASP."
    $escapedPassword = $Password.Replace("'", "''")
    $quotedDatabase = "[" + $Database.Replace("]", "]]") + "]"
    $sql = @"
SET NOCOUNT ON;
USE [master];
IF EXISTS (SELECT 1 FROM sys.sql_logins WHERE name = N'$databaseLogin')
    ALTER LOGIN [$databaseLogin] WITH PASSWORD = N'$escapedPassword';
ELSE
    CREATE LOGIN [$databaseLogin]
        WITH PASSWORD = N'$escapedPassword', CHECK_POLICY = ON, CHECK_EXPIRATION = OFF;
ALTER LOGIN [$databaseLogin] ENABLE;

USE $quotedDatabase;
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = N'$databaseLogin')
    CREATE USER [$databaseLogin] FOR LOGIN [$databaseLogin];
GRANT SELECT ON OBJECT::[dbo].[lecture_progress] TO [$databaseLogin];
DENY INSERT, UPDATE, DELETE ON OBJECT::[dbo].[lecture_progress] TO [$databaseLogin];
"@

    Push-Location $projectRoot
    try {
        $result = Invoke-DockerSql -Sql $sql
        if ($result.ExitCode -ne 0) {
            throw "The Classic ASP SQL login could not be configured."
        }
    }
    finally {
        Pop-Location
    }
}

function Set-AppPoolEnvironmentVariable {
    param(
        [string] $PoolName,
        [string] $Name,
        [string] $Value
    )

    $assembly = Join-Path $env:windir "System32\inetsrv\Microsoft.Web.Administration.dll"
    Add-Type -Path $assembly -ErrorAction SilentlyContinue
    $serverManager = New-Object Microsoft.Web.Administration.ServerManager

    try {
        $configuration = $serverManager.GetApplicationHostConfiguration()
        $section = $configuration.GetSection("system.applicationHost/applicationPools")
        $pools = $section.GetCollection()
        $pool = $null

        foreach ($candidate in $pools) {
            if ($candidate.GetAttributeValue("name") -eq $PoolName) {
                $pool = $candidate
                break
            }
        }

        if ($null -eq $pool) {
            throw "IIS application pool '$PoolName' was not found."
        }

        $variables = $pool.GetCollection("environmentVariables")
        $variable = $null

        foreach ($candidate in $variables) {
            if ($candidate.GetAttributeValue("name") -eq $Name) {
                $variable = $candidate
                break
            }
        }

        if ($null -eq $variable) {
            $variable = $variables.CreateElement("add")
            $variable.SetAttributeValue("name", $Name)
            $variable.SetAttributeValue("value", $Value)
            $variables.Add($variable) | Out-Null
        }
        else {
            $variable.SetAttributeValue("value", $Value)
        }
        $serverManager.CommitChanges()
    }
    finally {
        $serverManager.Dispose()
    }
}

function Set-LegacyDirectoryAcl {
    $identity = "IIS AppPool\$appPoolName"
    $acl = Get-Acl -LiteralPath $legacyPath
    $rule = New-Object Security.AccessControl.FileSystemAccessRule(
        $identity,
        [Security.AccessControl.FileSystemRights]::ReadAndExecute,
        [Security.AccessControl.InheritanceFlags]"ContainerInherit, ObjectInherit",
        [Security.AccessControl.PropagationFlags]::None,
        [Security.AccessControl.AccessControlType]::Allow
    )

    $acl.SetAccessRule($rule)
    Set-Acl -LiteralPath $legacyPath -AclObject $acl
}

function Set-IisSite {
    param([string] $ConnectionString)

    Write-SetupStep "Configuring the localhost-only IIS site and application pool."
    Import-Module WebAdministration -ErrorAction Stop

    $appPoolPath = "IIS:\AppPools\$appPoolName"
    if (-not (Test-Path $appPoolPath)) {
        New-WebAppPool -Name $appPoolName | Out-Null
    }

    Set-ItemProperty $appPoolPath -Name managedRuntimeVersion -Value ""
    Set-ItemProperty $appPoolPath -Name managedPipelineMode -Value "Integrated"
    Set-ItemProperty $appPoolPath -Name enable32BitAppOnWin64 -Value $false
    Set-ItemProperty $appPoolPath -Name processModel.identityType -Value 4

    $site = Get-Website -Name $siteName -ErrorAction SilentlyContinue
    if ($null -eq $site) {
        $listener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
        if ($null -ne $listener) {
            throw "TCP port $Port is already in use."
        }

        New-Website `
            -Name $siteName `
            -IPAddress "127.0.0.1" `
            -Port $Port `
            -PhysicalPath $legacyPath `
            -ApplicationPool $appPoolName |
            Out-Null
    }
    else {
        $configuredPath = [Environment]::ExpandEnvironmentVariables($site.PhysicalPath)
        if ([IO.Path]::GetFullPath($configuredPath) -ne [IO.Path]::GetFullPath($legacyPath)) {
            throw "Existing IIS site '$siteName' points to a different physical path."
        }

        Set-ItemProperty "IIS:\Sites\$siteName" -Name applicationPool -Value $appPoolName
    }

    Set-WebConfigurationProperty `
        -PSPath "IIS:\" `
        -Location $siteName `
        -Filter "system.webServer/security/authentication/anonymousAuthentication" `
        -Name enabled `
        -Value $true
    Set-WebConfigurationProperty `
        -PSPath "IIS:\" `
        -Location $siteName `
        -Filter "system.webServer/security/authentication/anonymousAuthentication" `
        -Name userName `
        -Value ""

    Set-LegacyDirectoryAcl
    Set-AppPoolEnvironmentVariable `
        -PoolName $appPoolName `
        -Name $connectionStringVariable `
        -Value $ConnectionString

    if ((Get-WebAppPoolState -Name $appPoolName).Value -eq "Started") {
        Restart-WebAppPool -Name $appPoolName
    }
    else {
        Start-WebAppPool -Name $appPoolName
    }

    if ((Get-WebsiteState -Name $siteName).Value -ne "Started") {
        Start-Website -Name $siteName
    }
}

function Test-LegacyEndpoint {
    param([string] $ConnectionString)

    Write-SetupStep "Verifying the ADO connection and live Classic ASP response."
    $connection = New-Object -ComObject "ADODB.Connection"
    $recordset = $null

    try {
        $connection.ConnectionTimeout = 10
        $connection.Open($ConnectionString)
        $recordset = $connection.Execute(
            "SELECT TOP (1) learner_id, lecture_id FROM dbo.lecture_progress ORDER BY learner_id, lecture_id"
        )

        if ($recordset.EOF) {
            throw "lecture_progress has no row available for the live IIS check."
        }

        $learnerId = [string] $recordset.Fields.Item("learner_id").Value
        $lectureId = [string] $recordset.Fields.Item("lecture_id").Value
    }
    finally {
        if ($null -ne $recordset) {
            $recordset.Close()
        }
        if ($connection.State -ne 0) {
            $connection.Close()
        }
    }

    $uri = "http://127.0.0.1:$Port/progress.asp?learner_id=$learnerId&lecture_id=$lectureId"
    $aspFilter = "system.webServer/asp"
    $httpErrorsFilter = "system.webServer/httpErrors"

    Set-WebConfigurationProperty `
        -PSPath "IIS:\" `
        -Location $siteName `
        -Filter $aspFilter `
        -Name scriptErrorSentToBrowser `
        -Value $true
    Set-WebConfigurationProperty `
        -PSPath "IIS:\" `
        -Location $siteName `
        -Filter $httpErrorsFilter `
        -Name existingResponse `
        -Value "PassThrough"

    try {
        try {
            $response = Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 20
        }
        catch [Net.WebException] {
            $errorResponse = $_.Exception.Response
            if ($null -eq $errorResponse) {
                throw
            }

            $reader = New-Object IO.StreamReader($errorResponse.GetResponseStream())
            try {
                $errorBody = $reader.ReadToEnd()
            }
            finally {
                $reader.Dispose()
            }

            $plainError = [regex]::Replace($errorBody, "<[^>]+>", " ")
            $plainError = [Net.WebUtility]::HtmlDecode($plainError)
            $plainError = [regex]::Replace($plainError, "\s+", " ").Trim()
            if ($plainError.Length -gt 1500) {
                $plainError = $plainError.Substring(0, 1500)
            }

            throw "Classic ASP request failed with HTTP $([int] $errorResponse.StatusCode): $plainError"
        }
    }
    finally {
        Set-WebConfigurationProperty `
            -PSPath "IIS:\" `
            -Location $siteName `
            -Filter $aspFilter `
            -Name scriptErrorSentToBrowser `
            -Value $false
        Set-WebConfigurationProperty `
            -PSPath "IIS:\" `
            -Location $siteName `
            -Filter $httpErrorsFilter `
            -Name existingResponse `
            -Value "Auto"
    }

    if ($response.StatusCode -ne 200) {
        throw "The Classic ASP endpoint returned HTTP $($response.StatusCode)."
    }

    $payload = $response.Content | ConvertFrom-Json
    if ([string] $payload.learner_id -ne $learnerId -or
        [string] $payload.lecture_id -ne $lectureId) {
        throw "The Classic ASP response identifiers do not match the database row."
    }

    return [pscustomobject]@{
        Url = $uri
        StatusCode = $response.StatusCode
        LearnerId = $learnerId
        LectureId = $lectureId
    }
}

function Write-ResultFile {
    param(
        [string] $Path,
        [string] $Content
    )

    if ([string]::IsNullOrWhiteSpace($Path)) {
        return
    }

    $directory = Split-Path -Parent $Path
    if (-not [string]::IsNullOrWhiteSpace($directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    [IO.File]::WriteAllText($Path, $Content, (New-Object Text.UTF8Encoding($false)))
}

try {
    Assert-Administrator

    if (-not (Test-Path -LiteralPath (Join-Path $legacyPath "progress.asp")) -or
        -not (Test-Path -LiteralPath (Join-Path $legacyPath "global.asa"))) {
        throw "The Classic ASP source or global.asa file is missing."
    }

    $database = Get-DotEnvValue `
        -Path (Join-Path $projectRoot ".env") `
        -Name "DB_DATABASE" `
        -DefaultValue "edusync"

    if ($database -notmatch "^[A-Za-z0-9_]+$") {
        throw "DB_DATABASE may contain only letters, numbers, and underscores."
    }

    Install-SqlOleDbDriver
    Enable-IisFeatures
    Wait-ForDatabase

    $databasePassword = New-DatabasePassword
    Set-DatabaseReader -Database $database -Password $databasePassword

    $connectionString = (
        "Provider=MSOLEDBSQL19;" +
        "Data Source=127.0.0.1,1433;" +
        "Initial Catalog=$database;" +
        "User ID=$databaseLogin;" +
        "Password=$databasePassword;" +
        "Encrypt=Mandatory;" +
        "Trust Server Certificate=True;"
    )

    Set-IisSite -ConnectionString $connectionString
    $endpoint = Test-LegacyEndpoint -ConnectionString $connectionString

    $result = [pscustomobject]@{
        Succeeded = $true
        SiteName = $siteName
        AppPoolName = $appPoolName
        Binding = "http://127.0.0.1:$Port"
        Driver = "MSOLEDBSQL19"
        DatabaseLogin = $databaseLogin
        DatabasePermissions = "SELECT dbo.lecture_progress; DML denied"
        Endpoint = $endpoint
        RestartRequired = $restartRequired
    } | ConvertTo-Json -Depth 4

    Write-ResultFile -Path $ResultPath -Content $result
    Write-Output $result
}
catch {
    $result = [pscustomobject]@{
        Succeeded = $false
        Error = $_.Exception.Message
        ScriptStackTrace = $_.ScriptStackTrace
        Position = $_.InvocationInfo.PositionMessage
    } | ConvertTo-Json

    Write-ResultFile -Path $ResultPath -Content $result
    Write-Error $_.Exception.Message
    exit 1
}
