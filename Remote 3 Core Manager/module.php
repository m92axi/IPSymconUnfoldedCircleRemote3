<?php

declare(strict_types=1);

class Remote3CoreManager extends IPSModuleStrict
{
    const DEFAULT_WS_PROTOCOL = 'ws://';

    const DEFAULT_WSS_PROTOCOL = 'wss://';
    const DEFAULT_WS_PORT = 8001;

    const DEFAULT_WSS_PORT = 8443;
    const DEFAULT_WS_PATH = '/ws';

    public function GetCompatibleParents(): string
    {
        // Prefer creating/using a dedicated WebSocket Client connection
        return json_encode([
            'type' => 'require',
            'moduleIDs' => [
                // WebSocket Client
                '{D68FD31F-0E90-7019-F16C-1949BD3079EF}'
            ]
        ]);
    }

    private function EnsureApiKey(bool $forceRenew = false): bool
    {
        $this->SendDebug(__FUNCTION__, 'started' . ($forceRenew ? ' (forceRenew=true)' : ''), 0);

        // --- read config ---
        $host = $this->ReadPropertyString('host');
        $user = $this->ReadPropertyString('web_config_user');
        $pass = $this->ReadPropertyString('web_config_pass');

        if ($host === '' || $user === '' || $pass === '') {
            $this->SendDebug(__FUNCTION__, '❌ Fehlende Konfiguration (host/user/pass).', 0);
            return false;
        }

        // Ensure api_key_name exists
        if ($this->ReadAttributeString('api_key_name') === '') {
            $uuid = $this->ReadAttributeString('symcon_uuid');
            if ($uuid === '') {
                $uuid = bin2hex(random_bytes(8));
                $this->WriteAttributeString('symcon_uuid', $uuid);
            }
            $this->WriteAttributeString('api_key_name', 'Symcon Access ' . $uuid);
        }

        $name = $this->ReadAttributeString('api_key_name');
        if ($name === '') {
            $this->SendDebug(__FUNCTION__, '❌ Fehler: api_key_name leer.', 0);
            return false;
        }

        // --- helper: HTTP request ---
        $httpRequest = function (string $method, string $url, array $headers = [], ?string $basicUser = null, ?string $basicPass = null, ?string $body = null): array {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers
            ];
            if ($basicUser !== null && $basicPass !== null) {
                $opts[CURLOPT_USERPWD] = $basicUser . ':' . $basicPass;
            }
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
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
        $storedApiKey = $this->ReadAttributeString('api_key');
        if (!$forceRenew && $storedApiKey !== '') {
            $this->SendDebug(__FUNCTION__, '🔐 Prüfe gespeicherten API-Key via /api/system ...', 0);
            $test = $httpRequest(
                'GET',
                "http://$host/api/system",
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $storedApiKey
                ]
            );

            $this->SendDebug(__FUNCTION__, '🔐 Bearer Test HTTP-Code: ' . $test['httpCode'], 0);
            if ($test['error'] !== '') {
                $this->SendDebug(__FUNCTION__, '❌ CURL Error (bearer test): ' . $test['error'], 0);
            }

            // 200 => key ok, keep it
            if ($test['httpCode'] >= 200 && $test['httpCode'] < 300) {
                $this->SendDebug(__FUNCTION__, '✅ Gespeicherter API-Key ist gültig.', 0);
                return true;
            }

            // 401/403 (or any non-2xx) => treat as invalid, renew
            $this->SendDebug(__FUNCTION__, '⚠️ Gespeicherter API-Key scheint ungültig oder nicht mehr berechtigt. Erneuerung wird gestartet.', 0);
            $forceRenew = true;
        }

        // --- Step 2: With Basic Auth, check whether a key with our name exists and is active ---
        $this->SendDebug(__FUNCTION__, '🔎 Prüfe vorhandene API-Keys via Basic Auth: ' . $name, 0);
        $list = $httpRequest(
            'GET',
            "http://$host/api/auth/api_keys?active=true",
            ['Content-Type: application/json'],
            $user,
            $pass
        );

        $this->SendDebug(__FUNCTION__, '📥 Existing Key Request HTTP-Code: ' . $list['httpCode'], 0);
        $this->SendDebug(__FUNCTION__, '📥 Existing Key Response: ' . $list['response'], 0);

        if ($list['error'] !== '') {
            $this->SendDebug(__FUNCTION__, '❌ CURL Error (list): ' . $list['error'], 0);
            return false;
        }
        if ($list['httpCode'] === 401 || $list['httpCode'] === 403) {
            $this->SendDebug(__FUNCTION__, '❌ Basic Auth fehlgeschlagen (user/pass ungültig).', 0);
            return false;
        }

        $existingKeys = json_decode($list['response'], true);
        if (!is_array($existingKeys)) {
            $this->SendDebug(__FUNCTION__, '❌ Ungültige Antwortstruktur beim Abrufen vorhandener Keys.', 0);
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
                $this->WriteAttributeString('api_key', '');
                $storedApiKey = '';
            }

            if ($foundKeyId !== null) {
                $this->SendDebug(__FUNCTION__, '🧹 Revoke existing key with same name. key_id=' . $foundKeyId, 0);
                $del = $httpRequest(
                    'DELETE',
                    "http://$host/api/auth/api_keys/" . urlencode((string)$foundKeyId),
                    ['Content-Type: application/json'],
                    $user,
                    $pass
                );
                $this->SendDebug(__FUNCTION__, '🧹 Revoke HTTP-Code: ' . $del['httpCode'], 0);
                $this->SendDebug(__FUNCTION__, '🧹 Revoke Response: ' . $del['response'], 0);
                if ($del['error'] !== '') {
                    $this->SendDebug(__FUNCTION__, '❌ CURL Error (revoke): ' . $del['error'], 0);
                    return false;
                }
                // if revoke fails, still try to create a new key with a new name as fallback
                if ($del['httpCode'] < 200 || $del['httpCode'] >= 300) {
                    $this->SendDebug(__FUNCTION__, '⚠️ Revoke nicht erfolgreich. Fallback: neuer Key-Name wird generiert.', 0);
                    $uuid = $this->ReadAttributeString('symcon_uuid');
                    if ($uuid === '') {
                        $uuid = bin2hex(random_bytes(8));
                        $this->WriteAttributeString('symcon_uuid', $uuid);
                    }
                    $newName = 'Symcon Access ' . $uuid . ' ' . date('YmdHis');
                    $this->WriteAttributeString('api_key_name', $newName);
                    $name = $newName;
                    $foundKeyId = null;
                }
            }
        } else {
            // Not forceRenew and no stored key means we cannot use existing token value.
            // If the key exists on remote but is not stored locally, we must revoke and re-create.
            if ($storedApiKey === '' && $foundKeyId !== null) {
                $this->SendDebug(__FUNCTION__, '⚠️ Key existiert auf der Remote, aber token fehlt lokal. Revoke + Neu erstellen.', 0);
                $forceRenew = true;
                $del = $httpRequest(
                    'DELETE',
                    "http://$host/api/auth/api_keys/" . urlencode((string)$foundKeyId),
                    ['Content-Type: application/json'],
                    $user,
                    $pass
                );
                $this->SendDebug(__FUNCTION__, '🧹 Revoke HTTP-Code: ' . $del['httpCode'], 0);
                $this->SendDebug(__FUNCTION__, '🧹 Revoke Response: ' . $del['response'], 0);
                if ($del['error'] !== '') {
                    $this->SendDebug(__FUNCTION__, '❌ CURL Error (revoke): ' . $del['error'], 0);
                    return false;
                }
                if ($del['httpCode'] < 200 || $del['httpCode'] >= 300) {
                    $this->SendDebug(__FUNCTION__, '⚠️ Revoke nicht erfolgreich. Fallback: neuer Key-Name wird generiert.', 0);
                    $uuid = $this->ReadAttributeString('symcon_uuid');
                    if ($uuid === '') {
                        $uuid = bin2hex(random_bytes(8));
                        $this->WriteAttributeString('symcon_uuid', $uuid);
                    }
                    $newName = 'Symcon Access ' . $uuid . ' ' . date('YmdHis');
                    $this->WriteAttributeString('api_key_name', $newName);
                    $name = $newName;
                }
            }
        }

        // --- Step 4: Create new key (Basic Auth) ---
        $this->SendDebug(__FUNCTION__, '🆕 Erstelle neuen API-Key: ' . $name, 0);
        $createBody = json_encode([
            'name' => $name,
            'scopes' => ['admin'],
            'description' => 'Created from Symcon module'
        ]);

        $create = $httpRequest(
            'POST',
            "http://$host/api/auth/api_keys",
            ['Content-Type: application/json'],
            $user,
            $pass,
            $createBody
        );

        $this->SendDebug(__FUNCTION__, '📥 Create HTTP-Code: ' . $create['httpCode'], 0);
        $this->SendDebug(__FUNCTION__, '📥 Create Response: ' . $create['response'], 0);

        if ($create['error'] !== '') {
            $this->SendDebug(__FUNCTION__, '❌ CURL Error (create): ' . $create['error'], 0);
            return false;
        }

        $data = json_decode($create['response'], true);
        if (is_array($data) && isset($data['api_key']) && $data['api_key'] !== '') {
            $newKey = (string)$data['api_key'];
            $this->WriteAttributeString('api_key', $newKey);
            $this->SendDebug(__FUNCTION__, '✅ API-Key gespeichert.', 0);

            // Auto-upload icon once after obtaining an API key
            if (!$this->ReadAttributeBoolean('icon_uploaded')) {
                $this->SendDebug(__FUNCTION__, '🖼️ Auto-uploading Symcon icon...', 0);
                $uploadResult = $this->UploadSymconIcon();
                $decodedUpload = json_decode($uploadResult, true);
                if (is_array($decodedUpload) && ($decodedUpload['success'] ?? false) === true) {
                    $this->WriteAttributeBoolean('icon_uploaded', true);
                    $this->SendDebug(__FUNCTION__, '✅ Symcon icon uploaded.', 0);
                } else {
                    $this->SendDebug(__FUNCTION__, '⚠️ Symcon icon upload failed: ' . $uploadResult, 0);
                }
            }

            return true;
        }

        $this->SendDebug(__FUNCTION__, '❌ Kein API-Key erhalten. Hinweis: Key muss ggf. auf der Remote bestätigt werden.', 0);
        return false;
    }

    public function GetApiKey(): string
    {
        $this->SendDebug(__FUNCTION__, 'started', 0);

        $host = $this->ReadPropertyString('host');
        $pass = $this->ReadPropertyString('web_config_pass');

        // Only attempt to create/validate an API key once the required fields are present.
        if ($host !== '' && $pass !== '') {
            $this->EnsureApiKey();
        } else {
            $this->SendDebug(__FUNCTION__, '⏸️ Skip EnsureApiKey (Host/Password missing).', 0);
        }

        $api_key = $this->ReadAttributeString('api_key');
        $this->SendDebug('API Key', $api_key, 0);
        return $api_key;
    }

    /**
     * Reset the stored API key and request a new one from the Remote.
     * This will revoke an existing API key with the same name (if possible) and create a new one.
     */
    public function ResetApiKey(): bool
    {
        $this->SendDebug(__FUNCTION__, '🔄 Reset API-Key gestartet', 0);
        // Clear local token first
        $this->WriteAttributeString('api_key', '');
        $this->WriteAttributeBoolean('icon_uploaded', false);

        // Force renew (revoke + create)
        $ok = $this->EnsureApiKey(true);

        // Rebuild parent configuration so WS uses the new token
        $this->ApplyChanges();

        $this->SendDebug(__FUNCTION__, $ok ? '✅ Reset erfolgreich' : '❌ Reset fehlgeschlagen', 0);
        return $ok;
    }


    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('name', '');
        $this->RegisterPropertyString('hostname', '');
        $this->RegisterPropertyString('host', '');
        $this->RegisterPropertyString('remote_id', '');
        $this->RegisterPropertyString('model', '');
        $this->RegisterPropertyString('version', '');
        $this->RegisterPropertyString('ver_api', '');
        $this->RegisterPropertyString('https_port', '');

        $this->RegisterAttributeString('api_key', '');
        $this->RegisterAttributeString('api_key_name', '');
        $this->RegisterAttributeString('auth_mode', '');
        $this->RegisterAttributeString('symcon_uuid', '');
        $this->RegisterAttributeBoolean('icon_uploaded', false);

        // Cached system information for UI display (so we can also work without Discovery)
        $this->RegisterAttributeString('sys_name', '');
        $this->RegisterAttributeString('sys_hostname', '');
        $this->RegisterAttributeString('sys_host', '');
        $this->RegisterAttributeString('sys_remote_id', '');
        $this->RegisterAttributeString('sys_model', '');
        $this->RegisterAttributeString('sys_version', '');
        $this->RegisterAttributeString('sys_ver_api', '');
        $this->RegisterAttributeString('sys_https_port', '');

        $this->RegisterPropertyString('web_config_user', 'web-configurator');
        $this->RegisterPropertyString('web_config_pass', '');

        // $this->ConnectParent('{D68FD31F-0E90-7019-F16C-1949BD3079EF}'); // Websocket Client

        //We need to call the RegisterHook function on Kernel READY
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        // Timers for automatic initial data refresh
        $this->RegisterTimer('RefreshStep', 0, 'UCR_RefreshStep($_IPS["TARGET"]);');
        $this->RegisterTimer('RefreshAllData', 0, 'UCR_RefreshAllData($_IPS["TARGET"]);');
        // Timer for automatic WebSocket reconnect handling
        $this->RegisterTimer('WsReconnectStep', 0, 'UCR_WsReconnectStep($_IPS["TARGET"]);');
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();

    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        // Explicit instance status handling
        // Default: show "creating" until minimum configuration is present
        $host = $this->ReadPropertyString('host');
        $pass = $this->ReadPropertyString('web_config_pass');

        if ($host === '' || $pass === '') {
            // Not configured yet
            $this->SetStatus(IS_CREATING);
        } else {
            // Config present; mark active for now (we may downgrade later if key creation fails)
            $this->SetStatus(IS_ACTIVE);
        }

        // Parent monitoring temporarily disabled for stabilization
        // $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        // if ($parentID > 0) {
        //     $this->RegisterMessage($parentID, IM_CHANGESTATUS);
        // }

        if ($this->GetBuffer('InitialRefreshEnqueued') === '') {
            $this->SetBuffer('InitialRefreshEnqueued', '0');
        }
        if ($this->GetBuffer('WsReconnectPhase') === '') {
            $this->SetBuffer('WsReconnectPhase', '0');
        }
        if ($this->GetBuffer('WsReconnectAttempts') === '') {
            $this->SetBuffer('WsReconnectAttempts', '0');
        }

        // --- Automatic initial setup when configuration is complete ---
        // $host and $pass already read above

        if ($host !== '' && $pass !== '') {
            $this->SendDebug(__FUNCTION__, '🚀 Auto setup triggered (host + password present)', 0);

            // 1) Ensure API key exists
            if ($this->EnsureApiKey()) {
                $this->SendDebug(__FUNCTION__, '✅ API key ensured', 0);

                // Parent WebSocket manipulation temporarily disabled for stabilization
                // No automatic ApplyChanges, activation, subscription or refresh here

                // 5) Try icon upload once
                if (!$this->ReadAttributeBoolean('icon_uploaded')) {
                    $this->SendDebug(__FUNCTION__, '🖼️ Attempting automatic icon upload', 0);
                    $this->UploadSymconIcon();
                }
            } else {
                $this->SendDebug(__FUNCTION__, '❌ API key could not be ensured during auto setup', 0);
                // Keep instance active (config is present). WS parent may still be connected; we will retry later.
                $this->SetStatus(IS_ACTIVE);
            }
        }

    }

    public function GetConfigurationForParent(): string
    {
        $host = $this->ReadPropertyString('host');
        $pass = $this->ReadPropertyString('web_config_pass');

        // If manual setup is not finished yet (no host/pass), do not configure the WS client.
        // Return a valid but non-working dummy configuration so the parent stays inactive.
        if ($host === '' || $pass === '') {
            $this->SendDebug(__FUNCTION__, '⏸️ WS-Konfiguration noch nicht möglich (Host/Passwort fehlt).', 0);

            $dummy = [
                'URL' => 'wss://127.0.0.1/ws',
                'VerifyCertificate' => false,
                'Type' => 0,
                'Headers' => json_encode([])
            ];

            return json_encode($dummy);
        }

        // Ensure we have a valid API key once host+pass are present.
        $apiKey = $this->ReadAttributeString('api_key');
        if ($apiKey === '') {
            $this->SendDebug(__FUNCTION__, '🔐 Kein API-Key vorhanden – versuche API-Key zu erzeugen/validieren...', 0);
            if (!$this->EnsureApiKey()) {
                $this->SendDebug(__FUNCTION__, '⏸️ WS-Konfiguration wird verschoben (API-Key nicht verfügbar).', 0);

                $dummy = [
                    'URL' => 'wss://127.0.0.1/ws',
                    'VerifyCertificate' => false,
                    'Type' => 0,
                    'Headers' => json_encode([])
                ];

                return json_encode($dummy);
            }
            $apiKey = $this->ReadAttributeString('api_key');
        }

        // Build the Headers as a JSON-encoded string for compatibility
        $headers = json_encode([
            [
                'Name' => 'API-Key',
                'Value' => $apiKey
            ]
        ]);

        $config = [
            'URL' => self::DEFAULT_WSS_PROTOCOL . $host . self::DEFAULT_WS_PATH,
            'VerifyCertificate' => false,
            'Type' => 0,
            'Headers' => $headers
        ];

        $this->SendDebug(__FUNCTION__, '🧩 WS Configuration: ' . json_encode($config), 0);
        return json_encode($config);
    }

    public function ForwardData(string $JSONString): string
    {
        $this->SendDebug(__FUNCTION__, '📥 Eingehende Daten: ' . $JSONString, 0);

        $data = json_decode($JSONString, true);

        // Prüfen, ob ein Buffer existiert
        if (!isset($data['Buffer'])) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler: Buffer fehlt!', 0);
            return json_encode(['error' => 'Buffer fehlt']);
        }

        $buffer = is_string($data['Buffer']) ? json_decode($data['Buffer'], true) : $data['Buffer'];

        // Prüfen, ob "method" vorhanden ist
        if (!isset($buffer['method'])) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler: Buffer enthält kein "method"-Feld!', 0);
            return json_encode(['error' => 'method fehlt im Buffer']);
        }

        $method = $buffer['method'];
        $this->SendDebug(__FUNCTION__, "➡️ Verarbeite Methode: $method", 0);

        switch ($method) {
            case 'CallGetVersion':
                return $this->CallGetVersion();
            case 'CallGetStatus':
                return $this->CallGetStatus();
            case 'CallGetHealthCheck':
                return $this->CallGetHealthCheck();
            case 'CallGetScopes':
                return $this->CallGetScopes();
            case 'CallGetExternalSystems':
                return $this->CallGetExternalSystems();
            case 'CallGetSystemInformation':
                return $this->CallGetSystemInformation();
            case 'CallGetBatteryState':
                return $this->CallGetBatteryState();
            case 'CallGetNetworkConfig':
                return $this->CallGetNetworkConfig();
            case 'CallGetDisplayConfig':
                return $this->CallGetDisplayConfig();
            case 'CallGetDocks':
                return $this->CallGetDocks();
            case 'CallGetActivities':
                return $this->CallGetActivities();
            case 'CallSendEntityCommand':
                return $this->CallSendEntityCommand($buffer['params']);
            case 'CallGetDockDiscovery':
                return $this->CallGetDockDiscovery();
            case 'CallGetSoundConfig':
                return $this->CallGetSoundConfig();
            case 'CallGetRemotes':
                return $this->CallGetRemotes();
            case 'CallGetEntities':
                return $this->CallGetEntities();
            case 'CallGetIntg':
                return $this->CallGetIntg();
            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannte Methode: $method", 0);
                return json_encode(['error' => 'Unbekannte Methode']);
        }
        // $this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => $data->Buffer]));
    }

    public function ReceiveData(string $JSONString): string
    {
        // WebSocket Client -> Splitter payload
        $this->SendDebug(__FUNCTION__, '📥 Envelope: ' . $JSONString, 0);

        $envelope = json_decode($JSONString, true);
        if (!is_array($envelope) || !array_key_exists('Buffer', $envelope)) {
            $this->SendDebug(__FUNCTION__, '❌ Invalid envelope (Buffer missing)', 0);
            return '';
        }

        $buf = $envelope['Buffer'];

        // Normalize payload: Buffer may be
        // - array (already decoded)
        // - JSON string (starts with '{' or '[')
        // - HEX encoded JSON (e.g. '7B226B69...' = '{"ki...')
        $data = null;

        if (is_array($buf)) {
            $data = $buf;
        } elseif (is_string($buf)) {
            $raw = trim($buf);

            // If it looks like HEX (only 0-9a-f, even length, starts with 7B/5B => '{'/'[')
            $isHex = ($raw !== '')
                && (strlen($raw) % 2 === 0)
                && (bool)preg_match('/^[0-9a-fA-F]+$/', $raw)
                && (str_starts_with($raw, '7B') || str_starts_with($raw, '7b') || str_starts_with($raw, '5B') || str_starts_with($raw, '5b'));

            if ($isHex) {
                $decodedRaw = hex2bin($raw);
                if ($decodedRaw === false) {
                    $this->SendDebug(__FUNCTION__, '❌ Buffer looks like HEX but hex2bin failed', 0);
                    return '';
                }
                $raw = $decodedRaw;
            }

            // Now attempt JSON decode
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                // Keep raw buffer for debugging; we currently expect JSON from WebSocket Client.
                $preview = is_string($raw) ? substr($raw, 0, 250) : '';
                $this->SendDebug(__FUNCTION__, '⚠️ Buffer is not JSON (preview): ' . $preview, 0);
                return '';
            }
        } else {
            $this->SendDebug(__FUNCTION__, '❌ Buffer has unsupported type', 0);
            return '';
        }

        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, '❌ Decoded payload is not an array', 0);
            return '';
        }

        // Log a compact, readable payload (avoid flooding)
        $payloadPreview = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($payloadPreview) && strlen($payloadPreview) > 1200) {
            $payloadPreview = substr($payloadPreview, 0, 1200) . '…';
        }
        $this->SendDebug(__FUNCTION__, '📥 Payload: ' . $payloadPreview, 0);

        if (!isset($data['msg'])) {
            $this->SendDebug(__FUNCTION__, '⚠️ No msg field in payload', 0);
            return '';
        }

        switch ($data['msg']) {
            case 'event':
                $this->SendPayloadToChildren($data);
                break;

            case 'auth_required':
            case 'auth_ok':
            case 'authentication':
            $this->SendDebug(__FUNCTION__, 'Authentication: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                break;

            case 'power_mode_change':
                $this->SendDebug(__FUNCTION__, 'Power Mode Change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'ping':
                $this->SendDebug(__FUNCTION__, '🔐 System message: ' . $data['msg'], 0);
                break;

            // --- WebSocket documented events ---
            case 'activity_change':
                $this->SendDebug(__FUNCTION__, '⚡ Activity change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'battery_state_change':
            case 'battery_status':
            $this->SendDebug(__FUNCTION__, '🔋 Battery change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'entity_change':
                $this->SendDebug(__FUNCTION__, 'Entity change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'connected_devices':
                $this->SendDebug(__FUNCTION__, '🔌 Connected devices: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                break;

            case 'display_state_change':
                $this->SendDebug(__FUNCTION__, '💡 Display state change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'remote_event':
                $this->SendDebug(__FUNCTION__, '🎮 Remote event: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'error':
                $this->SendDebug(__FUNCTION__, '❗ Error: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                break;

            // --- end WebSocket events ---
            default:
                $this->SendDebug(__FUNCTION__, '❔ Unknown msg: ' . (string)$data['msg'], 0);
                break;
        }

        return '';
    }

    protected function SendPayloadToChildren(array $data): void
    {
        // An Childs weiterleiten
        $payload = json_encode([
            'DataID' => '{76BD37C4-C1A4-AA3A-4AFF-599D64F5E989}',
            'Buffer' => $data
        ]);
        $this->SendDataToChildren($payload);
    }


    // --- Event Subscription Management ---
    // (nothing to do for subscribe/unsubscribe responses here yet)
    /**
     * Subscribes or unsubscribes to specific WebSocket event channels.
     *
     * @param array $channels Array of channel names to (un)subscribe.
     * @param bool $subscribe True to subscribe, false to unsubscribe.
     */
    protected function ManageEventSubscription(array $channels, bool $subscribe = true)
    {
        $this->SendDebug(__FUNCTION__, ($subscribe ? '🔔 Subscribe' : '🔕 Unsubscribe') . ' to channels: ' . json_encode($channels), 0);

        $payload = [
            'kind' => 'req',
            'id' => time(), // timestamp as unique ID
            'msg' => $subscribe ? 'subscribe_events' : 'unsubscribe_events',
            'msg_data' => [
                'channels' => $channels
            ]
        ];

        $this->SendDataToWebsocketClient($payload);
    }

    protected function SendDataToWebsocketClient(array $payload): void
    {
        // Log the payload itself (not the full message)
        $this->SendDebug(__FUNCTION__, '➡️ Sending payload to I/O: ' . json_encode($payload), 0);
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID > 0) {
            $message = json_encode($payload);
            $this->SendDebug(__FUNCTION__, '📤 WSC_SendMessage an ' . $parentID . ': ' . $message, 0);
            WSC_SendMessage($parentID, $message);
        }
    }

    /**
     * Request available event channels from the device.
     */
    public function GetAvailableEventChannels(): void
    {
        $payload = [
            'kind' => 'req',
            'id' => time(),
            'msg' => 'get_event_channels'
        ];
        $this->SendDebug(__FUNCTION__, '📡 Requesting available channels: ' . json_encode($payload), 0);
        $this->SendDataToWebsocketClient($payload);
    }

    /**
     * Query active event subscriptions.
     */
    public function GetActiveEventSubscriptions(): void
    {
        $payload = [
            'kind' => 'req',
            'id' => time(),
            'msg' => 'get_event_subscriptions'
        ];
        $this->SendDebug(__FUNCTION__, '📡 Requesting active subscriptions: ' . json_encode($payload), 0);
        $this->SendDataToWebsocketClient($payload);
    }

    /**
     * Subscribe to all event channels.
     */
    public function SubscribeToAllEvents(): void
    {
        $this->ManageEventSubscription(['all'], true);
    }

    /**
     * Unsubscribe from all event channels.
     */
    public function UnsubscribeFromAllEvents(): void
    {
        $this->ManageEventSubscription(['all'], false);
    }

    /**
     * Trigger a full refresh (manual entry point). This will enqueue a standard set of REST calls
     * and execute them step-by-step using a short timer.
     */
    public function RefreshAllData(): void
    {
        $this->SendDebug(__FUNCTION__, '🔄 Manual refresh requested', 0);
        $this->StartInitialRefresh(true);
    }

    /**
     * Timer-driven refresh step. Executes exactly one queued request per timer tick.
     */
    public function RefreshStep(): void
    {
        $queueJson = $this->GetBuffer('RefreshQueue');
        $queue = [];
        if (is_string($queueJson) && $queueJson !== '') {
            $decoded = json_decode($queueJson, true);
            if (is_array($decoded)) {
                $queue = $decoded;
            }
        }

        if (count($queue) === 0) {
            $this->SetTimerInterval('RefreshStep', 0);
            $this->SendDebug(__FUNCTION__, '✅ Refresh queue finished', 0);
            return;
        }

        $item = array_shift($queue);
        $this->SetBuffer('RefreshQueue', json_encode($queue));

        $method = is_array($item) ? ($item['method'] ?? '') : '';
        $params = is_array($item) ? ($item['params'] ?? null) : null;

        if (!is_string($method) || $method === '') {
            $this->SendDebug(__FUNCTION__, '⚠️ Invalid queue item (missing method)', 0);
            return;
        }

        // Safety allow-list
        $allowed = [
            'CallGetVersion',
            'CallGetStatus',
            'CallGetHealthCheck',
            'CallGetScopes',
            'CallGetExternalSystems',
            'CallGetSystemInformation',
            'CallGetBatteryState',
            'CallGetNetworkConfig',
            'CallGetDisplayConfig',
            'CallGetDocks',
            'CallGetActivities',
            'CallGetDockDiscovery',
            'CallGetSoundConfig',
            'CallGetRemotes',
            'CallGetEntities',
            'CallGetIntg'
        ];

        if (!in_array($method, $allowed, true) || !method_exists($this, $method)) {
            $this->SendDebug(__FUNCTION__, '⚠️ Method not allowed or not found: ' . $method, 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '➡️ Refresh step: ' . $method, 0);

        try {
            // Some calls require params
            if ($params !== null) {
                $result = $this->{$method}($params);
            } else {
                $result = $this->{$method}();
            }
            $this->SendDebug(__FUNCTION__, '⬅️ Result: ' . (is_string($result) ? $result : json_encode($result)), 0);
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, '❌ Exception: ' . $e->getMessage(), 0);
        }

        // Continue quickly until queue is empty
        if (count($queue) > 0) {
            $this->SetTimerInterval('RefreshStep', 750);
        } else {
            $this->SetTimerInterval('RefreshStep', 0);
        }
    }

    /**
     * Enqueue the standard initial refresh calls and start the step timer.
     *
     * @param bool $force If true, always enqueue (even if already enqueued recently)
     */
    protected function StartInitialRefresh(bool $force = false): void
    {
        // Only refresh when we have the minimum configuration
        $host = $this->ReadPropertyString('host');
        $pass = $this->ReadPropertyString('web_config_pass');

        if ($host === '' || $pass === '') {
            $this->SendDebug(__FUNCTION__, '⏸️ Skip refresh (Host/Password missing).', 0);
            return;
        }

        // Ensure a valid API key exists (this will also auto-upload the icon once)
        if (!$this->EnsureApiKey()) {
            $this->SendDebug(__FUNCTION__, '⏸️ Skip refresh (API key not available).', 0);
            return;
        }

        // Avoid repeatedly enqueuing the same initial refresh while connected
        $already = $this->GetBuffer('InitialRefreshEnqueued');
        if (!$force && $already === '1') {
            $this->SendDebug(__FUNCTION__, '⏸️ Initial refresh already enqueued.', 0);
            return;
        }

        $queue = [
            ['method' => 'CallGetVersion'],
            ['method' => 'CallGetStatus'],
            ['method' => 'CallGetHealthCheck'],
            ['method' => 'CallGetSystemInformation'],
            ['method' => 'CallGetBatteryState'],
            ['method' => 'CallGetActivities'],
            ['method' => 'CallGetScopes'],
            ['method' => 'CallGetExternalSystems'],
            ['method' => 'CallGetDocks'],
            ['method' => 'CallGetRemotes'],
            // Entities can be large; keep it last
            ['method' => 'CallGetEntities']
        ];

        $this->SetBuffer('RefreshQueue', json_encode($queue));
        $this->SetBuffer('InitialRefreshEnqueued', '1');

        $this->SendDebug(__FUNCTION__, '🧾 Enqueued refresh calls: ' . count($queue), 0);

        // Start the step timer shortly
        $this->SetTimerInterval('RefreshStep', 500);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        // Parent status handling temporarily disabled
        if ($Message === IM_CHANGESTATUS) {
            return;
        }

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug(__FUNCTION__, "Kernel Ready", 0);
        }
    }

    /**
     * Uploads the Symcon integration icon to the Remote 3 so it can be shown for the integration.
     * Requires host + API key to be available.
     */
    public function UploadSymconIcon(): string
    {
        $this->SendDebug(__FUNCTION__, 'started', 0);

        $host = $this->ReadPropertyString('host');
        if ($host === '') {
            $msg = 'Host is missing.';
            $this->SendDebug(__FUNCTION__, '❌ ' . $msg, 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        // Ensure we have an API key first
        if (!$this->EnsureApiKey()) {
            $msg = 'API key missing or could not be created.';
            $this->SendDebug(__FUNCTION__, '❌ ' . $msg, 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $apiKey = $this->ReadAttributeString('api_key');
        if ($apiKey === '') {
            $msg = 'API key is empty.';
            $this->SendDebug(__FUNCTION__, '❌ ' . $msg, 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $filePath = __DIR__ . '/../imgs/symcon_icon.png';
        $this->SendDebug(__FUNCTION__, 'Icon path: ' . $filePath, 0);
        if (!file_exists($filePath)) {
            $msg = 'Icon file not found: ' . $filePath;
            $this->SendDebug(__FUNCTION__, '❌ ' . $msg, 0);
            return json_encode(['success' => false, 'message' => $msg]);
        }

        $url = 'http://' . $host . '/api/resources/Icon';
        $this->SendDebug(__FUNCTION__, 'POST ' . $url, 0);

        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        $postFields = [
            'file' => new CURLFile($filePath)
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $postFields
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->SendDebug(__FUNCTION__, 'HTTP ' . $httpCode, 0);
        if ($err !== '') {
            $this->SendDebug(__FUNCTION__, '❌ CURL Error: ' . $err, 0);
            return json_encode(['success' => false, 'message' => $err]);
        }

        $this->SendDebug(__FUNCTION__, 'Response: ' . (string)$resp, 0);

        if ($httpCode < 200 || $httpCode >= 300) {
            return json_encode(['success' => false, 'message' => 'Upload failed', 'httpCode' => $httpCode, 'response' => (string)$resp]);
        }

        // Return parsed JSON if possible
        $decoded = json_decode((string)$resp, true);
        if (is_array($decoded)) {
            return json_encode(['success' => true, 'httpCode' => $httpCode, 'data' => $decoded]);
        }

        return json_encode(['success' => true, 'httpCode' => $httpCode, 'response' => (string)$resp]);
    }

    // === REST API Command Methods ===

    private function SendRestRequest(string $method, string $endpoint, array $params = []): array
    {
        if (!$this->EnsureApiKey()) {
            $this->SendDebug(__FUNCTION__, '❌ Kein API-Key verfügbar.', 0);
            return ['error' => 'API key missing or could not be created'];
        }

        $url = 'http://' . $this->ReadPropertyString('host') . '/api' . $endpoint;
        $this->SendDebug(__FUNCTION__, "🔗 URL: $url", 0);

        $ch = curl_init();

        $headers = [
            'Content-Type: application/json'
        ];

        $apiKey = $this->ReadAttributeString('api_key');
        if ($apiKey != '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 10
        ];

        if (in_array(strtoupper($method), ['POST', 'PUT']) && !empty($params)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($params);
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $this->SendDebug(__FUNCTION__, "📥 HTTP-Code: $httpCode", 0);
        $this->SendDebug(__FUNCTION__, "📥 Response: $result", 0);

        if ($error !== '') {
            $this->SendDebug(__FUNCTION__, "❌ CURL Error: $error", 0);
            return ['error' => $error];
        }

        // Guard clause: $result must be a valid string
        if ($result === false) {
            $this->SendDebug(__FUNCTION__, "❌ Leere oder fehlerhafte Antwort von curl_exec()", 0);
            return ['error' => 'Invalid CURL response'];
        }

        return json_decode($result, true);
    }

    protected function CeckResponse($response)
    {
        if (!is_array($response)) {
            $this->SendDebug(__FUNCTION__, '❌ Ungültige Antwortstruktur (kein Array).', 0);
            return json_encode(['success' => false, 'message' => 'Invalid response']);
        }

        $this->SendDebug(__FUNCTION__, '✅ Antwort erhalten: ' . json_encode($response), 0);
        return json_encode(['success' => true, 'data' => $response]);
    }

    protected function CallGetVersion()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /pub/version...', 0);
        $response = $this->SendRestRequest('GET', '/pub/version');
        return $this->CeckResponse($response);
    }

    protected function CallGetStatus()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /pub/status...', 0);
        $response = $this->SendRestRequest('GET', '/pub/status');
        return $this->CeckResponse($response);
    }

    protected function CallGetHealthCheck()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /pub/health_check...', 0);
        $response = $this->SendRestRequest('GET', '/pub/health_check');
        return $this->CeckResponse($response);
    }

    protected function CallGetScopes()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /auth/scopes...', 0);
        $response = $this->SendRestRequest('GET', '/auth/scopes');
        return $this->CeckResponse($response);
    }

    protected function CallGetExternalSystems()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /auth/external...', 0);
        $response = $this->SendRestRequest('GET', '/auth/external');
        return $this->CeckResponse($response);
    }

    protected function CallGetSystemInformation()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /system...', 0);
        $response = $this->SendRestRequest('GET', '/system');
        return $this->CeckResponse($response);
    }

    protected function CallGetBatteryState()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /system/power/battery...', 0);
        $response = $this->SendRestRequest('GET', '/system/power/battery');
        return $this->CeckResponse($response);
    }

    protected function CallGetNetworkConfig()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /cfg/network...', 0);
        $response = $this->SendRestRequest('GET', '/cfg/network');
        return $this->CeckResponse($response);
    }

    protected function CallGetDisplayConfig()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /cfg/display...', 0);
        $response = $this->SendRestRequest('GET', '/cfg/display');
        return $this->CeckResponse($response);
    }

    protected function CallGetDocks()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /docks...', 0);
        $response = $this->SendRestRequest('GET', '/docks');
        return $this->CeckResponse($response);
    }

    /**
     * Universal REST call helper for development and scripting.
     *
     * This allows calling arbitrary Core REST endpoints from scripts without exposing all protected methods.
     *
     * Examples:
     *  echo UCR_CallApi($id, 'GET', '/activities?page=1&limit=50');
     *  echo UCR_CallApi($id, 'PUT', '/entities/my_entity/command', '{"entity_id":"my_entity","cmd_id":"power_toggle"}');
     */
    public function CallApi(string $method, string $endpoint, $params = null): string
    {
        $this->SendDebug(__FUNCTION__, 'method=' . $method . ' endpoint=' . $endpoint, 0);

        // Allow params to be passed either as array or as JSON string
        $payload = [];
        if (is_string($params) && trim($params) !== '') {
            $decoded = json_decode($params, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                // Keep raw string in debug, but do not fail hard
                $this->SendDebug(__FUNCTION__, '⚠️ params JSON could not be decoded, ignoring params', 0);
            }
        } elseif (is_array($params)) {
            $payload = $params;
        }

        $response = $this->SendRestRequest($method, $endpoint, $payload);
        return $this->CeckResponse($response);
    }

    // === REST API Command Methods ===

    protected function CallGetActivities()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /activities (paginated)...', 0);

        $limit = 50;
        $page = 1;
        $all = [];
        $seen = [];

        // Hard safety cap to avoid infinite loops if the API behaves unexpectedly
        $maxPages = 100;

        while ($page <= $maxPages) {
            $endpoint = '/activities?page=' . $page . '&limit=' . $limit;
            $this->SendDebug(__FUNCTION__, '➡️ Page ' . $page . ' GET ' . $endpoint, 0);

            $response = $this->SendRestRequest('GET', $endpoint);

            if (!is_array($response)) {
                $this->SendDebug(__FUNCTION__, '❌ Invalid response (not an array) on page ' . $page, 0);
                // Return error structure through CeckResponse
                return $this->CeckResponse($response);
            }

            // The API may return either:
            // - { results: [...] }
            // - { items: [...] }
            // - { data: [...] }
            // - [...] (direct array)
            $items = [];
            if (isset($response['results']) && is_array($response['results'])) {
                $items = $response['results'];
            } elseif (isset($response['items']) && is_array($response['items'])) {
                $items = $response['items'];
            } elseif (isset($response['data']) && is_array($response['data'])) {
                $items = $response['data'];
            } elseif (array_is_list($response)) {
                $items = $response;
            }

            $count = is_array($items) ? count($items) : 0;
            $this->SendDebug(__FUNCTION__, '⬅️ Page ' . $page . ' items=' . $count, 0);

            if ($count === 0) {
                break;
            }

            // De-duplicate by activity id if present, otherwise by JSON hash
            foreach ($items as $a) {
                if (is_array($a) && isset($a['activity_id'])) {
                    $key = 'id:' . (string)$a['activity_id'];
                } elseif (is_array($a) && isset($a['id'])) {
                    $key = 'id:' . (string)$a['id'];
                } else {
                    $key = 'hash:' . md5(json_encode($a));
                }

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $all[] = $a;
                }
            }

            // If fewer than limit came back, assume this is the last page
            if ($count < $limit) {
                break;
            }

            $page++;
        }

        $this->SendDebug(__FUNCTION__, '✅ Total activities collected: ' . count($all), 0);

        // Return a single combined structure, keeping backward compatibility by using `results`
        return $this->CeckResponse(['results' => $all]);
    }

    protected function CallSendEntityCommand($params)
    {
        if (!isset($params['entity_id']) || !isset($params['cmd_id'])) {
            $this->SendDebug(__FUNCTION__, '❌ Fehlende Parameter (entity_id oder cmd_id)', 0);
            return json_encode(['success' => false, 'message' => 'entity_id und cmd_id erforderlich']);
        }

        $entityId = $params['entity_id'];
        $cmdId = $params['cmd_id'];

        $this->SendDebug(__FUNCTION__, "➡️ Sende Command an Entity: $entityId mit Befehl: $cmdId", 0);

        $endpoint = '/entities/' . urlencode($entityId) . '/command';
        $payload = [
            'entity_id' => $entityId,
            'cmd_id' => $cmdId
        ];

        $response = $this->SendRestRequest('PUT', $endpoint, $payload);
        return $this->CeckResponse($response);
    }

    protected function CallGetDockDiscovery()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /docks/discover...', 0);
        $response = $this->SendRestRequest('GET', '/docks/discover');
        return $this->CeckResponse($response);
    }

    protected function CallGetSoundConfig()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /cfg/sound...', 0);
        $response = $this->SendRestRequest('GET', '/cfg/sound');
        return $this->CeckResponse($response);
    }

    protected function CallGetRemotes()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /remotes...', 0);
        $response = $this->SendRestRequest('GET', '/remotes');
        return $this->CeckResponse($response);
    }

    protected function CallGetEntities()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /entities...', 0);
        $response = $this->SendRestRequest('GET', '/entities');
        return $this->CeckResponse($response);
    }

    protected function CallGetIntg()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /intg...', 0);
        $response = $this->SendRestRequest('GET', '/entities');
        return $this->CeckResponse($response);
    }

    /**
     * Start (or continue) an automatic reconnect cycle for the WebSocket Client parent.
     * This is a best-effort self-heal when the I/O gets stuck after Remote standby.
     */
    private function StartWsReconnect(string $reason): void
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID <= 0) {
            $this->SendDebug(__FUNCTION__, '⛔ No parent I/O instance available for reconnect.', 0);
            return;
        }

        // Avoid starting multiple cycles in parallel
        $phase = (int)($this->GetBuffer('WsReconnectPhase') !== '' ? $this->GetBuffer('WsReconnectPhase') : '0');
        if ($phase !== 0) {
            $this->SendDebug(__FUNCTION__, '⏳ Reconnect already running (phase=' . $phase . ')', 0);
            return;
        }

        $attempts = (int)($this->GetBuffer('WsReconnectAttempts') !== '' ? $this->GetBuffer('WsReconnectAttempts') : '0');
        $attempts++;
        $this->SetBuffer('WsReconnectAttempts', (string)$attempts);
        $this->SetBuffer('WsReconnectPhase', '1');

        $this->SendDebug(__FUNCTION__, '🧯 Starting WS reconnect cycle (attempt ' . $attempts . '): ' . $reason, 0);

        // Phase 1 will disable the parent, Phase 2 will re-enable it.
        $this->SetTimerInterval('WsReconnectStep', 250);
    }

    /**
     * Timer handler for reconnect cycle.
     * Phase 1: set parent inactive
     * Phase 2: apply changes + set parent active
     * Phase 3: verify, otherwise retry with backoff
     */
    public function WsReconnectStep(): void
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID <= 0) {
            $this->SetTimerInterval('WsReconnectStep', 0);
            $this->SetBuffer('WsReconnectPhase', '0');
            return;
        }

        $phase = (int)($this->GetBuffer('WsReconnectPhase') !== '' ? $this->GetBuffer('WsReconnectPhase') : '0');
        $attempts = (int)($this->GetBuffer('WsReconnectAttempts') !== '' ? $this->GetBuffer('WsReconnectAttempts') : '0');

        if ($phase === 1) {
            $this->SendDebug(__FUNCTION__, '🔌 Phase 1: disabling WebSocket Client ' . $parentID, 0);
            @IPS_SetInstanceStatus($parentID, IS_INACTIVE);
            $this->SetBuffer('WsReconnectPhase', '2');
            $this->SetTimerInterval('WsReconnectStep', 600);
            return;
        }

        if ($phase === 2) {
            $this->SendDebug(__FUNCTION__, '🔧 Phase 2: applying + enabling WebSocket Client ' . $parentID, 0);
            @IPS_ApplyChanges($parentID);
            @IPS_SetInstanceStatus($parentID, IS_ACTIVE);
            $this->SetBuffer('WsReconnectPhase', '3');
            $this->SetTimerInterval('WsReconnectStep', 1500);
            return;
        }

        if ($phase === 3) {
            $status = IPS_GetInstance($parentID)['InstanceStatus'];
            $this->SendDebug(__FUNCTION__, '🔍 Phase 3: parent status=' . $status, 0);

            if ($status === IS_ACTIVE) {
                $this->SendDebug(__FUNCTION__, '✅ Reconnect successful.', 0);
                $this->SetTimerInterval('WsReconnectStep', 0);
                $this->SetBuffer('WsReconnectPhase', '0');
                $this->SetBuffer('WsReconnectAttempts', '0');

                // After a successful reconnect, subscribe + refresh
                $this->SubscribeToAllEvents();
                $this->StartInitialRefresh(true);
                return;
            }

            // Retry with backoff (max 3 attempts)
            if ($attempts >= 3) {
                $this->SendDebug(__FUNCTION__, '❌ Reconnect failed after ' . $attempts . ' attempts. Giving up.', 0);
                $this->SetTimerInterval('WsReconnectStep', 0);
                $this->SetBuffer('WsReconnectPhase', '0');
                return;
            }

            $this->SendDebug(__FUNCTION__, '⏳ Reconnect not yet active, will retry. attempt=' . $attempts, 0);
            $this->SetBuffer('WsReconnectPhase', '1');
            // backoff 5 seconds
            $this->SetTimerInterval('WsReconnectStep', 5000);
            return;
        }

        // Not running
        $this->SetTimerInterval('WsReconnectStep', 0);
        $this->SetBuffer('WsReconnectPhase', '0');
    }


    /**
     * Loads the unfoldedcircle logo as a base64 data URI for embedding in the form.
     *
     * @return string
     */
    private function LoadImageAsBase64(): string
    {
        $path = __DIR__ . '/../libs/unfoldedcircle_logo.png';
        $this->SendDebug(__FUNCTION__, $path, 0);
        if (!file_exists($path)) {
            $this->SendDebug(__FUNCTION__, 'File not found: ' . $path, 0);
            return '';
        }
        $imageData = file_get_contents($path);
        $base64 = base64_encode($imageData);
        return 'data:image/png;base64,' . $base64;
    }

    /**
     * Fetches a minimal set of system info from the Remote 3 and stores it in attributes
     * so the configuration form can display it even when the instance was created manually.
     */
    public function SyncSystemInfo(): void
    {
        $this->SendDebug(__FUNCTION__, 'started', 0);

        $host = $this->ReadPropertyString('host');
        $pass = $this->ReadPropertyString('web_config_pass');
        if ($host === '' || $pass === '') {
            $this->SendDebug(__FUNCTION__, '❌ Host and Web Configurator password are required.', 0);
            return;
        }

        if (!$this->EnsureApiKey()) {
            $this->SendDebug(__FUNCTION__, '❌ API key missing or could not be created.', 0);
            return;
        }

        // /system
        $sys = $this->SendRestRequest('GET', '/system');
        $this->SendDebug(__FUNCTION__, 'System: ' . json_encode($sys), 0);

        $sysName = is_array($sys) ? (string)($sys['name'] ?? $sys['device_name'] ?? $sys['deviceName'] ?? '') : '';
        $sysHostname = is_array($sys) ? (string)($sys['hostname'] ?? $sys['host_name'] ?? '') : '';
        $sysRemoteId = is_array($sys) ? (string)($sys['remote_id'] ?? $sys['remoteId'] ?? $sys['id'] ?? '') : '';
        $sysModel = is_array($sys) ? (string)($sys['model'] ?? $sys['device_model'] ?? '') : '';
        $sysHttps = is_array($sys) ? (string)($sys['https_port'] ?? $sys['httpsPort'] ?? '') : '';

        // Always store current host
        $sysHost = $host;

        // /pub/version
        $ver = $this->SendRestRequest('GET', '/pub/version');
        $this->SendDebug(__FUNCTION__, 'Version: ' . json_encode($ver), 0);

        $sysVersion = is_array($ver) ? (string)($ver['core'] ?? $ver['version'] ?? $ver['firmware'] ?? '') : '';
        $sysVerApi = is_array($ver) ? (string)($ver['api'] ?? $ver['api_version'] ?? $ver['apiVersion'] ?? '') : '';

        // Fallback: some firmware returns most fields via /pub/version, while /system may be empty/limited.
        if ($sysName === '' && is_array($ver)) {
            $sysName = (string)($ver['device_name'] ?? $ver['deviceName'] ?? $ver['name'] ?? '');
        }
        if ($sysHostname === '' && is_array($ver)) {
            $sysHostname = (string)($ver['hostname'] ?? $ver['host_name'] ?? '');
        }
        // Remote ID is not always returned directly; derive it from hostname by removing trailing ".local".
        if ($sysRemoteId === '' && $sysHostname !== '') {
            $derived = $sysHostname;
            if (str_ends_with($derived, '.local')) {
                $derived = substr($derived, 0, -strlen('.local'));
            }
            $derived = trim($derived);
            if ($derived !== '') {
                $sysRemoteId = $derived;
            }
        }
        if ($sysModel === '' && is_array($ver)) {
            $sysModel = (string)($ver['model'] ?? $ver['device_model'] ?? '');
        }
        // Optional: if API/core are missing, use /pub/version as well
        if ($sysVerApi === '' && is_array($ver)) {
            $sysVerApi = (string)($ver['api'] ?? $ver['api_version'] ?? $ver['apiVersion'] ?? '');
        }
        if ($sysVersion === '' && is_array($ver)) {
            $sysVersion = (string)($ver['core'] ?? $ver['version'] ?? $ver['firmware'] ?? '');
        }

        // /pub/status (optional)
        $status = $this->SendRestRequest('GET', '/pub/status');
        $this->SendDebug(__FUNCTION__, 'Status: ' . json_encode($status), 0);

        // Cache in attributes (used by UI)
        $this->WriteAttributeString('sys_name', $sysName);
        $this->WriteAttributeString('sys_hostname', $sysHostname);
        $this->WriteAttributeString('sys_host', $sysHost);
        $this->WriteAttributeString('sys_remote_id', $sysRemoteId);
        $this->WriteAttributeString('sys_model', $sysModel);
        $this->WriteAttributeString('sys_version', $sysVersion);
        $this->WriteAttributeString('sys_ver_api', $sysVerApi);
        $this->WriteAttributeString('sys_https_port', $sysHttps);

        // Also persist into properties so users see it consistently and it survives cache logic
        // (These fields are still non-editable for Discovery-created instances.)
        IPS_SetProperty($this->InstanceID, 'name', $sysName);
        IPS_SetProperty($this->InstanceID, 'hostname', $sysHostname);
        IPS_SetProperty($this->InstanceID, 'host', $sysHost);
        IPS_SetProperty($this->InstanceID, 'remote_id', $sysRemoteId);
        IPS_SetProperty($this->InstanceID, 'model', $sysModel);
        IPS_SetProperty($this->InstanceID, 'version', $sysVersion);
        IPS_SetProperty($this->InstanceID, 'ver_api', $sysVerApi);
        IPS_SetProperty($this->InstanceID, 'https_port', $sysHttps);
        IPS_ApplyChanges($this->InstanceID);

        // Update the currently open form immediately (no popup/echo)
        $this->UpdateFormField('name', 'value', $sysName);
        $this->UpdateFormField('hostname', 'value', $sysHostname);
        $this->UpdateFormField('host', 'value', $sysHost);
        $this->UpdateFormField('remote_id', 'value', $sysRemoteId);
        $this->UpdateFormField('model', 'value', $sysModel);
        $this->UpdateFormField('version', 'value', $sysVersion);
        $this->UpdateFormField('ver_api', 'value', $sysVerApi);
        $this->UpdateFormField('https_port', 'value', $sysHttps);

        $this->SendDebug(__FUNCTION__, 'done', 0);
    }

    /**
     * build configuration form
     *
     * @return string
     */
    public function GetConfigurationForm(): string
    {
        // return current form
        return json_encode(
            [
                'elements' => $this->FormHead(),
                'actions' => $this->FormActions(),
                'status' => $this->FormStatus()]
        );
    }

    /**
     * return form configurations on configuration step
     *
     * @return array
     */
    protected function FormHead()
    {
        $api_key = $this->GetApiKey();
        $api_key_name = $this->ReadAttributeString('api_key_name');
        $auth_mode = $this->ReadAttributeString('auth_mode');
        $symcon_uuid = $this->ReadAttributeString('symcon_uuid');

        // Cached system fields (for UI)
        $sys_name = $this->ReadAttributeString('sys_name');
        $sys_hostname = $this->ReadAttributeString('sys_hostname');
        $sys_host = $this->ReadAttributeString('sys_host');
        $sys_remote_id = $this->ReadAttributeString('sys_remote_id');
        $sys_model = $this->ReadAttributeString('sys_model');
        $sys_version = $this->ReadAttributeString('sys_version');
        $sys_ver_api = $this->ReadAttributeString('sys_ver_api');
        $sys_https_port = $this->ReadAttributeString('sys_https_port');

        // Fallback: if cache is empty, show properties (Discovery may have written them)
        if ($sys_name === '') {
            $sys_name = $this->ReadPropertyString('name');
        }
        if ($sys_hostname === '') {
            $sys_hostname = $this->ReadPropertyString('hostname');
        }
        if ($sys_host === '') {
            $sys_host = $this->ReadPropertyString('host');
        }
        if ($sys_remote_id === '') {
            $sys_remote_id = $this->ReadPropertyString('remote_id');
        }
        if ($sys_model === '') {
            $sys_model = $this->ReadPropertyString('model');
        }
        if ($sys_version === '') {
            $sys_version = $this->ReadPropertyString('version');
        }
        if ($sys_ver_api === '') {
            $sys_ver_api = $this->ReadPropertyString('ver_api');
        }
        if ($sys_https_port === '') {
            $sys_https_port = $this->ReadPropertyString('https_port');
        }

        // Manual configuration: allow manual entry if host is empty
        $hostProp = $this->ReadPropertyString('host');
        $manualSetup = ($hostProp === '');

        $form = [
            [
                'type' => 'Image',
                'image' => $this->LoadImageAsBase64()
            ],
            // Insert hint label before the first ValidationTextBox (web_config_pass)
            [
                'type' => 'Label',
                'caption' => $manualSetup
                    ? 'Note: This instance was created manually. Please enter at least Host/IP and the Web Configurator password.'
                    : 'Note: This instance was created via Discovery. Host/IP and system information are managed automatically.'
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'web_config_pass',
                'caption' => 'Web Configurator Password'
            ],
            [
                'type' => 'Label',
                'caption' => 'System Information'
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'name',
                'caption' => 'Name',
                'enabled' => false,
                'value' => $sys_name
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'hostname',
                'caption' => 'Hostname',
                'enabled' => $manualSetup,
                'value' => $sys_hostname
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'host',
                'caption' => 'Host',
                'enabled' => $manualSetup,
                'value' => $sys_host
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'remote_id',
                'caption' => 'Remote ID',
                'enabled' => false,
                'value' => $sys_remote_id
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'model',
                'caption' => 'Model',
                'enabled' => false,
                'value' => $sys_model
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'version',
                'caption' => 'Firmware Version',
                'enabled' => false,
                'value' => $sys_version
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'ver_api',
                'caption' => 'API Version',
                'enabled' => false,
                'value' => $sys_ver_api
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'https_port',
                'caption' => 'HTTPS Port',
                'enabled' => $manualSetup,
                'value' => $sys_https_port
            ],
            [
                'type' => 'Label',
                'caption' => 'Authentication'
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'web_config_user',
                'caption' => 'Web Configurator User',
                // The username is fixed on the Remote 3 (default: web-configurator).
                // We keep it read-only to avoid wrong user input. Only the password is configurable.
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'api_key',
                'caption' => 'API Key',
                'enabled' => false,
                'value' => $api_key
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'api_key_name',
                'caption' => 'API Key Name',
                'enabled' => false,
                'value' => $api_key_name
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'auth_mode',
                'caption' => 'Auth Mode',
                'enabled' => false,
                'value' => $auth_mode,
                'visible' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'symcon_uuid',
                'caption' => 'Symcon UUID',
                'enabled' => false,
                'value' => $symcon_uuid
            ]
        ];
        return $form;
    }

    /**
     * return form actions by token
     *
     * @return array
     */
    protected function FormActions()
    {
        $form = [
            [
                'type' => 'Button',
                'caption' => 'Reset API Key',
                'icon' => 'refresh',
                'onClick' => 'echo UCR_ResetApiKey($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Upload Symcon Icon',
                'icon' => 'image',
                'onClick' => 'echo UCR_UploadSymconIcon($id);',
                'visible' => ($this->ReadAttributeString('api_key') !== '')
            ],
            [
                'type' => 'Button',
                'caption' => 'Sync system info',
                'icon' => 'refresh',
                'onClick' => 'UCR_SyncSystemInfo($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Refresh all data',
                'icon' => 'refresh',
                'onClick' => 'UCR_RefreshAllData($id);'
            ]
        ];
        return $form;
    }

    /**
     * return from status
     *
     * @return array
     */
    protected function FormStatus()
    {
        $form = [
            [
                'code' => IS_CREATING,
                'icon' => 'inactive',
                'caption' => 'Creating instance.'
            ],
            [
                'code' => IS_ACTIVE,
                'icon' => 'active',
                'caption' => 'Remote 3 Core Manager created.'
            ],
            [
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => 'interface closed.'
            ]
        ];
        return $form;
    }
}

