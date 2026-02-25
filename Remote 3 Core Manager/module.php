<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DebugTrait.php';
require_once __DIR__ . '/../libs/UcrApiHelper.php';

class Remote3CoreManager extends IPSModuleStrict
{
    use DebugTrait;
    const DEFAULT_WS_PROTOCOL = 'ws://';

    const DEFAULT_WSS_PROTOCOL = 'wss://';
    const DEFAULT_WS_PORT = 8001;

    const DEFAULT_WSS_PORT = 8443;
    const DEFAULT_WS_PATH = '/ws';

    private ?UcrApiHelper $apiHelper = null;

    protected function Api(): UcrApiHelper
    {
        if ($this->apiHelper === null) {
            $this->apiHelper = new UcrApiHelper($this);
        }
        return $this->apiHelper;
    }

    public function GetApiKey(): string
    {
        return $this->Api()->GetApiKey();
    }

    public function ResetApiKey(): bool
    {
        return $this->Api()->ResetApiKey();
    }

    public function UploadSymconIcon(): string
    {
        return $this->Api()->UploadSymconIcon();
    }

    /**
     * Ensures a valid API key exists (creates/validates it if needed).
     *
     * This is implemented in the shared UcrApiHelper and exposed here as a local wrapper
     * so existing module code can keep calling $this->EnsureApiKey().
     */
    protected function EnsureApiKey(): bool
    {
        return $this->Api()->EnsureApiKey();
    }

    /**
     * Expose the full helper result for setup flows / diagnostics.
     */
    protected function EnsureRemoteApiAccess(): array
    {
        return $this->Api()->EnsureRemoteApiAccess();
    }

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

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('name', '');
        $this->RegisterPropertyString('hostname', '');
        $this->RegisterPropertyString('host', '');
        $this->RegisterAttributeString('remote_host', '');
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
        $this->RegisterAttributeString('web_config_pass', '');

        // --- Expert Debug / Debug Filtering ---
        $this->RegisterPropertyBoolean('expert_debug', false);
        $this->RegisterPropertyInteger('debug_level', 4); // 0=BASIC,1=ERROR,2=WARN,3=INFO,4=TRACE
        $this->RegisterPropertyBoolean('debug_filter_enabled', false);
        $this->RegisterPropertyString('debug_topics', ''); // comma-separated topics; empty = all
        $this->RegisterPropertyString('debug_entity_ids', ''); // comma-separated entity ids
        $this->RegisterPropertyString('debug_var_ids', ''); // comma-separated var/object ids
        $this->RegisterPropertyString('debug_client_ips', ''); // comma-separated IPs
        $this->RegisterPropertyString('debug_text_filter', ''); // substring or regex
        $this->RegisterPropertyBoolean('debug_text_is_regex', false);
        $this->RegisterPropertyBoolean('debug_strict_match', true); // require match when any filter is set
        $this->RegisterPropertyInteger('debug_throttle_ms', 0); // 0 disables throttling
        $this->RegisterPropertyString('debug_topics_cfg', '');
        $this->RegisterPropertyString('debug_filter_instances', '');
        $this->RegisterPropertyString('debug_client_ips_cfg', '');

        $this->RegisterAttributeString('client_sessions', '');
        $this->RegisterAttributeString('connected_clients', '');

        // Properties for expert settings
        $this->RegisterPropertyBoolean('extended_debug', false);

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
            // In Symcon only Active/Inactive/StandBy/Error codes are allowed to be set by modules.
            // Use INACTIVE to indicate that the instance needs configuration.
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->WriteAttributeString('remote_host', $host);
            $this->WriteAttributeString('web_config_pass', $pass);
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
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_SETUP, '🚀 Auto setup triggered (host + password present)', 0);

            // 1) Ensure API key exists
            if ($this->EnsureApiKey()) {
                $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_SETUP, '✅ API key ensured', 0);

                // Parent WebSocket manipulation temporarily disabled for stabilization
                // No automatic ApplyChanges, activation, subscription or refresh here

                // 5) Try icon upload once
                if (!$this->ReadAttributeBoolean('icon_uploaded')) {
                    $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_SETUP, '🖼️ Attempting automatic icon upload', 0);
                    $this->UploadSymconIcon();
                }
            } else {
                $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_SETUP, '❌ API key could not be ensured during auto setup', 0);
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
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '⏸️ WS-Konfiguration noch nicht möglich (Host/Passwort fehlt).', 0);

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
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '🔐 Kein API-Key vorhanden – versuche API-Key zu erzeugen/validieren...', 0);
            if (!$this->EnsureApiKey()) {
                $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_WS, '⏸️ WS-Konfiguration wird verschoben (API-Key nicht verfügbar).', 0);

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

        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '🧩 WS Configuration: ' . json_encode($config), 0);
        return json_encode($config);
    }

    public function ForwardData(string $JSONString): string
    {
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '📥 Eingehende Daten: ' . $JSONString, 0);

        $data = json_decode($JSONString, true);

        // Prüfen, ob ein Buffer existiert
        if (!isset($data['Buffer'])) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❌ Fehler: Buffer fehlt!', 0);
            return json_encode(['error' => 'Buffer fehlt']);
        }

        $buffer = is_string($data['Buffer']) ? json_decode($data['Buffer'], true) : $data['Buffer'];

        // Prüfen, ob "method" vorhanden ist
        if (!isset($buffer['method'])) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❌ Fehler: Buffer enthält kein "method"-Feld!', 0);
            return json_encode(['error' => 'method fehlt im Buffer']);
        }

        $method = $buffer['method'];
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, "➡️ Verarbeite Methode: $method", 0);

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
                $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_WS, "⚠️ Unbekannte Methode: $method", 0);
                return json_encode(['error' => 'Unbekannte Methode']);
        }
        // $this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => $data->Buffer]));
    }

    public function ReceiveData(string $JSONString): string
    {
        // WebSocket Client -> Splitter payload
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '📥 Envelope: ' . $JSONString, 0);

        $envelope = json_decode($JSONString, true);
        if (!is_array($envelope) || !array_key_exists('Buffer', $envelope)) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❌ Invalid envelope (Buffer missing)', 0);
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
                    $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❌ Buffer looks like HEX but hex2bin failed', 0);
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
                $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_WS, '⚠️ Buffer is not JSON (preview): ' . $preview, 0);
                return '';
            }
        } else {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❌ Buffer has unsupported type', 0);
            return '';
        }

        if (!is_array($data)) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❌ Decoded payload is not an array', 0);
            return '';
        }

        // Log a compact, readable payload (avoid flooding)
        $payloadPreview = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($payloadPreview) && strlen($payloadPreview) > 1200) {
            $payloadPreview = substr($payloadPreview, 0, 1200) . '…';
        }
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '📥 Payload: ' . $payloadPreview, 0);

        if (!isset($data['msg'])) {
            $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_WS, '⚠️ No msg field in payload', 0);
            return '';
        }

        switch ($data['msg']) {
            case 'event':
                $this->SendPayloadToChildren($data);
                break;

            case 'auth_required':
            case 'auth_ok':
            case 'authentication':
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_AUTH, 'Authentication: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                break;

            case 'power_mode_change':
                $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, 'Power Mode Change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'ping':
                $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '🔐 System message: ' . $data['msg'], 0);
                break;

            // --- WebSocket documented events ---
            case 'activity_change':
                $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '⚡ Activity change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'battery_state_change':
            case 'battery_status':
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '🔋 Battery change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'entity_change':
                $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_ENTITY, 'Entity change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'connected_devices':
                $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '🔌 Connected devices: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                break;

            case 'display_state_change':
                $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '💡 Display state change: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'remote_event':
                $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '🎮 Remote event: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                $this->SendPayloadToChildren($data);
                break;

            case 'error':
                $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❗ Error: ' . json_encode($data['msg_data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
                break;

            // --- end WebSocket events ---
            default:
                $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_WS, '❔ Unknown msg: ' . (string)$data['msg'], 0);
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
    protected function ManageEventSubscription(array $channels, bool $subscribe = true): void
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, ($subscribe ? '🔔 Subscribe' : '🔕 Unsubscribe') . ' to channels: ' . json_encode($channels), 0);

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
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '➡️ Sending payload to I/O: ' . json_encode($payload), 0);
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID > 0) {
            $message = json_encode($payload);
            $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '📤 WSC_SendMessage an ' . $parentID . ': ' . $message, 0);
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
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '📡 Requesting available channels: ' . json_encode($payload), 0);
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
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '📡 Requesting active subscriptions: ' . json_encode($payload), 0);
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
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '🔄 Manual refresh requested', 0);
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
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '✅ Refresh queue finished', 0);
            return;
        }

        $item = array_shift($queue);
        $this->SetBuffer('RefreshQueue', json_encode($queue));

        $method = is_array($item) ? ($item['method'] ?? '') : '';
        $params = is_array($item) ? ($item['params'] ?? null) : null;

        if (!is_string($method) || $method === '') {
            $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_API, '⚠️ Invalid queue item (missing method)', 0);
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
            $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_API, '⚠️ Method not allowed or not found: ' . $method, 0);
            return;
        }

        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, '➡️ Refresh step: ' . $method, 0);

        try {
            // Some calls require params
            if ($params !== null) {
                $result = $this->{$method}($params);
            } else {
                $result = $this->{$method}();
            }
            $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, '⬅️ Result: ' . (is_string($result) ? $result : json_encode($result)), 0);
        } catch (Throwable $e) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, '❌ Exception: ' . $e->getMessage(), 0);
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
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏸️ Skip refresh (Host/Password missing).', 0);
            return;
        }

        // Ensure a valid API key exists (this will also auto-upload the icon once)
        if (!$this->EnsureApiKey()) {
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏸️ Skip refresh (API key not available).', 0);
            return;
        }

        // Avoid repeatedly enqueuing the same initial refresh while connected
        $already = $this->GetBuffer('InitialRefreshEnqueued');
        if (!$force && $already === '1') {
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏸️ Initial refresh already enqueued.', 0);
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

        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '🧾 Enqueued refresh calls: ' . count($queue), 0);

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
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_GEN, 'Kernel Ready', 0);
        }
    }

    // === REST API Command Methods ===

    private function SendRestRequest(string $method, string $endpoint, array $params = []): array
    {
        if (!$this->EnsureApiKey()) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, '❌ Kein API-Key verfügbar.', 0);
            return ['error' => 'API key missing or could not be created'];
        }

        $url = 'http://' . $this->ReadPropertyString('host') . '/api' . $endpoint;
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, "🔗 URL: $url", 0);

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

        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, "📥 HTTP-Code: $httpCode", 0);
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, "📥 Response: $result", 0);

        if ($error !== '') {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, "❌ CURL Error: $error", 0);
            return ['error' => $error];
        }

        // Guard clause: $result must be a valid string
        if ($result === false) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, "❌ Leere oder fehlerhafte Antwort von curl_exec()", 0);
            return ['error' => 'Invalid CURL response'];
        }

        return json_decode($result, true);
    }

    protected function CeckResponse($response)
    {
        if (!is_array($response)) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, '❌ Ungültige Antwortstruktur (kein Array).', 0);
            return json_encode(['success' => false, 'message' => 'Invalid response']);
        }

        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, '✅ Antwort erhalten: ' . json_encode($response), 0);
        return json_encode(['success' => true, 'data' => $response]);
    }

    protected function CallGetVersion()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /pub/version...', 0);
        $response = $this->SendRestRequest('GET', '/pub/version');
        return $this->CeckResponse($response);
    }

    protected function CallGetStatus()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /pub/status...', 0);
        $response = $this->SendRestRequest('GET', '/pub/status');
        return $this->CeckResponse($response);
    }

    protected function CallGetHealthCheck()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /pub/health_check...', 0);
        $response = $this->SendRestRequest('GET', '/pub/health_check');
        return $this->CeckResponse($response);
    }

    protected function CallGetScopes()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /auth/scopes...', 0);
        $response = $this->SendRestRequest('GET', '/auth/scopes');
        return $this->CeckResponse($response);
    }

    protected function CallGetExternalSystems()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /auth/external...', 0);
        $response = $this->SendRestRequest('GET', '/auth/external');
        return $this->CeckResponse($response);
    }

    protected function CallGetSystemInformation()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /system...', 0);
        $response = $this->SendRestRequest('GET', '/system');
        return $this->CeckResponse($response);
    }

    protected function CallGetBatteryState()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /system/power/battery...', 0);
        $response = $this->SendRestRequest('GET', '/system/power/battery');
        return $this->CeckResponse($response);
    }

    protected function CallGetNetworkConfig()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /cfg/network...', 0);
        $response = $this->SendRestRequest('GET', '/cfg/network');
        return $this->CeckResponse($response);
    }

    protected function CallGetDisplayConfig()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /cfg/display...', 0);
        $response = $this->SendRestRequest('GET', '/cfg/display');
        return $this->CeckResponse($response);
    }

    protected function CallGetDocks()
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /docks...', 0);
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
    public function CallApi(string $method, string $endpoint, string $params = ''): string
    {
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, 'method=' . $method . ' endpoint=' . $endpoint, 0);

        // In IPSModuleStrict, only simple types are allowed in public methods.
        // Therefore params must be passed as JSON string.
        $payload = [];

        if (trim($params) !== '') {
            $decoded = json_decode($params, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_API, '⚠️ params JSON could not be decoded, ignoring params', 0);
            }
        }

        $response = $this->SendRestRequest($method, $endpoint, $payload);
        return $this->CeckResponse($response);
    }

    // === REST API Command Methods ===

    protected function CallGetActivities(): false|string
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /activities (paginated)...', 0);

        $limit = 50;
        $page = 1;
        $all = [];
        $seen = [];

        // Hard safety cap to avoid infinite loops if the API behaves unexpectedly
        $maxPages = 100;

        while ($page <= $maxPages) {
            $endpoint = '/activities?page=' . $page . '&limit=' . $limit;
            $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, '➡️ Page ' . $page . ' GET ' . $endpoint, 0);

            $response = $this->SendRestRequest('GET', $endpoint);

            if (!is_array($response)) {
                $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, '❌ Invalid response (not an array) on page ' . $page, 0);
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
            $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, '⬅️ Page ' . $page . ' items=' . $count, 0);

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

        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '✅ Total activities collected: ' . count($all), 0);

        // Return a single combined structure, keeping backward compatibility by using `results`
        return $this->CeckResponse(['results' => $all]);
    }

    protected function CallSendEntityCommand($params)
    {
        if (!isset($params['entity_id']) || !isset($params['cmd_id'])) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_ENTITY, '❌ Fehlende Parameter (entity_id oder cmd_id)', 0);
            return json_encode(['success' => false, 'message' => 'entity_id und cmd_id erforderlich']);
        }

        $entityId = $params['entity_id'];
        $cmdId = $params['cmd_id'];

        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_ENTITY, "➡️ Sende Command an Entity: $entityId mit Befehl: $cmdId", 0);

        $endpoint = '/entities/' . urlencode($entityId) . '/command';
        $payload = [
            'entity_id' => $entityId,
            'cmd_id' => $cmdId
        ];

        $response = $this->SendRestRequest('PUT', $endpoint, $payload);
        return $this->CeckResponse($response);
    }

    protected function CallGetDockDiscovery(): false|string
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /docks/discover...', 0);
        $response = $this->SendRestRequest('GET', '/docks/discover');
        return $this->CeckResponse($response);
    }

    protected function CallGetSoundConfig(): false|string
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /cfg/sound...', 0);
        $response = $this->SendRestRequest('GET', '/cfg/sound');
        return $this->CeckResponse($response);
    }

    protected function CallGetRemotes(): false|string
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /remotes...', 0);
        $response = $this->SendRestRequest('GET', '/remotes');
        return $this->CeckResponse($response);
    }

    protected function CallGetEntities(): false|string
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /entities...', 0);
        $response = $this->SendRestRequest('GET', '/entities');
        return $this->CeckResponse($response);
    }

    protected function CallGetIntg(): false|string
    {
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, '⏳ Requesting /intg...', 0);
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
            $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_WS, '⛔ No parent I/O instance available for reconnect.', 0);
            return;
        }

        // Avoid starting multiple cycles in parallel
        $phase = (int)($this->GetBuffer('WsReconnectPhase') !== '' ? $this->GetBuffer('WsReconnectPhase') : '0');
        if ($phase !== 0) {
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '⏳ Reconnect already running (phase=' . $phase . ')', 0);
            return;
        }

        $attempts = (int)($this->GetBuffer('WsReconnectAttempts') !== '' ? $this->GetBuffer('WsReconnectAttempts') : '0');
        $attempts++;
        $this->SetBuffer('WsReconnectAttempts', (string)$attempts);
        $this->SetBuffer('WsReconnectPhase', '1');

        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '🧯 Starting WS reconnect cycle (attempt ' . $attempts . '): ' . $reason, 0);

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
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '🔌 Phase 1: disabling WebSocket Client ' . $parentID, 0);
            @IPS_SetInstanceStatus($parentID, IS_INACTIVE);
            $this->SetBuffer('WsReconnectPhase', '2');
            $this->SetTimerInterval('WsReconnectStep', 600);
            return;
        }

        if ($phase === 2) {
            $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '🔧 Phase 2: applying + enabling WebSocket Client ' . $parentID, 0);
            @IPS_ApplyChanges($parentID);
            @IPS_SetInstanceStatus($parentID, IS_ACTIVE);
            $this->SetBuffer('WsReconnectPhase', '3');
            $this->SetTimerInterval('WsReconnectStep', 1500);
            return;
        }

        if ($phase === 3) {
            $status = IPS_GetInstance($parentID)['InstanceStatus'];
            $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_WS, '🔍 Phase 3: parent status=' . $status, 0);

            if ($status === IS_ACTIVE) {
                $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_WS, '✅ Reconnect successful.', 0);
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
                $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_WS, '❌ Reconnect failed after ' . $attempts . ' attempts. Giving up.', 0);
                $this->SetTimerInterval('WsReconnectStep', 0);
                $this->SetBuffer('WsReconnectPhase', '0');
                return;
            }

            $this->Debug(__FUNCTION__, self::LV_WARN, self::TOPIC_WS, '⏳ Reconnect not yet active, will retry. attempt=' . $attempts, 0);
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
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_FORM, $path, 0);
        if (!file_exists($path)) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_FORM, 'File not found: ' . $path, 0);
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
        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, 'started', 0);

        $host = $this->ReadPropertyString('host');
        $pass = $this->ReadPropertyString('web_config_pass');
        if ($host === '' || $pass === '') {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, '❌ Host and Web Configurator password are required.', 0);
            return;
        }

        if (!$this->EnsureApiKey()) {
            $this->Debug(__FUNCTION__, self::LV_ERROR, self::TOPIC_API, '❌ API key missing or could not be created.', 0);
            return;
        }

        // /system
        $sys = $this->SendRestRequest('GET', '/system');
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, 'System: ' . json_encode($sys), 0);

        $sysName = is_array($sys) ? (string)($sys['name'] ?? $sys['device_name'] ?? $sys['deviceName'] ?? '') : '';
        $sysHostname = is_array($sys) ? (string)($sys['hostname'] ?? $sys['host_name'] ?? '') : '';
        $sysRemoteId = is_array($sys) ? (string)($sys['remote_id'] ?? $sys['remoteId'] ?? $sys['id'] ?? '') : '';
        $sysModel = is_array($sys) ? (string)($sys['model'] ?? $sys['device_model'] ?? '') : '';
        $sysHttps = is_array($sys) ? (string)($sys['https_port'] ?? $sys['httpsPort'] ?? '') : '';

        // Always store current host
        $sysHost = $host;

        // /pub/version
        $ver = $this->SendRestRequest('GET', '/pub/version');
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, 'Version: ' . json_encode($ver), 0);

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
        $this->Debug(__FUNCTION__, self::LV_TRACE, self::TOPIC_API, 'Status: ' . json_encode($status), 0);

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

        $this->Debug(__FUNCTION__, self::LV_INFO, self::TOPIC_API, 'done', 0);
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
    protected function FormHead(): array
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
            ],
            [
                'type' => 'CheckBox',
                'name' => 'expert_debug',
                'caption' => '🧪 Expert Debug'
            ]
        ];

        // Show debug settings only when enabled
        if ($this->ReadPropertyBoolean('expert_debug')) {
            $form[] = [
                'type' => 'ExpansionPanel',
                'caption' => '🪲 Debugging',
                'items' => [
                    [
                        'type' => 'Label',
                        'caption' => 'Use filters to reduce debug output to specific entities/IDs/IPs. Example topics: WS, HOOK, ENTITY, VM, AUTH.'
                    ],
                    [
                        'type' => 'Select',
                        'name' => 'debug_level',
                        'caption' => 'Minimum debug level',
                        'options' => [
                            ['caption' => 'BASIC', 'value' => self::LV_BASIC],
                            ['caption' => 'ERROR', 'value' => self::LV_ERROR],
                            ['caption' => 'WARN', 'value' => self::LV_WARN],
                            ['caption' => 'INFO', 'value' => self::LV_INFO],
                            ['caption' => 'TRACE', 'value' => self::LV_TRACE],
                        ]
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'debug_filter_enabled',
                        'caption' => 'Enable filters'
                    ],
                    // Available topics: GEN, AUTH, HOOK, WS, ENTITY, VM, DISCOVERY, API, FORM, EXT
                    [
                        'type' => 'List',
                        'name' => 'debug_topics_cfg',
                        'caption' => 'Topics',
                        'rowCount' => 10,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            [
                                'caption' => 'Show',
                                'name' => 'enabled',
                                'width' => '80px',
                                'add' => true,
                                'edit' => ['type' => 'CheckBox']
                            ],
                            [
                                'caption' => 'Topic',
                                'name' => 'topic',
                                'width' => '120px',
                                'add' => '',
                                'edit' => ['type' => 'Label']
                            ],
                            [
                                'caption' => 'Description',
                                'name' => 'description',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => ['type' => 'Label']
                            ]
                        ],
                        'values' => $this->BuildDebugTopicsConfig()
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Filter by device/object (Symcon): select an instance to reduce debug output for its mapped variables.'
                    ],
                    [
                        'type' => 'List',
                        'name' => 'debug_filter_instances',
                        'caption' => 'Devices / Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Instance',
                                'name' => 'instance_id',
                                'width' => 'auto',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ]
                        ]
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'debug_var_ids',
                        'caption' => 'Var/Object IDs (CSV)'
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Client IP filter: select one or more Remote client IPs to reduce debug output.'
                    ],
                    [
                        'type' => 'List',
                        'name' => 'debug_client_ips_cfg',
                        'caption' => 'Client IPs',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Client IP',
                                'name' => 'ip',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => $this->GetKnownClientIPOptions()
                                ]
                            ]
                        ],
                        'values' => $this->BuildDebugClientIPsConfig()
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'debug_text_filter',
                        'caption' => 'Text filter (substring or regex)'
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'debug_text_is_regex',
                        'caption' => 'Text filter is regex'
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'debug_strict_match',
                        'caption' => 'Log matches only (strict)'
                    ],
                    [
                        'type' => 'NumberSpinner',
                        'name' => 'debug_throttle_ms',
                        'caption' => 'Throttle (ms, 0=off)',
                        'minimum' => 0,
                        'maximum' => 60000
                    ]
                ]
            ];
        }


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
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => 'Not configured yet.'
            ],
            [
                'code' => IS_ACTIVE,
                'icon' => 'active',
                'caption' => 'Remote 3 Core Manager created.'
            ],
            // Optional explicit error state for UI
            [
                'code' => IS_EBASE,
                'icon' => 'error',
                'caption' => 'Error.'
            ]
        ];
        return $form;
    }
}

