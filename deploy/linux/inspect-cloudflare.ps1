param(
    [string]$OutputPath = (Join-Path $PSScriptRoot '../../space/.runtime/connection-diagnostics/cloudflare-audit.json')
)
$ErrorActionPreference = 'Stop'
# Read-only API calls. The token exists only in this terminal process, never on disk.
$secureToken = Read-Host 'Cloudflare read-only API token (hidden input; never paste it into chat)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
$token = $null
$headers = @{}
$report = [ordered]@{ at = [DateTime]::UtcNow.ToString('o'); zone = 'ichaa.dev'; checks = [ordered]@{} }
try {
    $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    $headers.Authorization = 'Bearer ' + $token.Trim()
    function Read-Api([string]$Name, [string]$Path, $Query = $null) {
        try {
            $parameters = @{ Uri = ('https://api.cloudflare.com/client/v4/' + $Path); Headers = $headers; TimeoutSec = 20; MaximumRedirection = 0; Method = 'GET' }
            if ($null -ne $Query) {
                $parameters.Method = 'POST'
                $parameters.ContentType = 'application/json'
                $parameters.Body = $Query | ConvertTo-Json -Depth 15 -Compress
            }
            $response = Invoke-RestMethod @parameters
            $codes = @($response.errors | ForEach-Object { if ($_.code) { $_.code } })
            $report.checks[$Name] = @{ success = ($response.success -ne $false -and $codes.Count -eq 0); errorCodes = $codes }
            return $response
        } catch {
            # Never serialize the request, headers, exception body or raw URL.
            $status = $null
            if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
            $report.checks[$Name] = @{ success = $false; httpStatus = $status }
            return $null
        }
    }
    $verify = Read-Api 'token' 'user/tokens/verify'
    if ($verify.result.status -ne 'active') { throw 'The API token did not verify; no account configuration was changed.' }
    $zones = Read-Api 'zone' 'zones?name=ichaa.dev'
    $zone = @($zones.result | Where-Object name -EQ 'ichaa.dev')
    if ($zone.Count -ne 1 -or $zone[0].id -notmatch '^[a-f0-9]{32}$') { throw 'The token could not select exactly the requested zone.' }
    $zoneId = $zone[0].id
    $report.plan = $zone[0].plan.name
    $report.zoneStatus = $zone[0].status
    $report.dns = @()
    foreach ($name in @('bitcraft.ichaa.dev', 'fishbound.ichaa.dev')) {
        $dns = Read-Api ('dns:' + $name) ('zones/' + $zoneId + '/dns_records?name=' + $name)
        $report.dns += @($dns.result | ForEach-Object { @{ name = $_.name; type = $_.type; proxied = $_.proxied; ttl = $_.ttl; pointsToExpectedOrigin = ($_.content -eq '5.189.187.85') } })
    }
    $settings = Read-Api 'settings' ('zones/' + $zoneId + '/settings')
    $allowedSettings = @('websockets','ssl','min_tls_version','tls_1_3','http2','http3','ipv6','0rtt','browser_check','security_level','bot_fight_mode','always_use_https','automatic_https_rewrites','opportunistic_encryption')
    $report.settings = @($settings.result | Where-Object { $_.id -in $allowedSettings } | ForEach-Object { @{ name = $_.id; value = $_.value } })
    $routes = Read-Api 'workerRoutes' ('zones/' + $zoneId + '/workers/routes')
    $report.workerRoutes = @($routes.result | ForEach-Object { @{ pattern = ($_.pattern -replace '\?.*$', ''); hasWorker = [bool]$_.script } })
    $rulesets = Read-Api 'rulesets' ('zones/' + $zoneId + '/rulesets')
    $report.rules = @()
    foreach ($ruleset in @($rulesets.result | Where-Object kind -EQ 'zone' | Select-Object -First 20)) {
        if ($ruleset.id -notmatch '^[a-f0-9]{32}$') { continue }
        $details = Read-Api ('ruleset:' + $ruleset.phase) ('zones/' + $zoneId + '/rulesets/' + $ruleset.id)
        foreach ($rule in $details.result.rules) {
            $terms = @('http.host','http.request.uri','http.request.headers','http.request.method','ip.src','ip.geoip','cf.bot_management','cf.threat_score','cf.waf') | Where-Object { $rule.expression -and $rule.expression.Contains($_) }
            # Rule expressions may contain private header values; keep only their structure.
            $report.rules += @{ phase = $ruleset.phase; action = $rule.action; enabled = ($rule.enabled -ne $false); mentionsSites = ($rule.expression -match 'bitcraft\.ichaa\.dev|fishbound\.ichaa\.dev'); mentionsDatabasePath = ($rule.expression -match '/v1/|subscribe|websocket'); conditionFields = @($terms) }
        }
    }
    $zoneJson = $zoneId | ConvertTo-Json -Compress
    $fromJson = [DateTime]::UtcNow.AddHours(-1).ToString('yyyy-MM-ddTHH:mm:ssZ') | ConvertTo-Json -Compress
    $untilJson = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ') | ConvertTo-Json -Compress
    $query = 'query { viewer { zones(filter: {zoneTag: ' + $zoneJson + '}) { httpRequestsAdaptiveGroups(limit: 100, filter: {datetime_geq: ' + $fromJson + ', datetime_leq: ' + $untilJson + ', clientRequestHTTPHost_in: ["bitcraft.ichaa.dev","fishbound.ichaa.dev"]}, orderBy: [datetimeMinute_ASC]) { count dimensions { datetimeMinute clientRequestHTTPHost edgeResponseStatus coloCode } } } } }'
    $analytics = Read-Api 'analytics' 'graphql' @{ query = $query }
    $report.httpGroups = @($analytics.data.viewer.zones.httpRequestsAdaptiveGroups)
    if ($analytics.errors) { $report.checks.analytics.success = $false; $report.checks.analytics.graphqlErrorCount = @($analytics.errors).Count }
    $rayIds = @('a4ed30a10b751d68','a4ed30922fef3f35','a4ed308e8974d8b7','a4ed2f6ca945afbc','a4ed2f6c9f64a087','a4ed2f6c980315e2')
    $rayJson = $rayIds | ConvertTo-Json -Compress
    $query = 'query { viewer { zones(filter: {zoneTag: ' + $zoneJson + '}) { firewallEventsAdaptive(limit: 100, filter: {datetime_geq: ' + $fromJson + ', datetime_leq: ' + $untilJson + ', rayName_in: ' + $rayJson + '}) { datetime action source rayName } } } }'
    $events = Read-Api 'incidentSecurityEvents' 'graphql' @{ query = $query }
    $report.incidentSecurityEvents = @($events.data.viewer.zones.firewallEventsAdaptive)
    if ($events.errors) { $report.checks.incidentSecurityEvents.success = $false; $report.checks.incidentSecurityEvents.graphqlErrorCount = @($events.errors).Count }
    $directory = [IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($OutputPath))
    if (-not (Test-Path -LiteralPath $directory)) { throw 'The owned diagnostic output directory is missing.' }
    if ((Test-Path -LiteralPath $OutputPath) -and (Get-Item -LiteralPath $OutputPath).Attributes.HasFlag([IO.FileAttributes]::ReparsePoint)) { throw 'Linked diagnostic output refused.' }
    $report | ConvertTo-Json -Depth 15 | Set-Content -LiteralPath $OutputPath -Encoding UTF8
    Write-Host ('Read-only audit saved to ' + [IO.Path]::GetFullPath($OutputPath)) -ForegroundColor Green
    Write-Host 'The token was not saved. You can revoke it after the investigation.'
} finally {
    $headers.Clear(); $token = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    $secureToken.Dispose()
}
