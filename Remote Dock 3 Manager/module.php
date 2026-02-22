<?php

declare(strict_types=1);

class Remote3DockManager extends IPSModuleStrict
{
    // Dock WebSocket API (UCD2/UCD3) defaults
    const DEFAULT_WS_PROTOCOL = 'ws://';
    const DEFAULT_WS_PORT = 80; // Dock 3
    const DOCK2_WS_PORT = 946; // 946
    const MODEL_DOCK3 = 'UCD3';
    const MODEL_DOCK2 = 'UCD2';

    // WebSocket path differs by dock model:
    // - Dock 3 (UCD3): /ws
    // - Dock 2 (UCD2): root path (empty string)
    const DEFAULT_WS_PATH = '/ws'; // Dock 3
    const DOCK2_WS_PATH = '';

    // DataID for forwarding Dock data to child instances.
    // Must match Dock Manager `childRequirements` and Dock Child `implemented`.
    const DOCK_CHILD_DATAID = '{B65C3047-2C25-5859-A9D6-7408B791CDCD}';

    public function GetCompatibleParents(): string
    {
        // Require the WebSocket Client as parent
        return json_encode([
            'type' => 'require',
            'moduleIDs' => [
                '{D68FD31F-0E90-7019-F16C-1949BD3079EF}'
            ]
        ]);
    }

    private function EnsureApiKey(): bool
    {
        // Dock-API does not expose the same REST API as remote-core for generating API keys.
        // The Dock WebSocket API authenticates with an access token sent via an `auth` message.
        // We store that token in `api_key` attribute for reuse.

        // 1) Manual override from property (user editable in form) has priority.
        //    Keep attribute in sync so all send/auth logic uses the latest value.
        $manualApiKey = trim($this->ReadPropertyString('api_key_display'));
        $apiKey = $this->ReadAttributeString('api_key');

        if ($manualApiKey !== '') {
            if ($manualApiKey !== $apiKey) {
                $this->WriteAttributeString('api_key', $manualApiKey);
                $this->SendDebug(__FUNCTION__, '🔁 Sync API key: property -> attribute (EnsureApiKey).', 0);
            }
            return true;
        }

        // 2) If we already have a stored attribute token, use it
        if ($apiKey !== '') {
            return true;
        }

        // 3) Try pulling from selected Remote 3 Core instance
        $coreInstanceId = (int)$this->ReadPropertyInteger('core_instance_id');
        if ($coreInstanceId > 0) {
            $this->SendDebug(__FUNCTION__, '🔑 Trying to fetch API key from selected Remote 3 Core instance #' . $coreInstanceId . '…', 0);
            $this->UpdateApiKeyFromCoreById($coreInstanceId);
            $apiKey = $this->ReadAttributeString('api_key');
            if ($apiKey !== '') {
                return true;
            }
            $this->SendDebug(__FUNCTION__, '⚠️ Could not fetch API key from Remote 3 Core.', 0);
        }

        $this->SendDebug(__FUNCTION__, '⏸️ API key not available yet (manual empty and core not selected/returned empty).', 0);
        return false;
    }

    /**
     * Fetch API key from the selected Remote 3 Core instance (property `core_instance_id`)
     * and store it in this instance.
     * The selected instance must provide the public function UCR_GetApiKey(int $id): string.
     */
    public function UpdateApiKeyFromCore(): void
    {
        $coreInstanceId = (int)$this->ReadPropertyInteger('core_instance_id');
        $this->SendDebug(__FUNCTION__, '🔑 Fetch API key requested. Selected core_instance_id=' . $coreInstanceId, 0);
        $this->UpdateApiKeyFromCoreById($coreInstanceId);
    }

    /**
     * Internal helper to fetch and store the API key from a given Remote 3 Core instance id.
     */
    public function UpdateApiKeyFromCoreById(int $coreInstanceId): void
    {
        if ($coreInstanceId <= 0 || !@IPS_InstanceExists($coreInstanceId)) {
            $this->SendDebug(__FUNCTION__, '⏸️ No valid Remote 3 Core instance selected.', 0);
            return;
        }

        // Verify function exists in the system (provided by the Remote 3 Core module)
        if (!function_exists('UCR_GetApiKey')) {
            $this->SendDebug(__FUNCTION__, '❌ Function UCR_GetApiKey() not found. Please ensure the Remote 3 Core module is installed/loaded.', 0);
            return;
        }

        try {
            $apiKey = (string)@UCR_GetApiKey($coreInstanceId);
        } catch (Throwable $e) {
            $this->SendDebug(__FUNCTION__, '❌ Error calling UCR_GetApiKey: ' . $e->getMessage(), 0);
            return;
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            $this->SendDebug(__FUNCTION__, '⚠️ Remote 3 Core returned an empty API key.', 0);
            return;
        }

        $this->WriteAttributeString('api_key', $apiKey);
        $this->SendDebug(__FUNCTION__, '✅ API key updated from Remote 3 Core instance #' . $coreInstanceId, 0);

        if (method_exists($this, 'ReloadForm')) {
            $this->ReloadForm();
        }

        // Optionally trigger parent WS client reconfiguration
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if (is_int($parentID) && $parentID > 0) {
            $this->SendDebug(__FUNCTION__, '🔄 Triggering parent ApplyChanges (after API key update)…', 0);
            @IPS_ApplyChanges($parentID);
        }
    }

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString('api_key', '');
        $this->RegisterAttributeString('auth_mode', '');
        $this->RegisterPropertyString('hostname', '');
        $this->RegisterPropertyInteger('core_instance_id', 0);
        $this->RegisterPropertyString('host', '');
        $this->RegisterPropertyString('model', 'UCD3');
        $this->RegisterPropertyString('port', '');
        $this->RegisterPropertyString('https_port', '');
        $this->RegisterPropertyString('ws_path', self::DEFAULT_WS_PATH);
        $this->RegisterPropertyString('ws_port', (string)self::DEFAULT_WS_PORT);
        $this->RegisterPropertyString('ws_host', '');
        $this->RegisterPropertyString('ws_https_port', '');
        $this->RegisterPropertyString('ws_https_host', '');
        $this->RegisterAttributeString('ws_auth_mode', '');
        $this->RegisterAttributeString('ws_api_key', '');
        $this->RegisterAttributeString('sysinfo_raw', '');
        $this->RegisterAttributeString('sysinfo_last_req_id', '');
        $this->RegisterAttributeInteger('dock_msg_id', 0);
        $this->RegisterPropertyString('api_key_display', '');

        //We need to call the RegisterHook function on Kernel READY
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
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

        $host = $this->ReadPropertyString('host');

        // Normalize WS settings depending on Dock model (keeps UI consistent and avoids confusion).
        $model = trim($this->ReadPropertyString('model'));
        if ($model === '') {
            $model = self::MODEL_DOCK3;
        }

        // For Dock 2: ws://IP:946 (no path)
        // For Dock 3: ws://IP/ws (default port 80 can be omitted)
        $expectedWsPort = ($model === self::MODEL_DOCK2) ? (string)self::DOCK2_WS_PORT : (string)self::DEFAULT_WS_PORT;
        $expectedWsPath = ($model === self::MODEL_DOCK2) ? self::DOCK2_WS_PATH : self::DEFAULT_WS_PATH;

        $currentWsPort = (string)$this->ReadPropertyString('ws_port');
        $currentWsPath = (string)$this->ReadPropertyString('ws_path');

        // Only write properties when they differ to avoid ApplyChanges loops.
        $needSync = false;
        if (trim($currentWsPort) !== $expectedWsPort) {
            $needSync = true;
        }
        if ((string)$currentWsPath !== (string)$expectedWsPath) {
            $needSync = true;
        }

        if ($needSync) {
            $this->SendDebug(__FUNCTION__, '🔧 Sync WS defaults for model=' . $model . ' (ws_port=' . $expectedWsPort . ', ws_path=' . json_encode($expectedWsPath) . ')', 0);
            @IPS_SetProperty($this->InstanceID, 'ws_port', $expectedWsPort);
            @IPS_SetProperty($this->InstanceID, 'ws_path', $expectedWsPath);

            // Re-run ApplyChanges once with the corrected properties.
            @IPS_ApplyChanges($this->InstanceID);
            return;
        }

        // If setup is incomplete, keep module inactive and do not touch parent configuration.
        if ($host === '') {
            $this->SendDebug(__FUNCTION__, '⏸️ Setup incomplete (Host missing) – waiting for user input.', 0);
            $this->SetStatus(IS_INACTIVE);
            if (method_exists($this, 'ReloadForm')) {
                $this->ReloadForm();
            }
            return;
        }

        // Debug: log effective WS URL that will be used for the parent (model-aware)
        $cfg = json_decode($this->GetConfigurationForParent(), true);
        if (is_array($cfg) && isset($cfg['URL'])) {
            $this->SendDebug(__FUNCTION__, '🧭 Effective WS URL (model=' . $model . '): ' . (string)$cfg['URL'], 0);
        }

        // Sync: if user changed the API key property, mirror it into the attribute used for sending/auth.
        $propApiKey = trim($this->ReadPropertyString('api_key_display'));
        $attrApiKey = $this->ReadAttributeString('api_key');

        if ($propApiKey !== '' && $propApiKey !== $attrApiKey) {
            $this->SendDebug(__FUNCTION__, '🔁 Sync API key: property -> attribute', 0);
            $this->WriteAttributeString('api_key', $propApiKey);
        }

        // Setup seems complete – ensure Dock access token exists.
        $this->SendDebug(__FUNCTION__, '🔐 Setup complete – ensuring Dock access token…', 0);
        $ok = $this->EnsureApiKey();

        // Update the API key display in the form
        if (method_exists($this, 'ReloadForm')) {
            $this->ReloadForm();
        }

        if (!$ok) {
            $this->SendDebug(__FUNCTION__, '⏸️ Token not available yet – parent WS config will remain dummy.', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        // We have an API key; mark active and trigger parent to fetch fresh configuration.
        $this->SetStatus(IS_ACTIVE);

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if (is_int($parentID) && $parentID > 0) {
            $this->SendDebug(__FUNCTION__, '🔄 Triggering parent ApplyChanges to update WS configuration (API-Key/Headers)…', 0);
            @IPS_ApplyChanges($parentID);
        } else {
            $this->SendDebug(__FUNCTION__, '⚠️ No parent connected yet – cannot trigger WS client reconfiguration.', 0);
        }
    }

    public function GetConfigurationForParent(): string
    {
        $host = trim($this->ReadPropertyString('host'));
        $token = trim($this->ReadAttributeString('api_key'));

        // Determine Dock model (set by discovery). Fallback to Dock 3.
        $model = trim($this->ReadPropertyString('model'));
        if ($model === '') {
            $model = self::MODEL_DOCK3;
        }

        // Build WS URL depending on dock model.
        // Forum confirmed:
        // - Dock 2: ws://IP:946
        // - Dock 3: ws://IP/ws
        $urlHost = ($host !== '') ? $host : '127.0.0.1';

        $protocol = self::DEFAULT_WS_PROTOCOL;
        $url = '';

        if ($model === self::MODEL_DOCK2) {
            // Dock 2: explicit port, no /ws path
            $url = $protocol . $urlHost . ':' . self::DOCK2_WS_PORT . self::DOCK2_WS_PATH;
        } else {
            // Dock 3: /ws path, default port (80) can be omitted in URL
            $path = self::DEFAULT_WS_PATH;
            if ($path === '') {
                $path = '/ws';
            }

            // If a custom ws_port is configured and not default, include it.
            $customPort = (int)trim($this->ReadPropertyString('ws_port'));
            if ($customPort > 0 && $customPort !== self::DEFAULT_WS_PORT) {
                $url = $protocol . $urlHost . ':' . $customPort . $path;
            } else {
                $url = $protocol . $urlHost . $path;
            }
        }

        // If setup is incomplete (host and/or token missing), still configure the WS client URL
        // with the best-known host so the parent does not show a misleading 127.0.0.1.
        // Authentication may happen later once the token becomes available.
        if ($host === '' || $token === '') {
            $this->SendDebug(__FUNCTION__, '⏸️ WS configuration incomplete (Host/Token missing) – using model-based URL without headers.', 0);

            $config = [
                'URL' => $url,
                'VerifyCertificate' => false,
                'Type' => 0,
                'Headers' => json_encode([])
            ];

            return json_encode($config);
        }

        // Ensure we have a valid API key once host+token are present.
        $apiKey = $this->ReadAttributeString('api_key');
        if ($apiKey === '') {
            $this->SendDebug(__FUNCTION__, '🔐 No API key yet – trying to create/validate API key…', 0);
            if (!$this->EnsureApiKey()) {
                $this->SendDebug(__FUNCTION__, '⏸️ WS configuration postponed (API key not available).', 0);

                $config = [
                    'URL' => $url,
                    'VerifyCertificate' => false,
                    'Type' => 0,
                    'Headers' => json_encode([])
                ];

                return json_encode($config);
            }
            $apiKey = $this->ReadAttributeString('api_key');
        }

        // Dock authenticates with an `auth` message, not via HTTP headers.
        $config = [
            'URL' => $url,
            'VerifyCertificate' => false,
            'Type' => 0,
            'Headers' => json_encode([])
        ];

        $this->SendDebug(__FUNCTION__, '🧩 WS Configuration (model=' . $model . '): ' . json_encode($config), 0);
        return json_encode($config);
    }

    public function UpdateWSClient(): void
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if (!is_int($parentID) || $parentID <= 0) {
            $this->SendDebug(__FUNCTION__, '❌ No parent WebSocket Client connected (ConnectionID is empty).', 0);
            return;
        }

        // Build the config exactly like the parent would request it.
        $configJson = $this->GetConfigurationForParent();
        $cfg = json_decode($configJson, true);
        if (!is_array($cfg)) {
            $this->SendDebug(__FUNCTION__, '❌ Invalid parent configuration JSON: ' . $configJson, 0);
            return;
        }

        // WebSocket Client (Symcon) uses these configuration keys:
        // {"Active":false,"Headers":"[]","Type":0,"URL":"ws://<ip>:<port>/<path>","VerifyCertificate":false}
        $this->SendDebug(__FUNCTION__, '🔧 Applying WS client configuration to parent…', 0);
        $this->SendDebug(__FUNCTION__, 'ParentID: ' . $parentID, 0);
        $this->SendDebug(__FUNCTION__, 'Config: ' . json_encode($cfg), 0);

        // Apply required properties
        if (array_key_exists('URL', $cfg)) {
            $this->SendDebug(__FUNCTION__, '➡️ IPS_SetProperty(URL): ' . (string)$cfg['URL'], 0);
            @IPS_SetProperty($parentID, 'URL', (string)$cfg['URL']);
        }

        if (array_key_exists('VerifyCertificate', $cfg)) {
            $this->SendDebug(__FUNCTION__, '➡️ IPS_SetProperty(VerifyCertificate): ' . json_encode((bool)$cfg['VerifyCertificate']), 0);
            @IPS_SetProperty($parentID, 'VerifyCertificate', (bool)$cfg['VerifyCertificate']);
        }

        if (array_key_exists('Type', $cfg)) {
            $this->SendDebug(__FUNCTION__, '➡️ IPS_SetProperty(Type): ' . json_encode((int)$cfg['Type']), 0);
            @IPS_SetProperty($parentID, 'Type', (int)$cfg['Type']);
        }

        if (array_key_exists('Headers', $cfg)) {
            // Headers must be a JSON string (e.g. "[]")
            $headers = $cfg['Headers'];
            if (is_array($headers)) {
                $headers = json_encode($headers);
            }
            $headers = (string)$headers;
            $this->SendDebug(__FUNCTION__, '➡️ IPS_SetProperty(Headers): ' . $headers, 0);
            @IPS_SetProperty($parentID, 'Headers', $headers);
        }

        // Enable the WS client (Symcon uses property name "Active")
        $this->SendDebug(__FUNCTION__, '➡️ IPS_SetProperty(Active): true', 0);
        @IPS_SetProperty($parentID, 'Active', true);

        $this->SendDebug(__FUNCTION__, '🔄 Calling IPS_ApplyChanges on parent…', 0);
        @IPS_ApplyChanges($parentID);

        // Log the resulting parent configuration for troubleshooting
        $finalCfg = @IPS_GetConfiguration($parentID);
        if (is_string($finalCfg) && $finalCfg !== '') {
            $this->SendDebug(__FUNCTION__, '✅ Parent configuration after ApplyChanges: ' . $finalCfg, 0);
        }
    }

    // --- Dock WebSocket API helpers -------------------------------------------------

    private function NextDockMsgId(): int
    {
        $id = (int)$this->ReadAttributeInteger('dock_msg_id');
        $id++;
        // Keep it in a sane range
        if ($id < 0 || $id > 2147483000) {
            $id = 1;
        }
        $this->WriteAttributeInteger('dock_msg_id', $id);
        return $id;
    }

    /**
     * Send a Dock API message that uses the `command` field (most requests).
     *
     * Payload format per AsyncAPI examples:
     *   {"type":"dock","id":<int>,"command":"...", ...}
     */
    private function SendDockCommand(string $command, array $fields = []): void
    {
        $payload = array_merge(
            [
                'type' => 'dock',
                'id' => $this->NextDockMsgId(),
                'command' => $command
            ],
            $fields
        );

        $this->SendDebug(__FUNCTION__, '➡️ Sending dock command: ' . json_encode($payload, JSON_UNESCAPED_SLASHES), 0);
        $this->SendToWebSocket($payload);
    }

    /**
     * Send a Dock API message that uses the `msg` field (e.g. ping).
     *
     * Payload format per AsyncAPI examples:
     *   {"type":"dock","msg":"ping"}
     */
    private function SendDockMsg(string $msg, array $fields = []): void
    {
        $payload = array_merge(
            [
                'type' => 'dock',
                'msg' => $msg
            ],
            $fields
        );

        $this->SendDebug(__FUNCTION__, '➡️ Sending dock msg: ' . json_encode($payload, JSON_UNESCAPED_SLASHES), 0);
        $this->SendToWebSocket($payload);
    }

    /**
     * Dock authentication message (per Dock AsyncAPI spec):
     *   {"type":"auth","token":"..."}
     */
    private function SendAuth(string $token): void
    {
        $token = trim($token);
        $len = strlen($token);

        // Per Dock AsyncAPI spec: token length 4..40 characters
        if ($len === 0) {
            $this->SendDebug(__FUNCTION__, '❌ Empty token – cannot authenticate.', 0);
            return;
        }
        if ($len < 4 || $len > 40) {
            $this->SendDebug(
                __FUNCTION__,
                '❌ Token length invalid (' . $len . '). The Dock API expects an API access token with 4..40 characters. ' .
                'This is NOT the same as the Remote 3 REST API key. Please enter the Dock API access token.',
                0
            );
            return;
        }

        // Do not log the token in plain text
        $this->SendDebug(__FUNCTION__, '🔐 Sending auth message: {"type":"auth","token":"***"} (len=' . $len . ')', 0);
        $this->SendToWebSocket([
            'type' => 'auth',
            'token' => $token
        ]);
    }

    // --- Dock WebSocket API: documented requests -----------------------------------

    /** Ping the dock (no authentication required). */
    public function Ping(): void
    {
        $this->SendDockMsg('ping');
    }

    /**
     * Perform a system command.
     * Allowed values (per docs):
     * ir_receive_on, ir_receive_off, remote_charged, remote_lowbattery, remote_normal, identify, reboot, reset
     */
    public function SystemCommand(string $command): void
    {
        $command = trim($command);
        if ($command === '') {
            $this->SendDebug(__FUNCTION__, '❌ Empty command.', 0);
            return;
        }
        $this->SendDockCommand($command);
    }

    /** Get system information (no authentication required). */
    public function GetSysInfo(): void
    {
        // Use the documented command message format.
        $this->SendDockCommand('get_sysinfo');
    }

    /** Stop a currently repeating IR transmission. */
    public function IRStop(): void
    {
        $this->SendDockCommand('ir_stop');
    }

    /**
     * Send an IR code.
     *
     * @param string $code IR code (Unfolded Circle hex or Pronto)
     * @param string $format 'hex' or 'pronto'
     * @param int $repeat Optional repeat value (0..20)
     * @param array $outputs Optional outputs, e.g. ['int_side'=>true,'int_top'=>true,'ext1'=>true,'ext2'=>true]
     * @param int $featureFlags Optional feature flags (field `f`)
     * @param int $holdMs Optional hold duration in ms (if supported by dock feature flags)
     */
    public function IRSend(string $code, string $format = 'hex', int $repeat = 0, array $outputs = [], int $featureFlags = 0, int $holdMs = 0): void
    {
        $code = trim($code);
        if ($code === '') {
            $this->SendDebug(__FUNCTION__, '❌ Empty IR code.', 0);
            return;
        }

        $format = strtolower(trim($format));
        if ($format !== 'hex' && $format !== 'pronto') {
            $format = 'hex';
        }

        $fields = [
            'code' => $code,
            'format' => $format,
            'repeat' => $repeat,
            'f' => $featureFlags
        ];

        // Optional hold (only if caller provided > 0)
        if ($holdMs > 0) {
            $fields['hold'] = $holdMs;
        }

        // Optional outputs (bool flags)
        foreach (['int_side', 'int_top', 'ext1', 'ext2'] as $k) {
            if (array_key_exists($k, $outputs)) {
                $fields[$k] = (bool)$outputs[$k];
            }
        }

        $this->SendDockCommand('ir_send', $fields);
    }

    /**
     * Set LED brightness.
     *
     * @param int $ledBrightness Main status LED brightness
     * @param int $ethernetLedBrightness Optional ethernet LED brightness (0 = off)
     */
    public function SetBrightness(int $ledBrightness, int $ethernetLedBrightness = -1): void
    {
        $fields = [
            'led_brightness' => $ledBrightness
        ];
        if ($ethernetLedBrightness >= 0) {
            $fields['eth_led_brightness'] = $ethernetLedBrightness;
        }
        $this->SendDockCommand('set_brightness', $fields);
    }

    /** Set speaker volume (0..100 depending on firmware). */
    public function SetVolume(int $volume): void
    {
        $this->SendDockCommand('set_volume', ['volume' => $volume]);
    }

    /**
     * Configure dock logging.
     * This is a thin wrapper; exact fields may differ by firmware version.
     */
    public function SetLogging(string $level, bool $enabled = true): void
    {
        $level = trim($level);
        if ($level === '') {
            $level = 'info';
        }
        $this->SendDockCommand('set_logging', ['level' => $level, 'enabled' => $enabled]);
    }

    /** Get active/supported configuration for a single external port. */
    public function GetPortMode(int $port): void
    {
        $this->SendDockCommand('get_port_mode', ['port' => $port]);
    }

    /** Get active/supported configuration for all external ports. */
    public function GetPortModes(): void
    {
        $this->SendDockCommand('get_port_modes');
    }

    /**
     * Set external port mode.
     *
     * @param int $port 1-based port index
     * @param string $mode Mode string (e.g. AUTO, NONE, IR_BLASTER, TRIGGER_5V, RS232, ...)
     * @param array $uart Optional UART config for RS232, e.g. ['baud_rate'=>9600,'data_bits'=>8,'stop_bits'=>'1','parity'=>'none']
     */
    public function SetPortMode(int $port, string $mode, array $uart = []): void
    {
        $fields = [
            'port' => $port,
            'mode' => $mode
        ];
        if (!empty($uart)) {
            $fields['uart'] = $uart;
        }
        $this->SendDockCommand('set_port_mode', $fields);
    }

    /**
     * Configure 5V trigger output for a port.
     *
     * @param int $port 1-based port index
     * @param bool $enabled Enable/disable trigger
     * @param int $pulseMs Optional pulse duration in ms (0 = continuous, depending on firmware)
     */
    public function SetPortTrigger(int $port, bool $enabled, int $pulseMs = 0): void
    {
        $fields = [
            'port' => $port,
            'enabled' => $enabled
        ];
        if ($pulseMs > 0) {
            $fields['pulse'] = $pulseMs;
        }
        $this->SendDockCommand('set_port_trigger', $fields);
    }

    /** Get current trigger configuration for a port. */
    public function GetPortTrigger(int $port): void
    {
        $this->SendDockCommand('get_port_trigger', ['port' => $port]);
    }

    /**
     * Set (partial) dock configuration.
     * This is a generic wrapper that forwards the given config object as-is.
     */
    public function SetConfig(array $config): void
    {
        $this->SendDockCommand('set_config', $config);
    }

    /**
     * Convenience: send a raw Dock API payload (advanced).
     * The payload must already follow the Dock schema.
     */
    public function SendRawDockPayload(array $payload): void
    {
        if (!isset($payload['type'])) {
            $payload['type'] = 'dock';
        }
        $this->SendDebug(__FUNCTION__, '➡️ Sending raw dock payload: ' . json_encode($payload, JSON_UNESCAPED_SLASHES), 0);
        $this->SendToWebSocket($payload);
    }

    /**
     * Triggers Dock WebSocket authentication using a provided access token.
     * Useful for manual testing from scripts.
     */
    public function Authenticate(): void
    {
        // Prefer the editable property if present; keep attribute synced.
        $prop = trim($this->ReadPropertyString('api_key_display'));
        if ($prop !== '') {
            $this->WriteAttributeString('api_key', $prop);
        }

        $token = trim($this->ReadAttributeString('api_key'));
        if ($token === '') {
            $this->SendDebug(__FUNCTION__, '❌ Empty token – cannot authenticate.', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '🔐 Authenticate(): sending Dock auth message using the currently stored Dock API access token.', 0);
        $this->SendAuth($token);
    }

    public function AuthenticateWithToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            $this->SendDebug(__FUNCTION__, '❌ Empty token – cannot authenticate.', 0);
            return;
        }

        // Store token for reuse and keep form property in sync where possible.
        $this->WriteAttributeString('api_key', $token);
        $this->SendDebug(__FUNCTION__, '🔐 AuthenticateWithToken(): token stored to attribute api_key.', 0);

        $this->SendAuth($token);

        if (method_exists($this, 'ReloadForm')) {
            $this->ReloadForm();
        }
    }


    private function SendToWebSocket(array $payload): void
    {
        // The WebSocket Client transports payloads as HEX strings (see incoming Buffer).
        // Therefore we also send HEX-encoded JSON to avoid corrupted frames (NUL bytes).
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->SendDebug(__FUNCTION__, '❌ WS send failed: json_encode returned false.', 0);
            return;
        }

        $hex = strtoupper(bin2hex($json));

        $data = [
            'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
            'Buffer' => $hex
        ];

        $this->SendDebug(__FUNCTION__, '➡️ WS send (json): ' . $json, 0);
        $this->SendDebug(__FUNCTION__, '➡️ WS send (hex,len=' . strlen($hex) . '): ' . substr($hex, 0, 128) . (strlen($hex) > 128 ? '…' : ''), 0);
        $this->SendDataToParent(json_encode($data));
    }

    private function ForwardToChildren(array $payload): void
    {
        $data = [
            'DataID' => self::DOCK_CHILD_DATAID,
            // Children receive readable JSON; no HEX here
            'Buffer' => json_encode($payload, JSON_UNESCAPED_SLASHES)
        ];
        $this->SendDebug(__FUNCTION__, '➡️ Forward to children: ' . $data['Buffer'], 0);
        $this->SendDataToChildren(json_encode($data));
    }

    public function ForwardData(string $JSONString): string
    {
        $this->SendDebug(__FUNCTION__, '📥 Incoming child data: ' . $JSONString, 0);

        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, '❌ Invalid JSON from child.', 0);
            return json_encode(['error' => 'Invalid JSON']);
        }

        if (!isset($data['Buffer'])) {
            $this->SendDebug(__FUNCTION__, '❌ Missing Buffer in child envelope.', 0);
            return json_encode(['error' => 'Missing Buffer']);
        }

        // Child sends Buffer as JSON string
        $bufferRaw = $data['Buffer'];
        $buffer = null;
        if (is_string($bufferRaw)) {
            $buffer = json_decode($bufferRaw, true);
            if (!is_array($buffer)) {
                // Sometimes Symcon already passes an array-like string; fallback to treating it as plain text
                $this->SendDebug(__FUNCTION__, '⚠️ Buffer is not JSON – raw: ' . $bufferRaw, 0);
            }
        } elseif (is_array($bufferRaw)) {
            $buffer = $bufferRaw;
        }

        if (!is_array($buffer)) {
            return json_encode(['error' => 'Invalid Buffer']);
        }

        // New request style from Dock child: {"action":"get_sysinfo"}
        $action = (string)($buffer['action'] ?? '');
        if ($action !== '') {
            $this->SendDebug(__FUNCTION__, '➡️ Handling action: ' . $action, 0);
            switch ($action) {
                case 'get_sysinfo':
                    // Trigger the actual WS request; response will arrive via ReceiveData and be forwarded to children.
                    $this->GetSysInfo();
                    return '';

                default:
                    $this->SendDebug(__FUNCTION__, '⚠️ Unknown action: ' . $action, 0);
                    return json_encode(['error' => 'Unknown action']);
            }
        }

        // Backward compatibility: old style {"method":"..."}
        $method = (string)($buffer['method'] ?? '');
        if ($method !== '') {
            $this->SendDebug(__FUNCTION__, '➡️ Handling legacy method: ' . $method, 0);
            switch ($method) {
                case 'CallGetVersion':
                    // Legacy placeholder; no-op for Dock Manager
                    $this->SendDebug(__FUNCTION__, 'ℹ️ Legacy CallGetVersion requested – not implemented for Dock Manager.', 0);
                    return '';
                default:
                    $this->SendDebug(__FUNCTION__, '⚠️ Unknown legacy method: ' . $method, 0);
                    return json_encode(['error' => 'Unknown method']);
            }
        }

        $this->SendDebug(__FUNCTION__, '⚠️ No action/method provided in child request.', 0);
        return json_encode(['error' => 'No action']);
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug(__FUNCTION__, '📥 Envelope: ' . $JSONString, 0);

        $envelope = json_decode($JSONString, true);
        if (!is_array($envelope) || !isset($envelope['Buffer'])) {
            $this->SendDebug(__FUNCTION__, '⚠️ Invalid envelope (missing Buffer).', 0);
            return '';
        }

        $raw = $envelope['Buffer'];

        // 1) Raw debug
        if (is_string($raw)) {
            $this->SendDebug(__FUNCTION__, '📥 Buffer (string) length=' . strlen($raw), 0);
            // Avoid logging extremely long buffers verbatim; log a safe prefix
            $this->SendDebug(__FUNCTION__, '📥 Buffer (string, prefix): ' . substr($raw, 0, 256) . (strlen($raw) > 256 ? '…' : ''), 0);
        } else {
            $this->SendDebug(__FUNCTION__, '📥 Buffer (non-string): ' . json_encode($raw), 0);
        }

        $payload = null;

        // 2) Try JSON decode directly if Buffer is a JSON string
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
                $this->SendDebug(__FUNCTION__, '✅ Buffer is JSON (direct).', 0);
            }
        } elseif (is_array($raw)) {
            // Some IOs may already provide the payload as array
            $payload = $raw;
            $this->SendDebug(__FUNCTION__, '✅ Buffer is array (already decoded).', 0);
        }

        // 3) If not JSON, check if Buffer is HEX-encoded JSON (what you currently see in debug)
        if ($payload === null && is_string($raw)) {
            $maybeHex = $raw;
            $isHex = (strlen($maybeHex) % 2 === 0) && (strlen($maybeHex) >= 2) && ctype_xdigit($maybeHex);
            if ($isHex) {
                $this->SendDebug(__FUNCTION__, 'ℹ️ Buffer looks like HEX – attempting hex2bin + JSON decode.', 0);
                $bin = @hex2bin($maybeHex);
                if ($bin !== false) {
                    $this->SendDebug(__FUNCTION__, '📥 Buffer (hex2bin) length=' . strlen($bin), 0);
                    $this->SendDebug(__FUNCTION__, '📥 Buffer (hex2bin, prefix): ' . substr($bin, 0, 256) . (strlen($bin) > 256 ? '…' : ''), 0);
                    $decoded = json_decode($bin, true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                        $this->SendDebug(__FUNCTION__, '✅ Buffer decoded from HEX JSON.', 0);
                    } else {
                        $this->SendDebug(__FUNCTION__, '⚠️ HEX decoded but JSON parse failed. Raw(bin) prefix logged above.', 0);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, '⚠️ hex2bin failed (invalid HEX).', 0);
                }
            }
        }

        // 4) If still unknown, log and stop
        if (!is_array($payload)) {
            $this->SendDebug(__FUNCTION__, '⚠️ Payload could not be decoded (neither JSON nor HEX-JSON).', 0);
            return '';
        }

        $this->SendDebug(__FUNCTION__, '📥 Payload (decoded): ' . json_encode($payload), 0);

        // Forward every decoded dock message to child instances (children can filter by msg/type)
        $this->ForwardToChildren($payload);

        // Dock WebSocket API: server sends `auth_required` right after connect.
        // We must respond with `{"type":"auth","token":"..."}`.
        if (($payload['type'] ?? '') === 'auth_required') {
            $this->SendDebug(__FUNCTION__, '🔐 Dock requested authentication (auth_required). Sending {"type":"auth","token":"..."}.', 0);

            if ($this->EnsureApiKey()) {
                $token = (string)$this->ReadAttributeString('api_key');
                $this->SendAuth($token);
            } else {
                $this->SendDebug(__FUNCTION__, '⏸️ Cannot authenticate yet (Token missing).', 0);
            }
            return '';
        }

        // Log authentication result (optional)
        if (($payload['type'] ?? '') === 'authentication') {
            $code = $payload['code'] ?? null;
            $this->SendDebug(__FUNCTION__, '🔐 Authentication result code: ' . json_encode($code), 0);
            return '';
        }

        // Dock sysinfo response comes as: {"type":"dock","msg":"get_sysinfo", ...}
        if (($payload['type'] ?? '') === 'dock' && ($payload['msg'] ?? '') === 'get_sysinfo' && isset($payload['code']) && (int)$payload['code'] === 200) {
            $this->SendDebug(__FUNCTION__, '🧾 Dock sysinfo received (dock/get_sysinfo): ' . json_encode($payload), 0);
            $this->WriteAttributeString('sysinfo_raw', json_encode($payload));
            $this->ForwardToChildren($payload);
            if (isset($payload['req_id'])) {
                $this->WriteAttributeString('sysinfo_last_req_id', (string)$payload['req_id']);
            }
            if (method_exists($this, 'ReloadForm')) {
                $this->ReloadForm();
            }
            return '';
        }

        // Dock system info response (expected after get_sysinfo)
        $type = (string)($payload['type'] ?? '');
        if ($type === 'sysinfo' || $type === 'get_sysinfo' || $type === 'sys_info' || $type === 'system' || $type === 'system_info') {
            $this->SendDebug(__FUNCTION__, '🧾 Sysinfo received: ' . json_encode($payload), 0);
            $this->WriteAttributeString('sysinfo_raw', json_encode($payload));
            $this->ForwardToChildren($payload);

            if (isset($payload['req_id'])) {
                $this->WriteAttributeString('sysinfo_last_req_id', (string)$payload['req_id']);
            } elseif (isset($payload['reqId'])) {
                $this->WriteAttributeString('sysinfo_last_req_id', (string)$payload['reqId']);
            }

            if (method_exists($this, 'ReloadForm')) {
                $this->ReloadForm();
            }
            return '';
        }

        // Wrapped sysinfo
        if (($payload['kind'] ?? '') === 'result' || ($payload['type'] ?? '') === 'result' || ($payload['type'] ?? '') === 'resp') {
            $msgData = $payload['msg_data'] ?? $payload['data'] ?? null;
            if (is_array($msgData) && (isset($msgData['model']) || isset($msgData['hostname']) || isset($msgData['firmware']) || isset($msgData['hw_rev']))) {
                $this->SendDebug(__FUNCTION__, '🧾 Sysinfo (wrapped) received: ' . json_encode($payload), 0);
                $this->WriteAttributeString('sysinfo_raw', json_encode($payload));
                if (method_exists($this, 'ReloadForm')) {
                    $this->ReloadForm();
                }
                return '';
            }
        }

        // For now, only log other messages; can be extended later.
        return '';
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug(__FUNCTION__, 'Kernel READY', 0);
        }
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
        $host = $this->ReadPropertyString('host');
        $wsHost = $this->ReadPropertyString('ws_host');
        $manualSetup = ($host === '');

        // Helper for read-only property display
        $ro = function (string $name, string $caption) {
            return [
                'type' => 'ValidationTextBox',
                'name' => $name,
                'caption' => $caption,
                'enabled' => false
            ];
        };

        $form = [];

        // --- Optional: Remote 3 Core instance API key reuse (hidden for now) ---
        //
        // $form[] = [
        //     'type' => 'SelectInstance',
        //     'name' => 'core_instance_id',
        //     'caption' => 'Remote 3 Core instance',
        //     'moduleID' => '{C810D534-2395-7C43-D0BE-6DEC069B2516}'
        // ];
        //
        // $form[] = [
        //     'type' => 'Label',
        //     'caption' => 'Select your Remote 3 Core instance to automatically reuse its API key.'
        // ];

        // Manual setup: allow entering host + websocket host
        if ($manualSetup) {
            $form[] = [
                'type' => 'Select',
                'name' => 'model',
                'caption' => 'Model',
                'options' => [
                    ['caption' => 'Dock 3 (UCD3)', 'value' => self::MODEL_DOCK3],
                    ['caption' => 'Dock 2 (UCD2)', 'value' => self::MODEL_DOCK2]
                ]
            ];
            $form[] = [
                'type' => 'ValidationTextBox',
                'name' => 'host',
                'caption' => 'Host (IP)',
                'enabled' => true
            ];

            $form[] = [
                'type' => 'ValidationTextBox',
                'name' => 'ws_host',
                'caption' => 'WebSocket host',
                'enabled' => true
            ];

            $form[] = [
                'type' => 'ValidationTextBox',
                'name' => 'api_key_display',
                'caption' => 'API key',
                'value' => $this->ReadAttributeString('api_key'),
                'enabled' => true
            ];
            $form[] = [
                'type' => 'ValidationTextBox',
                'name' => 'sysinfo_display',
                'caption' => 'Last sysinfo (raw JSON)',
                'value' => $this->ReadAttributeString('sysinfo_raw'),
                'enabled' => false
            ];
        } else {
            // Discovery setup: show all known properties read-only
            $form[] = [
                'type' => 'Label',
                'caption' => 'System information (read-only)'
            ];

            $form[] = $ro('hostname', 'Hostname');
            $form[] = $ro('model', 'Model');
            $form[] = [
                'type' => 'ValidationTextBox',
                'name' => 'host',
                'caption' => 'Host (IP)',
                'enabled' => true
            ];
            $form[] = $ro('port', 'Port');
            $form[] = $ro('https_port', 'HTTPS port');

            $form[] = $ro('ws_host', 'WebSocket host');
            $form[] = $ro('ws_port', 'WebSocket port');
            $form[] = $ro('ws_path', 'WebSocket path');

            $form[] = $ro('ws_https_host', 'WebSocket HTTPS host');
            $form[] = $ro('ws_https_port', 'WebSocket HTTPS port');

            // Dock does not use web_config_user; remove this field.

            $form[] = [
                'type' => 'ValidationTextBox',
                'name' => 'api_key_display',
                'caption' => 'API key',
                'enabled' => true
            ];
            $form[] = [
                'type' => 'ValidationTextBox',
                'name' => 'sysinfo_display',
                'caption' => 'Last sysinfo (raw JSON)',
                'value' => $this->ReadAttributeString('sysinfo_raw'),
                'enabled' => false
            ];
        }

        return $form;
    }

    /**
     * return form actions by token
     *
     * @return array
     */
    protected function FormActions(): array
    {
        return [
            // --- Optional: Remote 3 Core instance API key reuse (hidden for now) ---
            // [
            //     'type' => 'Button',
            //     'caption' => 'Fetch API key from selected Remote 3 Core',
            //     'onClick' => 'UCD_UpdateApiKeyFromCore($id);'
            // ],
            [
                'type' => 'Button',
                'caption' => 'Update WS client configuration',
                'onClick' => 'UCD_UpdateWSClient($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Request Dock sysinfo (get_sysinfo)',
                'onClick' => 'UCD_GetSysInfo($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Authenticate using API Key',
                'onClick' => 'UCD_Authenticate($id);'
            ]
        ];
    }

    /**
     * return from status
     *
     * @return array
     */
    protected function FormStatus(): array
    {
        $form = [
            [
                'code' => IS_CREATING,
                'icon' => 'inactive',
                'caption' => 'Creating instance.'],
            [
                'code' => IS_ACTIVE,
                'icon' => 'active',
                'caption' => 'Remote 3 Dock Manager created.'],
            [
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => 'Interface closed.']];

        return $form;
    }
}
