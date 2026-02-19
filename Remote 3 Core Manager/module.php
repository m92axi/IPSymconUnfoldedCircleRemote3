<?php

declare(strict_types=1);

class Remote3CoreManager extends IPSModule
{
    const DEFAULT_WS_PROTOCOL = 'ws://';

    const DEFAULT_WSS_PROTOCOL = 'wss://';
    const DEFAULT_WS_PORT = 8001;

    const DEFAULT_WSS_PORT = 8443;
    const DEFAULT_WS_PATH = '/ws';

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
            $this->WriteAttributeString('api_key', (string)$data['api_key']);
            $this->SendDebug(__FUNCTION__, '✅ API-Key gespeichert.', 0);
            return true;
        }

        $this->SendDebug(__FUNCTION__, '❌ Kein API-Key erhalten. Hinweis: Key muss ggf. auf der Remote bestätigt werden.', 0);
        return false;
    }

    public function GetApiKey()
    {
        $this->SendDebug(__FUNCTION__, 'started', 0);
        $this->EnsureApiKey();
        $api_key = $this->ReadAttributeString('api_key');
        $this->SendDebug("API Key", $api_key, 0);
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

        // Force renew (revoke + create)
        $ok = $this->EnsureApiKey(true);

        // Rebuild parent configuration so WS uses the new token
        $this->ApplyChanges();

        $this->SendDebug(__FUNCTION__, $ok ? '✅ Reset erfolgreich' : '❌ Reset fehlgeschlagen', 0);
        return $ok;
    }


    public function Create()
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

        $this->RegisterPropertyString('web_config_user', 'web-configurator');
        $this->RegisterPropertyString('web_config_pass', '');

        // $this->ConnectParent('{D68FD31F-0E90-7019-F16C-1949BD3079EF}'); // Websocket Client

        //We need to call the RegisterHook function on Kernel READY
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();

    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        // Register for status changes of the I/O (WebSocket) instance
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID > 0) {
            $this->RegisterMessage($parentID, IM_CHANGESTATUS);
        }

    }

    public function GetConfigurationForParent()
    {
        $host = $this->ReadPropertyString('host');
        $apiKey = $this->ReadAttributeString('api_key');


        // Build the Headers as a JSON-encoded string for compatibility
        $headers = json_encode([[
            'Name' => 'API-Key',
            'Value' => $apiKey
        ]]);

        $Config = [
            "URL" => self::DEFAULT_WSS_PROTOCOL . $host . self::DEFAULT_WS_PATH,
            "VerifyCertificate" => false,
            "Type" => 0,
            "Headers" => $headers
        ];

        $this->SendDebug(__FUNCTION__, '🧩 WS Konfiguration: ' . json_encode($Config), 0);
        return json_encode($Config);
    }

    public function ForwardData($JSONString)
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

    public function ReceiveData($JSONString)
    {
        $this->SendDebug(__FUNCTION__, '📥 Empfangene Daten: ' . $JSONString, 0);

        $data = json_decode($JSONString, true);
        // Decode nested Buffer if present and is string
        if (isset($data['Buffer']) && is_string($data['Buffer'])) {
            $data = json_decode($data['Buffer'], true);
        }

        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, '❌ Ungültiges JSON', 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, '📥 Payload: ' . json_encode($data), 0);

        if (!isset($data['msg'])) {
            $this->SendDebug(__FUNCTION__, '⚠️ Keine msg im Paket', 0);
            return;
        }

        switch ($data['msg']) {
            case 'event':
                $this->SendPayloadToChildren($data);
                break;

            case 'auth_required':
            case 'auth_ok':
            case 'authentication':
                $this->SendDebug(__FUNCTION__, 'Authentication: ' . json_encode($data['msg_data']), 0);
                break;
            case 'power_mode_change':
                $this->SendDebug(__FUNCTION__, 'Power Mode Change: ' . json_encode($data['msg_data']), 0);
                $this->SendPayloadToChildren($data);
                break;
            case 'ping':
                // evtl. direkt behandeln
                $this->SendDebug(__FUNCTION__, '🔐 Systemnachricht: ' . $data['msg'], 0);
                break;

            // --- WebSocket documented events ---
            case 'activity_change':
                $this->SendDebug(__FUNCTION__, '⚡ Aktivitätswechsel: ' . json_encode($data['msg_data']), 0);
                $this->SendPayloadToChildren($data);
                break;
            case 'battery_state_change':
                $this->SendDebug(__FUNCTION__, '🔋 Batterieänderung: ' . json_encode($data['msg_data']), 0);
                $this->SendPayloadToChildren($data);
                break;
            case 'battery_status':
                $this->SendDebug(__FUNCTION__, '🔋 Batterieänderung: ' . json_encode($data['msg_data']), 0);
                $this->SendPayloadToChildren($data);
                break;
            // 17.05.2025, 22:37:28 |          ReceiveData | 📥 Payload: {"kind":"event","msg":"battery_status","cat":"REMOTE","ts":"2025-05-17T20:37:28.960944722Z","msg_data":{"capacity":71,"power_supply":false,"status":"DISCHARGING"}}
            case 'entity_change':
                $this->SendDebug(__FUNCTION__, 'Entity Änderung: ' . json_encode($data['msg_data']), 0);
                // $this->SendPayloadToChildren($data);
                // 17.05.2025, 22:37:28 |          ReceiveData | 📥 Payload: {"kind":"event","msg":"entity_change","cat":"ENTITY","ts":"2025-05-17T20:37:28.379512399Z","msg_data":{"entity_id":"uc_hue_driver.main.10","entity_type":"light","event_type":"CHANGE","new_state":{"attributes":{"state":"UNAVAILABLE"}}}}
                break;
            case 'connected_devices':
                $this->SendDebug(__FUNCTION__, '🔌 Verbundene Geräte: ' . json_encode($data['msg_data']), 0);
                break;
            case 'display_state_change':
                $this->SendDebug(__FUNCTION__, '💡 Displaystatusänderung: ' . json_encode($data['msg_data']), 0);
                $this->SendPayloadToChildren($data);
                break;
            case 'remote_event':
                $this->SendDebug(__FUNCTION__, '🎮 Benutzeraktion: ' . json_encode($data['msg_data']), 0);
                $this->SendPayloadToChildren($data);
                break;
            case 'error':
                $this->SendDebug(__FUNCTION__, '❗ Fehlerbenachrichtigung: ' . json_encode($data['msg_data']), 0);
                break;
            // --- end WebSocket events ---

            default:
                $this->SendDebug(__FUNCTION__, '❔ Unbekannte Nachricht: ' . $data['msg'], 0);
        }
    }

    protected function SendPayloadToChildren($data)
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

    protected function SendDataToWebsocketClient($payload)
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
    public function GetAvailableEventChannels()
    {
        $payload = [
            'kind' => 'req',
            'id' => time(),
            'msg' => 'get_event_channels'
        ];
        $this->SendDebug(__FUNCTION__, '📡 Requesting available channels: ' . $payload, 0);
        $this->SendDataToWebsocketClient($payload);
    }

    /**
     * Query active event subscriptions.
     */
    public function GetActiveEventSubscriptions()
    {
        $payload = [
            'kind' => 'req',
            'id' => time(),
            'msg' => 'get_event_subscriptions'
        ];
        $this->SendDebug(__FUNCTION__, '📡 Requesting active subscriptions: ' . $payload, 0);
        $this->SendDataToWebsocketClient($payload);
    }

    /**
     * Subscribe to all event channels.
     */
    public function SubscribeToAllEvents()
    {
        $this->ManageEventSubscription(['all'], true);
    }

    /**
     * Unsubscribe from all event channels.
     */
    public function UnsubscribeFromAllEvents()
    {
        $this->ManageEventSubscription(['all'], false);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message === IM_CHANGESTATUS) {
            $this->SendDebug(__FUNCTION__, "📡 WebSocket Statusänderung von ID $SenderID: Status $Data[0]", 0);
            // Nur bei aktivem Zustand neu abonnieren
            if ($Data[0] === IS_ACTIVE) {
                $this->SendDebug(__FUNCTION__, '🔄 WebSocket verbunden, automatische Event-Registrierung...', 0);
                $this->SubscribeToAllEvents();
            }
        }

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug(__FUNCTION__, "Kernel Ready", 0);
        }
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

    protected function CallGetActivities()
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /activities...', 0);
        $response = $this->SendRestRequest('GET', '/activities');
        return $this->CeckResponse($response);
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
     * build configuration form
     *
     * @return string
     */
    public function GetConfigurationForm()
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

        $form = [
            [
                'type' => 'Image',
                'image' => $this->LoadImageAsBase64()
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'web_config_pass',
                'caption' => 'Web-Konfigurator Passwort'
            ],
            [
                'type' => 'Label',
                'caption' => 'Systeminformationen'
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'name',
                'caption' => 'Name',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'hostname',
                'caption' => 'Hostname',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'host',
                'caption' => 'Host',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'remote_id',
                'caption' => 'Remote ID',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'model',
                'caption' => 'Modell',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'version',
                'caption' => 'Version',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'ver_api',
                'caption' => 'API-Version',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'https_port',
                'caption' => 'HTTPS-Port',
                'enabled' => false
            ],
            [
                'type' => 'Label',
                'caption' => 'Authentifizierungsinformationen'
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'web_config_user',
                'caption' => 'Web-Konfigurator Benutzer',
                'enabled' => false
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'api_key',
                'caption' => 'API-Key',
                'enabled' => false,
                'value' => $api_key
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'api_key_name',
                'caption' => 'API-Key Name',
                'enabled' => false,
                'value' => $api_key_name
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'auth_mode',
                'caption' => 'Auth-Modus',
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
                'caption' => 'API-Key zurücksetzen',
                'icon' => 'refresh',
                'onClick' => 'UCR_ResetApiKey($id);'
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
                'caption' => 'Creating instance.'],
            [
                'code' => IS_ACTIVE,
                'icon' => 'active',
                'caption' => 'Remote 3 Core Manager created.'],
            [
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => 'interface closed.']];

        return $form;
    }
}