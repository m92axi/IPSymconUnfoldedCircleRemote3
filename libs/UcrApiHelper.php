<?php

declare(strict_types=1);

/**
 * Shared helper for Remote 3 REST operations used by multiple Symcon module instances.
 *
 * NOTE: This helper intentionally calls Symcon methods via the injected $ctx (module instance)
 * so SendDebug/ReadPropertyString/ReadAttribute* etc. continue to work as before.
 */
class UcrApiHelper
{
    /** @var object Symcon module instance (IPSModule/IPSModuleStrict) */
    private object $ctx;

    public function __construct(object $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * Decide CURLOPT_IPRESOLVE for the given host.
     *
     * - If host is a literal IPv4 address, force IPv4.
     * - If host is a literal IPv6 address (incl. ULA/GUA/link-local), force IPv6.
     * - If host is a hostname, do not force resolution (let system decide).
     */
    private function GetCurlIpResolveOption(string $host): ?int
    {
        $host = trim($host);
        if ($host === '') {
            return null;
        }

        // Strip zone-id if present (fe80::1%8)
        $hostNoZone = explode('%', $host, 2)[0];

        if (filter_var($hostNoZone, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return CURL_IPRESOLVE_V4;
        }
        if (filter_var($hostNoZone, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return CURL_IPRESOLVE_V6;
        }
        return null;
    }

    /**
     * Choose the best host for REST operations.
     *
     * If the discovered host is IPv6 link-local (fe80::/10), prefer a non-link-local alternative
     * if available (IPv4 or hostname) because REST may not be reachable via link-local on all stacks.
     *
     * Optional attributes (if present in the module):
     * - remote_host_ipv4: an IPv4 address for the Remote
     * - remote_host_name: a resolvable hostname (e.g. homeserver.local)
     */
    private function PickRestHost(string $rawHost): string
    {
        $rawHost = trim($rawHost);
        if ($rawHost === '') {
            return '';
        }

        // Link-local IPv6 detected?
        $isLinkLocalV6 = (stripos($rawHost, 'fe80:') === 0);
        if (!$isLinkLocalV6) {
            return $rawHost;
        }

        // Prefer IPv4 if available
        if (method_exists($this->ctx, 'Ext_ReadAttributeString')) {
            $ipv4 = (string)$this->ctx->Ext_ReadAttributeString('remote_host_ipv4');
            $ipv4 = trim($ipv4);
            if ($ipv4 !== '') {
                $this->dbg(__FUNCTION__, '🧠 REST host pick: prefer IPv4 over link-local IPv6: ' . $ipv4 . ' (was ' . $rawHost . ')', 0, 'API', 0);
                return $ipv4;
            }

            // Prefer hostname if available
            $name = (string)$this->ctx->Ext_ReadAttributeString('remote_host_name');
            $name = trim($name);
            if ($name !== '') {
                $this->dbg(__FUNCTION__, '🧠 REST host pick: prefer hostname over link-local IPv6: ' . $name . ' (was ' . $rawHost . ')', 0, 'API', 0);
                return $name;
            }
        }

        // No better alternative known
        $this->dbg(__FUNCTION__, '🧠 REST host pick: using link-local IPv6 (no alternative stored): ' . $rawHost, 0, 'API', 0);
        return $rawHost;
    }

    /**
     * Helper-safe debug output.
     * Uses the module's public Debug() so topic filtering works.
     */
    private function dbg(string $message, $data = '', int $level = 0, string $topic = 'API', int $format = 0): void
    {
        // Preferred: module's public Debug() method (supports topic filtering)
        if (method_exists($this->ctx, 'Debug')) {
            // Debug() returns bool, ignore result
            $this->ctx->Debug($message, $level, $topic, $data, $format);
            return;
        }

        // Fallback: log to system log if Debug() is not available
        $suffix = '';
        if (is_scalar($data)) {
            $suffix = (string)$data;
        } else {
            $suffix = json_encode($data);
        }
        IPS_LogMessage('UCR', $message . ($suffix !== '' ? ' | ' . $suffix : ''));
    }

    /**
     * Normalize the configured remote host into a curl-safe base URL.
     *
     * Handles:
     * - optional scheme (http/https)
     * - optional port
     * - optional path (will be stripped)
     * - IPv6 (adds brackets)
     * - IPv6 scope-id for link-local addresses (encodes '%' as '%25')
     *
     * Examples:
     *   fe80::1%8            -> http://[fe80::1%258]
     *   [fe80::1%258]:8080   -> http://[fe80::1%258]:8080
     *   192.168.1.10         -> http://192.168.1.10
     *   http://dock.local:80 -> http://dock.local:80
     */
    private function BuildRemoteBaseUrl(string $rawHost, string $defaultScheme = 'http'): string
    {
        $rawHost = trim($rawHost);
        if ($rawHost === '') {
            return '';
        }

        $scheme = $defaultScheme;
        $hostPort = $rawHost;

        // Strip scheme if present
        if (preg_match('~^(https?)://(.+)$~i', $hostPort, $m)) {
            $scheme = strtolower($m[1]);
            $hostPort = $m[2];
        }

        // Strip any path/query/fragment
        $slashPos = strpos($hostPort, '/');
        if ($slashPos !== false) {
            $hostPort = substr($hostPort, 0, $slashPos);
        }
        $qPos = strpos($hostPort, '?');
        if ($qPos !== false) {
            $hostPort = substr($hostPort, 0, $qPos);
        }
        $hashPos = strpos($hostPort, '#');
        if ($hashPos !== false) {
            $hostPort = substr($hostPort, 0, $hashPos);
        }

        $hostPort = trim($hostPort);
        if ($hostPort === '') {
            return '';
        }

        $host = $hostPort;
        $port = '';

        // IPv6 in brackets: [addr%scope]:port
        if ($hostPort[0] === '[') {
            $end = strpos($hostPort, ']');
            if ($end !== false) {
                $host = substr($hostPort, 1, $end - 1);
                $rest = substr($hostPort, $end + 1);
                if (strlen($rest) > 0 && $rest[0] === ':') {
                    $port = substr($rest, 1);
                }
            }
        } else {
            // If it contains exactly one ':' => host:port (IPv4/hostname)
            // If it contains multiple ':' => IPv6 without brackets (no port parsing here)
            $colonCount = substr_count($hostPort, ':');
            if ($colonCount === 1) {
                [$h, $p] = explode(':', $hostPort, 2);
                $host = $h;
                $port = $p;
            }
        }

        $host = trim($host);
        $port = trim($port);

        // Detect IPv6 (contains ':')
        $isIpv6 = (strpos($host, ':') !== false);

        // Encode scope-id if present. Important for link-local addresses.
        // If it's already encoded (%25), do NOT double-encode.
        if (strpos($host, '%') !== false && strpos($host, '%25') === false) {
            $host = str_replace('%', '%25', $host);
        }

        $authority = $isIpv6 ? ('[' . $host . ']') : $host;
        if ($port !== '') {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority;
    }

    /**
     * Extract an IPv6 zone/scope id from the raw host (RFC6874).
     *
     * For link-local IPv6 (fe80::/10), a zone id is required on the local machine.
     * Examples:
     *   fe80::1%8        -> "8"
     *   [fe80::1%258]    -> "8"
     *   fe80::1%eth0     -> "eth0"
     */
    private function ExtractIpv6ZoneId(string $rawHost): string
    {
        $rawHost = trim($rawHost);
        if ($rawHost === '') {
            return '';
        }

        // Strip scheme if present
        if (preg_match('~^(https?)://(.+)$~i', $rawHost, $m)) {
            $rawHost = $m[2];
        }

        // Strip any path/query/fragment
        $cut = strcspn($rawHost, "/?#");
        $rawHost = substr($rawHost, 0, $cut);

        // If bracketed, take inside
        if (strlen($rawHost) > 0 && $rawHost[0] === '[') {
            $end = strpos($rawHost, ']');
            if ($end !== false) {
                $rawHost = substr($rawHost, 1, $end - 1);
            }
        }

        // Remove :port for non-IPv6-with-colons? (we only care about zone id after %)
        // Zone-id is always after '%', so port isn't relevant.

        $pos = strpos($rawHost, '%');
        if ($pos === false) {
            return '';
        }

        $zone = substr($rawHost, $pos + 1);
        $zone = trim($zone);
        if ($zone === '') {
            return '';
        }

        // If already URL-encoded (%25), it may show up as "25<zone>" only if '%' was stripped.
        // Commonly we see %25 in the host string itself, so normalize "25" prefix.
        if (str_starts_with($zone, '25')) {
            // Only strip if the remainder looks like a reasonable zone id
            $maybe = substr($zone, 2);
            if ($maybe !== '') {
                $zone = $maybe;
            }
        }

        // Decode any %25 that might still be present
        $zone = str_replace('%25', '%', $zone);
        // Final cleanup: zone id must not contain ':' or ']'
        $zone = str_replace([':', ']'], '', $zone);

        return $zone;
    }

    /**
     * Ensure an API key exists and is valid.
     * Returns true if a valid key is stored in the module attribute `api_key`.
     */
    public function EnsureApiKey(bool $forceRenew = false): bool
    {
        $this->dbg(__FUNCTION__, 'started' . ($forceRenew ? ' (forceRenew=true)' : ''), 0, 'API', 0);

        // --- read config ---
        $hostRaw = $this->ctx->Ext_ReadAttributeString('remote_host');
        $host = $this->PickRestHost($hostRaw);
        $user = $this->ctx->Ext_ReadPropertyString('web_config_user');
        $pass = $this->ctx->Ext_ReadAttributeString('web_config_pass');

        if ($host === '' || $user === '' || $pass === '') {
            $this->dbg(__FUNCTION__, '❌ Fehlende Konfiguration (host/user/pass).', 0, 'API', 0);
            return false;
        }

        $baseUrl = $this->BuildRemoteBaseUrl($host);
        if ($baseUrl === '') {
            $this->dbg(__FUNCTION__, '❌ Fehler: Host ungültig/leer nach Normalisierung.', 0, 'API', 0);
            return false;
        }
        $this->dbg(__FUNCTION__, '🌐 Normalized baseUrl=' . $baseUrl . ' (raw host=' . $hostRaw . ', rest host=' . $host . ')', 0, 'API', 0);
        $zoneId = $this->ExtractIpv6ZoneId($host);
        if ($zoneId !== '') {
            $this->dbg(__FUNCTION__, '🧭 Detected IPv6 zone-id=' . $zoneId . ' (link-local requires this on the client host)', 0, 'API', 0);
        }

        // Ensure api_key_name exists
        if ($this->ctx->Ext_ReadAttributeString('api_key_name') === '') {
            $uuid = $this->ctx->Ext_ReadAttributeString('symcon_uuid');
            if ($uuid === '') {
                $uuid = bin2hex(random_bytes(8));
                $this->ctx->Ext_WriteAttributeString('symcon_uuid', $uuid);
            }
            $this->ctx->Ext_WriteAttributeString('api_key_name', 'Symcon Access ' . $uuid);
        }

        $name = $this->ctx->Ext_ReadAttributeString('api_key_name');
        if ($name === '') {
            $this->dbg(__FUNCTION__, '❌ Fehler: api_key_name leer.', 0, 'API', 0);
            return false;
        }

        // Provide zone-id to the HTTP closure without changing all its call sites
        $GLOBALS['__ucr_zoneId'] = $zoneId ?? '';
        // --- helper: HTTP request ---
        $httpRequest = function (string $method, string $url, array $headers = [], ?string $basicUser = null, ?string $basicPass = null, ?string $body = null) use ($host): array {
            $this->dbg(__FUNCTION__, '➡️ HTTP ' . strtoupper($method) . ' ' . $url, 0, 'API', 0);

            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
            ];

            // Resolve strategy: only force when we have an IP literal.
            $ipResolve = $this->GetCurlIpResolveOption($host);
            if ($ipResolve !== null) {
                $opts[CURLOPT_IPRESOLVE] = $ipResolve;
                $this->dbg(__FUNCTION__, '🧭 CURLOPT_IPRESOLVE=' . ($ipResolve === CURL_IPRESOLVE_V6 ? 'V6' : 'V4'), 0, 'API', 0);
            }

            // For IPv6 link-local addresses, libcurl on some platforms behaves more reliably
            // when the outgoing interface is pinned. If the configured host contains a zone-id
            // (e.g. %8 or %eth0), we use it as CURLOPT_INTERFACE.
            // Note: even if curl error messages omit the zone-id, the URL may still contain it.
            $detectedZoneId = '';
            if (isset($GLOBALS['__ucr_zoneId'])) {
                $detectedZoneId = (string)$GLOBALS['__ucr_zoneId'];
            }

            // On Windows, CURLOPT_INTERFACE typically expects an interface *name* (e.g. "Ethernet") or an IP,
            // not a numeric ifIndex like "8". Using a numeric value can trigger: "Couldn't bind to '8'".
            // For numeric zone-ids we rely on the RFC6874 zone in the URL ("%25<id>") instead.
            if ($detectedZoneId !== '' && !ctype_digit($detectedZoneId) && strpos($url, '[') !== false && strpos($url, ']') !== false) {
                $opts[CURLOPT_INTERFACE] = $detectedZoneId;
            }

            if ($basicUser !== null && $basicPass !== null) {
                $opts[CURLOPT_USERPWD] = $basicUser . ':' . $basicPass;
            }
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }

            if (isset($opts[CURLOPT_INTERFACE])) {
                $this->dbg(__FUNCTION__, '🧷 CURLOPT_INTERFACE=' . (string)$opts[CURLOPT_INTERFACE], 0, 'API', 0);
            }

            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            return [
                'httpCode' => $httpCode,
                'response' => ($resp === false ? '' : $resp),
                'error' => $err
            ];
        };

        // --- Step 1: Validate stored API key by doing a Bearer request ---
        $storedApiKey = $this->ctx->Ext_ReadAttributeString('api_key');
        if (!$forceRenew && $storedApiKey !== '') {
            $this->dbg(__FUNCTION__, '🔐 Prüfe gespeicherten API-Key via /api/system ...', 0, 'API', 0);
            $test = $httpRequest(
                'GET',
                $baseUrl . '/api/system',
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $storedApiKey
                ]
            );

            $this->dbg(__FUNCTION__, '🔐 Bearer Test HTTP-Code: ' . $test['httpCode'], 0, 'API', 0);
            if ($test['error'] !== '') {
                $this->dbg(__FUNCTION__, '❌ CURL Error (bearer test): ' . $test['error'], 0, 'API', 0);
            }

            // 200 => key ok, keep it
            if ($test['httpCode'] >= 200 && $test['httpCode'] < 300) {
                $this->dbg(__FUNCTION__, '✅ Gespeicherter API-Key ist gültig.', 0, 'API', 0);
                return true;
            }

            // 401/403 (or any non-2xx) => treat as invalid, renew
            $this->dbg(__FUNCTION__, '⚠️ Gespeicherter API-Key scheint ungültig oder nicht mehr berechtigt. Erneuerung wird gestartet.', 0, 'API', 0);
            $forceRenew = true;
        }

        // --- Step 2: With Basic Auth, check whether a key with our name exists and is active ---
        $this->dbg(__FUNCTION__, '🔎 Prüfe vorhandene API-Keys via Basic Auth: ' . $name, 0, 'API', 0);
        $list = $httpRequest(
            'GET',
            $baseUrl . '/api/auth/api_keys?active=true',
            ['Content-Type: application/json'],
            $user,
            $pass
        );

        $this->dbg(__FUNCTION__, '📥 Existing Key Request HTTP-Code: ' . $list['httpCode'], 0, 'API', 0);
        $this->dbg(__FUNCTION__, '📥 Existing Key Response: ' . $list['response'], 0, 'API', 0);

        if ($list['error'] !== '') {
            $this->dbg(__FUNCTION__, '❌ CURL Error (list): ' . $list['error'], 0, 'API', 0);
            return false;
        }
        if ($list['httpCode'] === 401 || $list['httpCode'] === 403) {
            $this->dbg(__FUNCTION__, '❌ Basic Auth fehlgeschlagen (user/pass ungültig).', 0, 'API', 0);
            return false;
        }

        $existingKeys = json_decode($list['response'], true);
        if (!is_array($existingKeys)) {
            $this->dbg(__FUNCTION__, '❌ Ungültige Antwortstruktur beim Abrufen vorhandener Keys.', 0, 'API', 0);
            return false;
        }

        $keys = isset($existingKeys['results']) ? $existingKeys['results'] : $existingKeys;

        $foundKeyId = null;
        foreach ($keys as $key) {
            if (isset($key['name']) && $key['name'] === $name) {
                // API returns key identifier as key_id
                if (isset($key['key_id'])) {
                    $foundKeyId = $key['key_id'];
                }
                break;
            }
        }

        // --- Step 3: If we want to renew or we have no stored key, revoke existing key with same name (if any) ---
        if ($forceRenew || $storedApiKey === '') {
            // Clear local key first
            if ($storedApiKey !== '') {
                $this->ctx->Ext_WriteAttributeString('api_key', '');
                $storedApiKey = '';
            }

            if ($foundKeyId !== null) {
                $this->dbg(__FUNCTION__, '🧹 Revoke existing key with same name. key_id=' . $foundKeyId, 0, 'API', 0);
                $del = $httpRequest(
                    'DELETE',
                    $baseUrl . '/api/auth/api_keys/' . urlencode((string)$foundKeyId),
                    ['Content-Type: application/json'],
                    $user,
                    $pass
                );
                $this->dbg(__FUNCTION__, '🧹 Revoke HTTP-Code: ' . $del['httpCode'], 0, 'API', 0);
                $this->dbg(__FUNCTION__, '🧹 Revoke Response: ' . $del['response'], 0, 'API', 0);
                if ($del['error'] !== '') {
                    $this->dbg(__FUNCTION__, '❌ CURL Error (revoke): ' . $del['error'], 0, 'API', 0);
                    return false;
                }
                // if revoke fails, still try to create a new key with a new name as fallback
                if ($del['httpCode'] < 200 || $del['httpCode'] >= 300) {
                    $this->dbg(__FUNCTION__, '⚠️ Revoke nicht erfolgreich. Fallback: neuer Key-Name wird generiert.', 0, 'API', 0);
                    $uuid = $this->ctx->Ext_ReadAttributeString('symcon_uuid');
                    if ($uuid === '') {
                        $uuid = bin2hex(random_bytes(8));
                        $this->ctx->Ext_WriteAttributeString('symcon_uuid', $uuid);
                    }
                    $newName = 'Symcon Access ' . $uuid . ' ' . date('YmdHis');
                    $this->ctx->Ext_WriteAttributeString('api_key_name', $newName);
                    $name = $newName;
                    $foundKeyId = null;
                }
            }
        } else {
            // Not forceRenew and no stored key means we cannot use existing token value.
            // If the key exists on remote but is not stored locally, we must revoke and re-create.
            if ($storedApiKey === '' && $foundKeyId !== null) {
                $this->dbg(__FUNCTION__, '⚠️ Key existiert auf der Remote, aber token fehlt lokal. Revoke + Neu erstellen.', 0, 'API', 0);
                $forceRenew = true;
                $del = $httpRequest(
                    'DELETE',
                    $baseUrl . '/api/auth/api_keys/' . urlencode((string)$foundKeyId),
                    ['Content-Type: application/json'],
                    $user,
                    $pass
                );
                $this->dbg(__FUNCTION__, '🧹 Revoke HTTP-Code: ' . $del['httpCode'], 0, 'API', 0);
                $this->dbg(__FUNCTION__, '🧹 Revoke Response: ' . $del['response'], 0, 'API', 0);
                if ($del['error'] !== '') {
                    $this->dbg(__FUNCTION__, '❌ CURL Error (revoke): ' . $del['error'], 0, 'API', 0);
                    return false;
                }
                if ($del['httpCode'] < 200 || $del['httpCode'] >= 300) {
                    $this->dbg(__FUNCTION__, '⚠️ Revoke nicht erfolgreich. Fallback: neuer Key-Name wird generiert.', 0, 'API', 0);
                    $uuid = $this->ctx->Ext_ReadAttributeString('symcon_uuid');
                    if ($uuid === '') {
                        $uuid = bin2hex(random_bytes(8));
                        $this->ctx->Ext_WriteAttributeString('symcon_uuid', $uuid);
                    }
                    $newName = 'Symcon Access ' . $uuid . ' ' . date('YmdHis');
                    $this->ctx->Ext_WriteAttributeString('api_key_name', $newName);
                    $name = $newName;
                }
            }
        }

        // --- Step 4: Create new key (Basic Auth) ---
        $this->dbg(__FUNCTION__, '🆕 Erstelle neuen API-Key: ' . $name, 0, 'API', 0);
        $createBody = json_encode([
            'name' => $name,
            'scopes' => ['admin'],
            'description' => 'Created from Symcon module'
        ]);

        $create = $httpRequest(
            'POST',
            $baseUrl . '/api/auth/api_keys',
            ['Content-Type: application/json'],
            $user,
            $pass,
            $createBody
        );

        $this->dbg(__FUNCTION__, '📥 Create HTTP-Code: ' . $create['httpCode'], 0, 'API', 0);
        $this->dbg(__FUNCTION__, '📥 Create Response: ' . $create['response'], 0, 'API', 0);

        if ($create['error'] !== '') {
            $this->dbg(__FUNCTION__, '❌ CURL Error (create): ' . $create['error'], 0, 'API', 0);
            return false;
        }

        $data = json_decode($create['response'], true);
        if (is_array($data) && isset($data['api_key']) && $data['api_key'] !== '') {
            $newKey = (string)$data['api_key'];
            $this->ctx->Ext_WriteAttributeString('api_key', $newKey);
            $this->dbg(__FUNCTION__, '✅ API-Key gespeichert.', 0, 'API', 0);

            // Auto-upload icon once after obtaining an API key
            if (!$this->ctx->Ext_ReadAttributeBoolean('icon_uploaded')) {
                $this->dbg(__FUNCTION__, '🖼️ Auto-uploading Symcon icon...', 0, 'API', 0);
                $uploadResult = $this->UploadSymconIcon();
                $decodedUpload = json_decode($uploadResult, true);
                if (is_array($decodedUpload) && ($decodedUpload['success'] ?? false) === true) {
                    $this->ctx->Ext_WriteAttributeBoolean('icon_uploaded', true);
                    $this->dbg(__FUNCTION__, '✅ Symcon icon uploaded.', 0, 'API', 0);
                } else {
                    $this->dbg(__FUNCTION__, '⚠️ Symcon icon upload failed: ' . $uploadResult, 0, 'API', 0);
                }
            }

            return true;
        }

        $this->dbg(__FUNCTION__, '❌ Kein API-Key erhalten. Hinweis: Key muss ggf. auf der Remote bestätigt werden.', 0, 'API', 0);
        return false;
    }

    /**
     * Ensure REST API access is possible.
     *
     * This helper will try to obtain a valid API key (validate / renew / create).
     * If no key can be obtained, the caller should request a PIN (web_config_pass).
     *
     * @param string $remoteHost Optional host override. If empty, uses stored attribute `remote_host`.
     * @return array{ok:bool, api_key:string, need_pin:bool, reason:string}
     */
    public function EnsureRemoteApiAccess(string $remoteHost = ''): array
    {
        $remoteHost = trim($remoteHost);
        if ($remoteHost === '') {
            $remoteHost = trim((string)$this->ctx->Ext_ReadAttributeString('remote_host'));
        }

        if ($remoteHost === '') {
            $this->dbg(__FUNCTION__, '❌ remote_host is empty', 0, 'SETUP', 0);
            return ['ok' => false, 'api_key' => '', 'need_pin' => true, 'reason' => 'remote_host_empty'];
        }

        // PIN is stored as attribute (web_config_pass)
        $storedPin = trim((string)$this->ctx->Ext_ReadAttributeString('web_config_pass'));

        // Keep host consistent for subsequent calls (including GetApiKey/EnsureApiKey)
        $this->ctx->Ext_WriteAttributeString('remote_host', $remoteHost);

        // Try to obtain a valid API key (this will validate/renew/create if possible)
        $this->dbg(__FUNCTION__, '🔎 EnsureRemoteApiAccess → trying EnsureApiKey for host ' . $remoteHost, 0, 'SETUP', 0);

        $ok = $this->EnsureApiKey(false);
        $apiKey = trim((string)$this->ctx->Ext_ReadAttributeString('api_key'));

        if ($ok && $apiKey !== '') {
            $this->dbg(__FUNCTION__, '✅ Remote API access OK (api_key_ok)', 0, 'SETUP', 0);
            return ['ok' => true, 'api_key' => $apiKey, 'need_pin' => false, 'reason' => 'api_key_ok'];
        }

        $this->dbg(__FUNCTION__, '❌ Remote API access failed → PIN required', 0, 'SETUP', 0);

        // No API key => PIN is required (missing or wrong/outdated)
        if ($storedPin !== '') {
            return ['ok' => false, 'api_key' => '', 'need_pin' => true, 'reason' => 'stored_pin_no_key'];
        }
        return ['ok' => false, 'api_key' => '', 'need_pin' => true, 'reason' => 'no_pin'];
    }

    /**
     * Public accessor used by modules to obtain a valid API key.
     * Will validate/create a key once required properties are present.
     */
    public function GetApiKey(): string
    {
        $this->dbg(__FUNCTION__, 'started', 0, 'API', 0);

        $host = $this->ctx->Ext_ReadAttributeString('remote_host');
        $pass = $this->ctx->Ext_ReadAttributeString('web_config_pass');

        // Only attempt to create/validate an API key once the required fields are present.
        if ($host !== '' && $pass !== '') {
            $this->EnsureApiKey();
        } else {
            $this->dbg(__FUNCTION__, '⏸️ Skip EnsureApiKey (Host/Password missing).', 0, 'API', 0);
        }

        $apiKey = $this->ctx->Ext_ReadAttributeString('api_key');
        $this->dbg('API Key', $apiKey, 0, 'API', 0);
        return $apiKey;
    }

    /**
     * Reset the stored API key and request a new one from the Remote.
     * This will revoke an existing API key with the same name (if possible) and create a new one.
     */
    public function ResetApiKey(): bool
    {
        $this->dbg(__FUNCTION__, '🔄 Reset API-Key gestartet', 0, 'API', 0);
        // Clear local token first
        $this->ctx->Ext_WriteAttributeString('api_key', '');
        $this->ctx->Ext_WriteAttributeBoolean('icon_uploaded', false);

        // Force renew (revoke + create)
        $ok = $this->EnsureApiKey(true);

        // Rebuild parent configuration so WS uses the new token
        $this->ctx->ApplyChanges();

        $this->dbg(__FUNCTION__, $ok ? '✅ Reset erfolgreich' : '❌ Reset fehlgeschlagen', 0, 'API', 0);
        return $ok;
    }

    /**
     * Uploads the Symcon integration icon to the Remote 3 so it can be shown for the integration.
     * Requires host + API key to be available.
     */
    public function UploadSymconIcon(): string
    {
        $this->dbg(__FUNCTION__, 'started', 0, 'API', 0);

        $hostRaw = $this->ctx->Ext_ReadAttributeString('remote_host');
        $host = $this->PickRestHost($hostRaw);
        if ($hostRaw === '') {
            $msg = 'Host is missing.';
            $this->dbg(__FUNCTION__, '❌ ' . $msg, 0, 'API', 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $baseUrl = $this->BuildRemoteBaseUrl($host);
        if ($baseUrl === '') {
            $msg = 'Host is invalid after normalization.';
            $this->dbg(__FUNCTION__, '❌ ' . $msg, 0, 'API', 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $this->dbg(__FUNCTION__, '🌐 Icon upload baseUrl=' . $baseUrl . ' (raw host=' . $hostRaw . ', rest host=' . $host . ')', 0, 'API', 0);

        $zoneId = $this->ExtractIpv6ZoneId($host);
        if ($zoneId !== '') {
            $this->dbg(__FUNCTION__, '🧭 Detected IPv6 zone-id=' . $zoneId, 0, 'API', 0);
        }

        // Ensure we have an API key first
        if (!$this->EnsureApiKey()) {
            $msg = 'API key missing or could not be created.';
            $this->dbg(__FUNCTION__, '❌ ' . $msg, 0, 'API', 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $apiKey = $this->ctx->Ext_ReadAttributeString('api_key');
        if ($apiKey === '') {
            $msg = 'API key is empty.';
            $this->dbg(__FUNCTION__, '❌ ' . $msg, 0, 'API', 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $candidates = [
            __DIR__ . '/../imgs/symcon_icon.png',          // libs -> imgs
            dirname(__DIR__) . '/imgs/symcon_icon.png',    // module root -> imgs
        ];

        $this->dbg(__FUNCTION__, 'KernelDir=' . IPS_GetKernelDir(), 0, 'API', 0);
        $this->dbg(__FUNCTION__, '__DIR__=' . __DIR__, 0, 'API', 0);

        $filePath = '';
        foreach ($candidates as $p) {
            $rp = realpath($p);
            $this->dbg(__FUNCTION__, 'Icon candidate=' . $p . ' | realpath=' . ($rp ?: 'false'), 0, 'API', 0);

            if ($rp !== false && is_file($rp)) {
                $filePath = $rp;
                break;
            }
        }

        if ($filePath === '') {
            $msg = 'Icon file not found. Checked: ' . implode(' | ', $candidates);
            $this->dbg(__FUNCTION__, '❌ ' . $msg, 0, 'API', 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $this->dbg(__FUNCTION__, '✅ Icon resolved path: ' . $filePath, 0, 'API', 0);

        // Project structure: this helper lives in /libs, icon is in /imgs at module root.
        /*
         *
        $filePath = dirname(__DIR__) . '/imgs/symcon_icon.png';
        $this->dbg(__FUNCTION__, 'Icon path: ' . $filePath, 0, 'API', 0);
        if (!file_exists($filePath)) {
            $msg = 'Icon file not found: ' . $filePath;
            $this->dbg(__FUNCTION__, '❌ ' . $msg, 0, 'API', 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }
        */

        $url = $baseUrl . '/api/resources/Icon';
        $this->dbg(__FUNCTION__, 'POST ' . $url, 0, 'API', 0);

        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        $postFields = [
            'file' => new CURLFile($filePath)
        ];

        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $postFields,
        ];

        // Resolve strategy: only force when we have an IP literal.
        $ipResolve = $this->GetCurlIpResolveOption($host);
        if ($ipResolve !== null) {
            $curlOpts[CURLOPT_IPRESOLVE] = $ipResolve;
            $this->dbg(__FUNCTION__, '🧭 CURLOPT_IPRESOLVE=' . ($ipResolve === CURL_IPRESOLVE_V6 ? 'V6' : 'V4'), 0, 'API', 0);
        }

        // Same as in EnsureApiKey(): numeric zone-ids are common on Windows (ifIndex) but not accepted by CURLOPT_INTERFACE.
        // Rely on the zone in the IPv6 literal instead.
        if ($zoneId !== '' && !ctype_digit($zoneId) && strpos($url, '[') !== false && strpos($url, ']') !== false) {
            $curlOpts[CURLOPT_INTERFACE] = $zoneId;
            $this->dbg(__FUNCTION__, '🧷 CURLOPT_INTERFACE=' . $zoneId, 0, 'API', 0);
        }

        curl_setopt_array($ch, $curlOpts);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->dbg(__FUNCTION__, 'HTTP ' . $httpCode, 0, 'API', 0);
        if ($err !== '') {
            $this->dbg(__FUNCTION__, '❌ CURL Error: ' . $err, 0, 'API', 0);
            return json_encode(['success' => false, 'message' => $err]);
        }

        $this->dbg(__FUNCTION__, 'Response: ' . (string)$resp, 0, 'API', 0);

        if ($httpCode < 200 || $httpCode >= 300) {
            return json_encode(['success' => false, 'message' => 'Upload failed', 'httpCode' => $httpCode, 'response' => (string)$resp]);
        }

        $decoded = json_decode((string)$resp, true);
        if (is_array($decoded)) {
            return json_encode(['success' => true, 'httpCode' => $httpCode, 'data' => $decoded]);
        }

        return json_encode(['success' => true, 'httpCode' => $httpCode, 'response' => (string)$resp]);
    }
}