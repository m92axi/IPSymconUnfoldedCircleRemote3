<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/WebSocketUtils.php';
require_once __DIR__ . '/../libs/Entity_Button.php';
require_once __DIR__ . '/../libs/Entity_Climate.php';
require_once __DIR__ . '/../libs/Entity_Cover.php';
require_once __DIR__ . '/../libs/Entity_IR_Emitter.php';
require_once __DIR__ . '/../libs/Entity_Light.php';
require_once __DIR__ . '/../libs/Entity_Media_Player.php';
require_once __DIR__ . '/../libs/Entity_Remote.php';
require_once __DIR__ . '/../libs/Entity_Sensor.php';
require_once __DIR__ . '/../libs/Entity_Switch.php';
require_once __DIR__ . '/../libs/DeviceRegistry.php';

include_once __DIR__ . '/../libs/ClientSessionManagement.php';

use WebsocketHandler\WebSocketUtils;

class Remote3IntegrationDriver extends IPSModuleStrict
{
    use ClientSessionTrait;

    const DEFAULT_WS_PORT = 9988;

    const Socket_Data = 0;
    const Socket_Connected = 1;
    const Socket_Disconnected = 2;
    const Unfolded_Circle_Driver_Version = "0.2.0";
    const Unfolded_Circle_API_Version = "0.12.1";

    const Unfolded_Circle_API_Minimum_Version = "0.12.1";

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'require',
            'moduleIDs' => ['{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}']
        ]);
    }

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString('token', '');

        $this->RegisterAttributeString('remote_cores', '');

        $this->RegisterAttributeString('client_sessions', '');
        $this->RegisterAttributeString('connected_clients', '');

        $this->RegisterAttributeString('events', '');

        $this->RegisterAttributeString('log_commands', '');


        // Properties for Button and Switch mapping configuration
        $this->RegisterPropertyString('button_mapping', '[]');
        $this->RegisterPropertyString('switch_mapping', '[]');
        $this->RegisterPropertyString('climate_mapping', '[]');
        $this->RegisterPropertyString('cover_mapping', '[]');
        $this->RegisterPropertyString('ir_mapping', '[]');
        $this->RegisterPropertyString('light_mapping', '[]');
        $this->RegisterPropertyString('media_player_mapping', '[]');
        $this->RegisterPropertyString('remote_mapping', '[]');
        $this->RegisterPropertyString('sensor_mapping', '[]');
        $this->RegisterPropertyString('ip_whitelist', '[]');

        // Properties for expert settings
        $this->RegisterPropertyBoolean('extended_debug', false);
        $this->RegisterPropertyString('callback_IP', '');

        //We need to call the RegisterHook function on Kernel READY
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        // $this->RequireParent('{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}');
        $this->RegisterTimer("PingDeviceState", 0, 'UCR_PingDeviceState($_IPS[\'TARGET\']);');

        $this->RegisterTimer('UpdateAllEntityStates', 0, 'UCR_UpdateAllEntityStates($_IPS["TARGET"]);');

    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
        $this->UnregisterHook('/hook/unfoldedcircle');
        $this->UnregisterMdnsService();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->SendDebugExtended(__FUNCTION__, '⚙️ ApplyChanges() aufgerufen', 0);
        //Only call this in READY state. On startup the WebHook instance might not be available yet
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/unfoldedcircle');
            $this->RegisterMdnsService();
            $this->SetTimerInterval("PingDeviceState", 30000); // alle 30 Sekunden den Status senden
            $this->SetTimerInterval("UpdateAllEntityStates", 15000); // alle 15 Sekunden den Status senden
            $this->EnsureTokenInitialized();
        }
        // Register for variable updates for all switches
        $switchMapping = json_decode($this->ReadPropertyString('switch_mapping'), true);
        if (is_array($switchMapping)) {
            foreach ($switchMapping as $entry) {
                if (isset($entry['var_id']) && is_numeric($entry['var_id'])) {
                    $this->RegisterMessage((int)$entry['var_id'], VM_UPDATE);
                }
            }
        }
    }

    /**
     * Ensures that a token exists.
     * Generates a token only once (first-time instance setup) and never overwrites an existing token.
     */
    private function EnsureTokenInitialized(): void
    {
        $token = (string)$this->ReadAttributeString('token');
        if ($token !== '') {
            return;
        }

        $token = bin2hex(random_bytes(16)); // 32 characters hex string
        $this->WriteAttributeString('token', $token);
        $this->SendDebug(__FUNCTION__, '🔑 Initial token generated: ' . $token, 0);

        // If the configuration form is open, reflect the value immediately.
        $this->UpdateFormField('token', 'value', $token);
    }


    public function GetConfigurationForParent(): string
    {

        $Config = [
            // "Open"               => true,
            "Port" => 9988,
            "UseSSL" => false,
            "SilenceErrors" => false
        ];

        $this->SendDebug(__FUNCTION__, '🧩 WS Konfiguration: ' . json_encode($Config), 0);
        return json_encode($Config);
    }

    public function PingDeviceState(): void
    {
        $this->SendDebugExtended(__FUNCTION__, '🔄 Timer-Methode wurde aufgerufen', 0);
        $sessions = $this->getAllClientSessions();
        $whitelist = array_map('trim', array_column(json_decode($this->ReadPropertyString('ip_whitelist'), true), 'ip'));

        foreach ($sessions as $ip => $entry) {
            $isWhitelisted = in_array($ip, $whitelist);
            $isAuthenticated = !empty($entry['authenticated']);
            $hasPort = !empty($entry['port']);

            if (($isAuthenticated || $isWhitelisted) && $hasPort) {
                $this->SendDebugExtended(__FUNCTION__, "🔁 Status-Ping an $ip:{$entry['port']} (auth: " . ($isAuthenticated ? '✅' : '❌') . ", whitelist: " . ($isWhitelisted ? '✅' : '❌') . ")", 0);
                $this->SendDeviceState('CONNECTED', $ip, (int)$entry['port']);
            } else {
                $this->SendDebugExtended(__FUNCTION__, "⏭️ Ping übersprungen für $ip (auth: " . ($isAuthenticated ? '✅' : '❌') . ", whitelist: " . ($isWhitelisted ? '✅' : '❌') . ", port: " . ($entry['port'] ?? '—') . ")", 0);
            }
        }
    }

    public function GetClientSessions(): string
    {
        // $this->WriteAttributeString('client_sessions', "");
        return $this->ReadAttributeString('client_sessions');
    }

    public function GetLoggedEventTypes(): string
    {
        // $this->WriteAttributeString('events', "");
        return $this->ReadAttributeString('events');
    }

    public function GetLoggedCommands(): string
    {
        // $this->WriteAttributeString('events', "");
        return $this->ReadAttributeString('log_commands');
    }

    public function UpdateAllEntityStates(): void
    {
        $this->SendDebugExtended(__FUNCTION__, '🔄 Starte zyklisches Update aller Entitätszustände...', 0);

        $types = [
            'button' => 'button_mapping',
            'switch' => 'switch_mapping',
            'climate' => 'climate_mapping',
            'cover' => 'cover_mapping',
            'ir' => 'ir_mapping',
            'light' => 'light_mapping',
            'media' => 'media_player_mapping',
            'remote' => 'remote_mapping',
            'sensor' => 'sensor_mapping'
        ];

        foreach ($types as $type => $property) {
            $mapping = json_decode($this->ReadPropertyString($property), true);
            if (!is_array($mapping) || count($mapping) === 0) {
                $this->SendDebugExtended(__FUNCTION__, "ℹ️ Keine Einträge für Typ '$type' vorhanden.", 0);
                continue;
            }

            foreach ($mapping as $entry) {
                $attributes = [];

                switch ($type) {
                    case 'button':
                        $scriptId = $entry['script_id'] ?? null;
                        if (is_numeric($scriptId) && @IPS_ScriptExists($scriptId)) {
                            $attributes['state'] = 'AVAILABLE';
                            $this->SendEntityChange('button_' . $scriptId, 'button', $attributes);
                        }
                        break;

                    case 'switch':
                        $varId = $entry['var_id'] ?? null;
                        if (is_numeric($varId) && @IPS_VariableExists($varId)) {
                            $value = @GetValue($varId);
                            $attributes['state'] = $value ? 'ON' : 'OFF';
                            $this->SendEntityChange('switch_' . $entry['instance_id'], 'switch', $attributes);
                        }
                        break;

                    case 'climate':
                        $varId = $entry['status_var_id'] ?? null;
                        if (is_numeric($varId) && @IPS_VariableExists($varId)) {
                            $stateVar = @GetValue($varId);
                            $attributes['state'] = 'OFF';  // Default fallback

                            // Optional: determine actual state from status_var_id if meaningful
                            if (is_bool($stateVar)) {
                                $attributes['state'] = $stateVar ? 'ON' : 'OFF';
                            }

                            // hvac_mode logic from mode_var_id
                            if (!empty($entry['mode_var_id']) && @IPS_VariableExists($entry['mode_var_id'])) {
                                $modeRaw = @GetValue($entry['mode_var_id']);
                                $v = IPS_GetVariable($entry['mode_var_id']);
                                $profile = $v['VariableCustomProfile'] ?: $v['VariableProfile'];

                                $mode = 'OFF';
                                if (IPS_VariableProfileExists($profile)) {
                                    $profileData = IPS_GetVariableProfile($profile);
                                    foreach ($profileData['Associations'] as $assoc) {
                                        if ((int)$assoc['Value'] === (int)$modeRaw) {
                                            $label = strtoupper(trim($assoc['Name']));
                                            $modeMapping = [
                                                'OFF' => 'OFF',
                                                'HEAT' => 'HEAT',
                                                'COOL' => 'COOL',
                                                'AUTO' => 'AUTO',
                                                'FAN' => 'FAN',
                                                'HEAT_COOL' => 'HEAT_COOL',
                                                'HEIZEN' => 'HEAT',
                                                'KÜHLEN' => 'COOL',
                                                'LÜFTEN' => 'FAN',
                                                'HEIZEN/KÜHLEN' => 'HEAT_COOL',
                                                'AUTOMATIK' => 'AUTO',
                                                'AUS' => 'OFF'
                                            ];
                                            $mode = $modeMapping[$label] ?? 'OFF';
                                            break;
                                        }
                                    }
                                }
                                $attributes['hvac_mode'] = $mode;
                            }

                            if (!empty($entry['target_temp_var_id']) && @IPS_VariableExists($entry['target_temp_var_id'])) {
                                $attributes['target_temperature'] = @GetValue($entry['target_temp_var_id']);
                            }

                            if (!empty($entry['current_temp_var_id']) && @IPS_VariableExists($entry['current_temp_var_id'])) {
                                $attributes['current_temperature'] = @GetValue($entry['current_temp_var_id']);
                            }

                            $this->SendDebugExtended(__FUNCTION__, '📤 Sende Entity für Climate: ' . json_encode($attributes), 0);
                            $this->SendEntityChange('climate_' . $entry['instance_id'], 'climate', $attributes);
                        }
                        break;

                    case 'cover':
                        $varId = $entry['position_var_id'] ?? null;
                        if (is_numeric($varId) && @IPS_VariableExists($varId)) {
                            $position = @GetValue($varId);
                            $attributes['position'] = $position;

                            if ($position == 0) {
                                $attributes['state'] = 'CLOSED';
                            } elseif ($position == 100) {
                                $attributes['state'] = 'OPEN';
                            } elseif ($position > 0 && $position < 50) {
                                $attributes['state'] = 'CLOSING';
                            } elseif ($position >= 50 && $position < 100) {
                                $attributes['state'] = 'OPENING';
                            } else {
                                $attributes['state'] = 'SETTING';
                            }

                            $this->SendEntityChange('cover_' . $entry['instance_id'], 'cover', $attributes);
                        }
                        break;

                    case 'light':
                        $varId = $entry['switch_var_id'] ?? null;
                        if (is_numeric($varId) && @IPS_VariableExists($varId)) {
                            $value = @GetValue($varId);
                            $attributes['state'] = $value ? 'ON' : 'OFF';

                            if (!empty($entry['brightness_var_id']) && @IPS_VariableExists($entry['brightness_var_id'])) {
                                $attributes['brightness'] = $this->ConvertBrightnessToRemote($entry['brightness_var_id']);
                            }
                            if (!empty($entry['color_temp_var_id']) && @IPS_VariableExists($entry['color_temp_var_id'])) {
                                $attributes['color_temperature'] = @GetValue($entry['color_temp_var_id']);
                            }
                            if (!empty($entry['color_var_id']) && @IPS_VariableExists($entry['color_var_id'])) {
                                $result = $this->ConvertHexColorToHueSaturation($entry['color_var_id']);
                                if (is_array($result)) {
                                    $attributes['hue'] = $result['hue'];
                                    $attributes['saturation'] = $result['saturation'];
                                }
                            }

                            $this->SendEntityChange('light_' . $entry['instance_id'], 'light', $attributes);
                        }
                        break;

                    case 'media':
                        if (!isset($entry['features']) || !is_array($entry['features'])) {
                            $this->SendDebugExtended(__FUNCTION__, "⚠️ Kein gültiges Feature-Array für Media-Player-Instanz: " . json_encode($entry), 0);
                            continue 2;
                        }

                        $attributes = [];
                        $instanceId = $entry['instance_id'] ?? 0;
                        if ($instanceId === 0) {
                            $this->SendDebugExtended(__FUNCTION__, "⚠️ Kein instance_id gesetzt für Media-Entry: " . json_encode($entry), 0);
                            continue 2;
                        }

                        $entityId = 'media_player_' . $instanceId;
                        $this->SendDebugExtended(__FUNCTION__, "🎵 Verarbeite Media Player: $entityId", 0);

                        $stateSet = false;

                        foreach ($entry['features'] as $feature) {
                            $varId = $feature['var_id'] ?? 0;
                            $key = $feature['feature_key'] ?? null;

                            if ($varId <= 0 || !$key || !@IPS_VariableExists($varId)) {
                                // $this->SendDebug(__FUNCTION__, "⚠️ Überspringe ungültiges Feature: " . json_encode($feature), 0);
                                continue;
                            }

                            $value = @GetValue($varId);
                            switch ($key) {
                                case 'media_duration':
                                case 'media_position':
                                    if (is_string($value)) {
                                        $parts = explode(':', $value);
                                        if (count($parts) === 2) {
                                            $value = ((int)$parts[0] * 60) + (int)$parts[1];
                                        } elseif (count($parts) === 3) {
                                            $value = ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
                                        } else {
                                            $value = 0;
                                        }
                                    }
                                    $attributes[$key] = $value;
                                    break;

                                case 'on_off':
                                    $attributes['state'] = $value ? 'ON' : 'OFF';
                                    $stateSet = true;
                                    break;

                                case 'symcon_control':
                                    $attributes['state'] = $this->GetMediaPlayerStateFromControlVariable($varId);
                                    $stateSet = true;
                                    break;

                                default:
                                    $attributes[$key] = $value;
                                    break;
                            }
                        }

                        if (!$stateSet) {
                            $attributes['state'] = 'ON'; // Fallback
                            $this->SendDebugExtended(__FUNCTION__, "ℹ️ Kein Status-Feature vorhanden, setze 'state' auf ON", 0);
                        }

                        // $this->SendDebug(__FUNCTION__, "📤 Sende Entity für Media Player $entityId: " . json_encode($attributes), 0);
                        $this->SendEntityChange($entityId, 'media_player', $attributes);
                        break;

                    case 'sensor':
                        $varId = $entry['var_id'] ?? null;
                        if (is_numeric($varId) && @IPS_VariableExists($varId)) {
                            $result = $this->GetSensorValueAndUnit($varId);
                            $attributes['value'] = $result['value'];
                            $attributes['unit'] = $result['unit'];
                            $attributes['state'] = 'ON';
                            $this->SendEntityChange('sensor_' . $entry['instance_id'], 'sensor', $attributes);
                        }
                        break;

                    default:
                        $this->SendDebugExtended(__FUNCTION__, "⚠️ Unbekannter Entitätstyp: $type", 0);
                        continue 2;
                }
            }
        }
    }

    /**
     * Ermittelt den Wert und die Einheit eines Sensors inklusive Umrechnung bei bestimmten Profilen.
     *
     * @param int $varId
     * @return array ['value' => float|string, 'unit' => string]
     */
    private function GetSensorValueAndUnit(int $varId): array
    {
        $value = @GetValue($varId);
        $unit = '';
        $varInfo = @IPS_GetVariable($varId);
        $profile = $varInfo['VariableCustomProfile'] ?? $varInfo['VariableProfile'] ?? '';

        if ($profile && @IPS_VariableProfileExists($profile)) {
            $profileInfo = IPS_GetVariableProfile($profile);
            $unit = trim($profileInfo['Suffix'] ?? '');

            // Bekannte Profile, bei denen Werte von z.B. 0.55 in 55 umgerechnet werden sollen
            $normalizeProfiles = [
                '~Intensity.1'
            ];

            if (in_array($profile, $normalizeProfiles)) {
                $value = round($value * 100, $profileInfo['Digits']);
            } // Alternative generische Prüfung: Suffix ist %, Wert ist < 1, und Profil verwendet Dezimalstellen
            elseif (in_array($unit, ['%', ' %', '% ']) && $profileInfo['Digits'] > 0 && $value < 1) {
                $value = round($value * 100, $profileInfo['Digits']);
            }
        }

        return ['value' => $value, 'unit' => $unit];
    }

    private function Send(string $Text): void
    {
        $this->SendDataToChildren(json_encode(['DataID' => '{34A21C2C-646B-1014-D032-DF7E7A88B419}', 'Buffer' => $Text]));
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
            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannte Methode: $method", 0);
                return json_encode(['error' => 'Unbekannter Fehler']);
        }
    }

    private function SendDataWebsocket($payload, string $ClientIP, int $ClientPort): void
    {
        // IPSModuleStrict: Binary data may be transported as HEX strings between instances.
        // Server Socket supports this and will send the decoded bytes on the wire.
        // We therefore ensure the JSON we send to the parent is always valid UTF-8.

        if (!is_string($payload)) {
            $payload = (string)$payload;
        }

        // If payload contains non-UTF8 bytes (typical for WebSocket frames), encode as HEX.
        // This mirrors what we already do in ReceiveData() for incoming buffers.
        $sendBuffer = $payload;
        $isHex = false;

        // Fast path: ASCII / UTF-8 text (handshake HTTP headers etc.)
        if ($sendBuffer !== '' && !mb_check_encoding($sendBuffer, 'UTF-8')) {
            $sendBuffer = bin2hex($sendBuffer);
            $isHex = true;
        }

        // $this->SendDebugExtended(__FUNCTION__, sprintf('📤 SendDataWebsocket → %s buffer to %s:%d (len=%d)', $isHex ? 'HEX' : 'TEXT', $ClientIP, $ClientPort, strlen($sendBuffer)), 0);
        $this->SendDebug(__FUNCTION__, sprintf('📤 SendDataWebsocket → %s buffer to %s:%d (len=%d)', $isHex ? 'HEX' : 'TEXT', $ClientIP, $ClientPort, strlen($sendBuffer)), 0);


        $this->SendDataToParent(json_encode([
            'DataID' => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}',
            'ClientIP' => $ClientIP,
            'ClientPort' => $ClientPort,
            'Type' => self::Socket_Data,
            'Buffer' => $sendBuffer,
            // Hint for our own debugging; harmless for the parent.
            'BufferIsHex' => $isHex
        ]));
    }

    public function ReceiveData(string $JSONString): string
    {
        // Always show at least a small trace that something arrived
        $this->SendDebug(__FUNCTION__, '📥 Incoming (raw length): ' . strlen($JSONString), 0);
        $this->SendDebugExtended(__FUNCTION__, '📥 Raw Data: ' . $JSONString, 0);

        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, '❌ JSON decode failed: ' . json_last_error_msg(), 0);
            $this->SendDebugExtended(__FUNCTION__, '📥 Original JSON string: ' . $JSONString, 0);
            return '';
        }

        $clientIP = (string)($data['ClientIP'] ?? $data['ClientIp'] ?? '');
        $clientPort = (int)($data['ClientPort'] ?? $data['ClientPORT'] ?? 0);
        $type = (int)($data['Type'] ?? -1);

        if (!isset($data['Buffer'])) {
            $this->SendDebug(__FUNCTION__, '❌ Missing Buffer in incoming data.', 0);
            $this->SendDebugExtended(__FUNCTION__, '📥 Incoming object: ' . json_encode($data), 0);
            return '';
        }

        // Buffer may be plain bytes, plain text, or HEX-encoded (IPSModuleStrict / socket variants)
        $buffer = (string)$data['Buffer'];

        // If Buffer looks like HEX (even length + only hex chars), decode it
        if ($buffer !== '' && (strlen($buffer) % 2 === 0) && ctype_xdigit($buffer)) {
            $decoded = @hex2bin($buffer);
            if ($decoded !== false) {
                $buffer = $decoded;
                $this->SendDebugExtended(__FUNCTION__, '🔁 Buffer was HEX → decoded to bytes (len=' . strlen($buffer) . ')', 0);
            }
        }

        // For string operations (headers), keep raw 1-byte string
        $payload = $buffer;

        // Minimal debug (visible without extended_debug)
        $typeLabel = match ($type) {
            self::Socket_Data => 'Data',
            self::Socket_Connected => 'Connected',
            self::Socket_Disconnected => 'Disconnected',
            default => 'Unknown(' . $type . ')'
        };
        $this->SendDebug(__FUNCTION__, "📡 Socket Type: {$typeLabel} | From: {$clientIP}:{$clientPort} | PayloadLen: " . strlen($payload), 0);

        // Token aus Header extrahieren
        $token = null;
        if (preg_match('/auth-token:\s*(.+)/i', $payload, $matches)) {
            $token = trim($matches[1]);
            $this->SendDebug(__FUNCTION__, "🔑 Auth-Token aus Header extrahiert: $token", 0);
        }

        // Direkt nach Header-Token-Erkennung authentifizieren
        if (!empty($token)) {
            $this->SendDebug(__FUNCTION__, '✅ Authentifizierung direkt nach Header-Token-Erkennung', 0);
            $this->authenticateClient($clientIP, $clientPort, $token);
        }

        // Fallback: Token aus JSON extrahieren
        if ($token === null) {
            $jsonText = mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1');
            $payloadJson = json_decode($jsonText, true);
            if (is_array($payloadJson) && isset($payloadJson['auth-token'])) {
                $token = $payloadJson['auth-token'];
                $this->SendDebug(__FUNCTION__, "🔑 Auth-Token aus JSON-Message extrahiert: $token", 0);
            }
        }

        // Client direkt nach Empfang registrieren (track by IP and update port/last_seen)
        $this->addOrUpdateClientSession($clientIP, $clientPort);

        $this->SendDebugExtended(__FUNCTION__, '✅ Payload-Länge: ' . strlen($payload), 0);
        $this->SendDebugExtended(__FUNCTION__, '✅ Client-IP: ' . $clientIP . ' | Port: ' . $clientPort, 0);
        // $this->SendDebug(__FUNCTION__, print_r($_SERVER, true), 0);

        switch ($type) {
            case self::Socket_Data: // Data
                $this->SendDebugExtended(__FUNCTION__, "🟢 WebSocket Type: Data", 0);
                break;
            case self::Socket_Connected: // Connected
                $this->SendDebugExtended(__FUNCTION__, "🟢 WebSocket Type: Connected", 0);
                break;
            case self::Socket_Disconnected: // Disconnected
                $this->SendDebugExtended(__FUNCTION__, "🟠 WebSocket Type: Disconnected", 0);
                break;
            default:
                $this->SendDebugExtended(__FUNCTION__, "⚠️ WebSocket Type: Unbekannt ($type)", 0);
                break;
        }

        // Prüfen, ob es sich um ein WebSocket-Upgrade handelt
        if ($this->PerformWebSocketHandshake($payload, $clientIP, $clientPort)) {
            $this->SendDebug(__FUNCTION__, '✅ Handshake erkannt und ausgeführt → Abbruch', 0);
            return '';
        }

        // WebSocket Payload extrahieren und verarbeiten
        $unpacked = WebSocketUtils::UnpackData($payload, function ($msg, $data) {
            $this->SendDebugExtended($msg, $data, 0);
        });
        if ($unpacked === null) {
            $this->SendDebugExtended(__FUNCTION__, '❌ UnpackData() hat null zurückgegeben', 0);
            return '';
        }

        if ($unpacked['opcode'] === 0x9) {
            $now = date('Y-m-d H:i:s');
            $this->SendDebugExtended(__FUNCTION__, "🔁 [$now] PING empfangen von $clientIP:$clientPort", 0);
            $pong = WebSocketUtils::PackPong();
            // $this->SendDebug(__FUNCTION__, '📤 PONG (hex): ' . bin2hex($pong), 0);
            // $this->SendDebug(__FUNCTION__, "📤 [$now] Sende echten PONG-Frame an $clientIP:$clientPort", 0);
            $this->PushPongToRemoteClient($pong, $clientIP, $clientPort);
            return '';
        }

        // Einzelne Debug-Ausgaben für jedes entpackte Feld
        $this->SendDebugExtended(__FUNCTION__, '📦 FIN: ' . var_export($unpacked['fin'], true), 0);
        $this->SendDebugExtended(__FUNCTION__, '📦 Opcode: ' . $unpacked['opcode'], 0);
        $this->SendDebugExtended(__FUNCTION__, '📦 Opcode Name: ' . $unpacked['opcode_name'], 0);
        $this->SendDebugExtended(__FUNCTION__, '📦 Raw Length: ' . $unpacked['length'], 0);
        $this->SendDebugExtended(__FUNCTION__, '📦 Raw Frame (hex): ' . bin2hex($unpacked['raw']), 0);
        // WebSocket payload is bytes; convert for logging/JSON decoding as UTF-8 best-effort
        $jsonText = $unpacked['payload'];
        if (!mb_check_encoding($jsonText, 'UTF-8')) {
            $jsonText = mb_convert_encoding($jsonText, 'UTF-8', 'ISO-8859-1');
        }
        $this->SendDebugExtended(__FUNCTION__, '📦 Demaskierter Payload (Klartext): ' . $jsonText, 0);

        $this->SendDebugExtended(__FUNCTION__, '✅ Frame wurde erfolgreich entpackt', 0);

        $json = json_decode($jsonText, true);
        if (!is_array($json)) {
            $this->SendDebugExtended(__FUNCTION__, '❌ Ungültiger JSON Payload im Frame', 0);
            // $this->SendDebug(__FUNCTION__, '➡️ Fallback: Weiterleitung an internen WebHook-Endpunkt...', 0);
            // $this->ForwardToWebhook($unpacked['payload']);
            return '';
        }

        $this->SendDebug(__FUNCTION__, '📥 Entpackter Frame: ' . json_encode($json), 0);

        // --- ADDED LOGIC FOR "kind" inspection and event handling ---
        $kind = $json['kind'] ?? '';
        $this->SendDebug(__FUNCTION__, "🧩 Kind: $kind", 0);

        if ($kind === 'event') {
            $this->HandleEventMessage($json, $clientIP, $clientPort);
        }
        // --- END ADDED LOGIC ---

        $msg = $json['msg'] ?? '';
        $reqId = $json['id'] ?? 0;
        $this->SendDebug(__FUNCTION__, "🧩 Message: $msg", 0);
        switch ($msg) {
            case 'authentication':
                $token = $json['msg_data']['token'] ?? null;
                $this->authenticateClient($clientIP, $clientPort, $token);
                break;

            case 'setup_driver':
                $this->SendDebug(__FUNCTION__, '🛠️ Setup-Start empfangen', 0);
                $this->SendResultOK($reqId, $clientIP, $clientPort);
                $this->NotifyDriverSetupComplete($clientIP, $clientPort);
                break;

            case 'set_driver_user_data':
                $this->HandleSetDriverUserData_Simple($json, $reqId, $clientIP, $clientPort);
                break;

            case 'connect':
                $this->SendDebug(__FUNCTION__, '🔌 Verbindung erkannt – sende device_state CONNECTED', 0);
                $this->SendDeviceState('CONNECTED', $clientIP, $clientPort);
                break;

            case 'entity_command':
                $msg_data = $json['msg_data'] ?? [];
                // Log incoming command before handling it
                $this->LogIncomingCommand($msg_data, $json);
                $this->HandleEntityCommand($msg_data, $clientIP, $clientPort, $reqId);
                break;

            case 'get_driver_metadata':
                $this->SendDriverMetadata($clientIP, $clientPort, $reqId);
                break;

            case 'get_driver_version':
                $this->SendDriverVersion($clientIP, $clientPort, $reqId);
                break;

            case 'get_available_entities':
                $this->SendAvailableEntities($clientIP, $clientPort, $reqId);
                break;

            case 'get_entity_states':
                $this->SendEntityStates($clientIP, $clientPort, $reqId);
                break;

            case 'subscribe_events':
                $this->subscribeClientToEvents($clientIP, $clientPort);
                $this->SendResultOK($reqId, $clientIP, $clientPort);
                break;

            default:
                $this->SendDebug(__FUNCTION__, '⚠️ Unbekannte Anfrage: ' . $msg, 0);
                break;
        }
        return '';
    }


    /**
     * Logs unique incoming commands for debugging/audit purposes.
     *
     * @param array $msgData The 'msg_data' array from the incoming message.
     * @param array $fullMessage The full decoded message as array.
     */
    private function LogIncomingCommand(array $msgData, array $fullMessage): void
    {
        $logged = json_decode($this->ReadAttributeString('log_commands'), true);
        if (!is_array($logged)) {
            $logged = [];
        }

        $cmdID = $msgData['cmd_id'] ?? 'undefined';
        $params = $msgData['params'] ?? [];

        // Schlüsselformat für Prüfung
        $key = $cmdID . '|' . json_encode($params);

        if (!array_key_exists($key, $logged)) {
            $logged[$key] = [
                'cmd_id' => $cmdID,
                'params' => $params,
                'full_msg' => $fullMessage
            ];
            $this->SendDebug(__FUNCTION__, "🆕 Neuer Befehl geloggt: $key", 0);
            $this->WriteAttributeString('log_commands', json_encode($logged));
        } else {
            $this->SendDebug(__FUNCTION__, "ℹ️ Bereits geloggt: $key", 0);
        }
    }

    /**
     * Handles incoming event messages from the Remote.
     * @param array $json
     * @param string $ip
     * @param int $port
     */
    private function HandleEventMessage(array $json, string $ip, int $port): void
    {
        $msg = $json['msg'] ?? '';
        // --- BEGIN log unique event types ---
        $loggedEvents = json_decode($this->ReadAttributeString('events'), true);
        if (!is_array($loggedEvents)) {
            $loggedEvents = [];
        }
        if (!in_array($msg, $loggedEvents)) {
            $loggedEvents[] = $msg;
            $this->WriteAttributeString('events', json_encode($loggedEvents));
            $this->SendDebug(__FUNCTION__, "📝 Neuer Event-Typ geloggt: $msg", 0);
        }
        // --- END log unique event types ---
        $this->SendDebug(__FUNCTION__, "📩 Empfangener Event: $msg von $ip:$port", 0);
        $instanceID = $this->FindDeviceInstanceByIp('{5894A8B3-7E60-981A-B3BA-6647335B57E4}', 'host', $ip);

        switch ($msg) {
            case 'enter_standby':
                $this->SendDebug(__FUNCTION__, "🛌 Remote $ip ist in Standby gegangen", 0);
                if ($instanceID > 0) {
                    UCR_ReceiveDriverEvent($instanceID, $json);
                }
                break;

            case 'connect':
                $this->SendDebug(__FUNCTION__, "🔌 Remote $ip ist wieder aktiv → sende CONNECTED", 0);
                $this->SendDeviceState('CONNECTED', $ip, $port);
                $this->UpdateAllEntityStates();
                if ($instanceID > 0) {
                    UCR_ReceiveDriverEvent($instanceID, $json);
                }
                break;

            case 'button_pressed':
                $this->SendDebug(__FUNCTION__, "🟢 Button gedrückt (noch nicht ausgewertet)", 0);
                if ($instanceID > 0) {
                    UCR_ReceiveDriverEvent($instanceID, $json);
                }
                break;

            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannter Event-Typ: $msg", 0);
                break;
        }
    }

    /**
     * Findet eine Geräte-Instanz anhand GUID, Property und IP-Adresse.
     *
     * @param string $guid
     * @param string $property
     * @param string $ip
     * @return int InstanceID oder 0 wenn nicht gefunden
     */
    private function FindDeviceInstanceByIp(string $guid, string $property, string $ip): int
    {
        $instanceIDs = IPS_GetInstanceListByModuleID($guid);
        foreach ($instanceIDs as $id) {
            $prop = @IPS_GetProperty($id, $property);
            if ($prop === $ip) {
                $this->SendDebug(__FUNCTION__, "🎯 Gefundene Instanz für IP $ip: $id", 0);
                return $id;
            }
        }
        $this->SendDebug(__FUNCTION__, "❌ Keine passende Instanz für IP $ip gefunden", 0);
        return 0;
    }


    /**
     * Sendet den aktuellen Gerätestatus an den Remote-Client.
     *
     * @param string $state
     * @param string $clientIP
     * @param int $clientPort
     */
    private function SendDeviceState(string $state, string $clientIP, int $clientPort): void
    {
        $response = [
            'kind' => 'event',
            'msg' => 'device_state',
            'msg_data' => [
                'state' => $state
            ],
            'cat' => 'DEVICE'
        ];
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }


    /**
     * Leitet maskierten Payload an den eigenen Webhook-Endpunkt intern weiter
     *
     * @param string $payload
     */
    private function ForwardToWebhook(string $payload): void
    {
        $token = $this->ReadAttributeString('token');
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                    "Auth-Token: $token\r\n",
                'content' => $payload
            ]
        ]);

        $url = 'http://127.0.0.1:3777/hook/unfoldedcircle';
        $this->SendDebug(__FUNCTION__, '🌐 Sende Fallback-Request an Webhook: ' . $url, 0);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei Weiterleitung – keine Antwort vom Webhook', 0);
        } else {
            $this->SendDebug(__FUNCTION__, '✅ Antwort vom Webhook: ' . $result, 0);
        }
    }


    /**
     * Extracts and performs the WebSocket handshake process.
     *
     * @param string $payload
     * @param string $clientIP
     * @param int $clientPort
     * @return bool True if handshake was performed, false otherwise.
     */
    private function PerformWebSocketHandshake(string $payload, string $clientIP, int $clientPort): bool
    {
        if (!str_starts_with($payload, 'GET /')) {
            return false;
        }

        if (!preg_match('/Sec-WebSocket-Key: (.*)/i', $payload, $matches)) {
            $this->SendDebug(__FUNCTION__, "❌ Kein gültiger Sec-WebSocket-Key gefunden", 0);
            return false;
        }

        $key = trim($matches[1]);
        $magicGUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        $raw = sha1($key . $magicGUID, true);
        $accept = base64_encode($raw);

        $upgradeResponse = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgradeResponse .= "Upgrade: websocket\r\n";
        $upgradeResponse .= "Connection: Upgrade\r\n";
        $upgradeResponse .= "Sec-WebSocket-Accept: $accept\r\n\r\n";

        $this->SendDebug(__FUNCTION__, "🔁 Sende WebSocket-Handshake-Antwort an $clientIP:$clientPort", 0);
        $this->PushRawToRemoteClient($upgradeResponse, $clientIP, $clientPort);
        IPS_Sleep(50); // Mini-Delay für Stabilität

        // 🔐 Authentifizierungsantwort (wie beim Node-Treiber)
        $authMessage = [
            'kind' => 'resp',
            'req_id' => 0,
            'code' => 200,
            'msg' => 'authentication',
            'msg_data' => new stdClass()
        ];
        $this->SendDebug(__FUNCTION__, "🔁 Sende Authentication-Antwort an $clientIP:$clientPort", 0);
        $this->PushToRemoteClient($authMessage, $clientIP, $clientPort);

        // Optional (kann auch später durch Anfrage erfolgen)
        // $this->SendDriverMetadata($clientIP, $clientPort);
        return true;
    }

    private function SendDriverMetadata(string $clientIP, int $clientPort, int $reqId): void
    {
        $first = $this->GetSymconFirstName();

        // Updated descriptions
        $descriptions = [
            'de' => "Verbindet dein Symcon-System mit der Remote 3. Ermöglicht die Steuerung von Systemen wie KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus und viele weitere. \n\nBevor die Einrichtung durchgeführt werden kann, klicken Sie bitte in der Instanz „Remote Integration Driver“ im Objektbaum auf „Token generieren“. Dort wählen Sie auch die Geräte aus, die über die Remote 3 gesteuert werden sollen.\n\nEs werden ausschließlich Geräte angezeigt, die explizit vom Benutzer zur Steuerung freigegeben wurden.\n\nBesuchen Sie die Support-Seite der Firma Symcon für weitergehende Informationen und Dokumentation zum System.",
            'en' => "Connects your Symcon system to Remote 3. Enables control of systems such as KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus and many more. \n\nBefore setup can be performed, please click on “Generate Token” in the “Remote Integration Driver” instance in the object tree. There you also select the devices to be controlled via Remote 3.\n\nOnly devices explicitly enabled by the user for control will be displayed.\n\nVisit the Symcon support page for further information and system documentation.",
            'fr' => "Connecte votre système Symcon à la Remote 3. Permet le contrôle de systèmes tels que KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus et bien d'autres. \n\nAvant de procéder à la configuration, cliquez sur « Générer un jeton » dans l'instance « Remote Integration Driver » de l'arborescence des objets. Vous y sélectionnez également les appareils à contrôler via la Remote 3.\n\nSeuls les appareils explicitement autorisés par l'utilisateur pour le contrôle seront affichés.\n\nConsultez la page d'assistance Symcon pour plus d'informations et de documentation sur le système.",
            'it' => "Collega il tuo sistema Symcon a Remote 3. Consente il controllo di sistemi come KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus e molti altri. \n\nPrima di procedere con la configurazione, clicca su \"Genera token\" nell'istanza \"Remote Integration Driver\" nell'albero degli oggetti. Lì selezioni anche i dispositivi da controllare tramite Remote 3.\n\nVerranno mostrati solo i dispositivi esplicitamente autorizzati dall'utente per il controllo.\n\nVisita la pagina di supporto Symcon per ulteriori informazioni e documentazione sul sistema.",
            'es' => "Conecta tu sistema Symcon con Remote 3. Permite el control de sistemas como KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus y muchos más. \n\nAntes de realizar la configuración, haz clic en \"Generar token\" en la instancia \"Remote Integration Driver\" en el árbol de objetos. Allí también seleccionas los dispositivos que se controlarán a través de Remote 3.\n\nSolo se mostrarán los dispositivos que el usuario haya autorizado explícitamente para el control.\n\nVisita la página de soporte de Symcon para obtener más información y documentación sobre el sistema.",
            'da' => "Forbinder dit Symcon-system med Remote 3. Muliggør styring af systemer som KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus og mange flere. \n\nInden opsætningen kan udføres, skal du klikke på \"Generer token\" i instansen \"Remote Integration Driver\" i objekttræet. Her vælger du også de enheder, der skal styres via Remote 3.\n\nKun enheder, som brugeren eksplicit har givet tilladelse til, vil blive vist.\n\nBesøg Symcons supportside for yderligere information og dokumentation om systemet.",
            'nl' => "Verbindt je Symcon-systeem met Remote 3. Maakt bediening mogelijk van systemen zoals KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus en vele andere. \n\nVoordat de installatie kan worden uitgevoerd, klik je in de instantie \"Remote Integration Driver\" in de objectboom op \"Token genereren\". Daar selecteer je ook de apparaten die via Remote 3 moeten worden bediend.\n\nAlleen apparaten die door de gebruiker expliciet voor bediening zijn vrijgegeven, worden weergegeven.\n\nBezoek de Symcon-supportpagina voor meer informatie en documentatie over het systeem.",
            'pl' => "Łączy system Symcon z Remote 3. Umożliwia sterowanie systemami takimi jak KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus i wieloma innymi. \n\nPrzed rozpoczęciem konfiguracji kliknij „Generuj token” w instancji „Remote Integration Driver” w drzewie obiektów. Tam również wybierasz urządzenia, które mają być sterowane przez Remote 3.\n\nWyświetlane będą wyłącznie urządzenia, które użytkownik wyraźnie udostępnił do sterowania.\n\nOdwiedź stronę wsparcia Symcon, aby uzyskać więcej informacji i dokumentacji dotyczącej systemu.",
            'de-CH' => "Verbindet dein Symcon-System mit der Remote 3. Ermöglicht die Steuerung von Systemen wie KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus und viele weitere. \n\nBevor die Einrichtung durchgeführt werden kann, klicken Sie bitte in der Instanz „Remote Integration Driver“ im Objektbaum auf „Token generieren“. Dort wählen Sie auch die Geräte aus, die über die Remote 3 gesteuert werden sollen.\n\nEs werden ausschließlich Geräte angezeigt, die explizit vom Benutzer zur Steuerung freigegeben wurden.\n\nBesuchen Sie die Support-Seite der Firma Symcon für weitergehende Informationen und Dokumentation zum System.",
            'de-AT' => "Verbindet dein Symcon-System mit der Remote 3. Ermöglicht die Steuerung von Systemen wie KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus und viele weitere. \n\nBevor die Einrichtung durchgeführt werden kann, klicken Sie bitte in der Instanz „Remote Integration Driver“ im Objektbaum auf „Token generieren“. Dort wählen Sie auch die Geräte aus, die über die Remote 3 gesteuert werden sollen.\n\nEs werden ausschließlich Geräte angezeigt, die explizit vom Benutzer zur Steuerung freigegeben wurden.\n\nBesuchen Sie die Support-Seite der Firma Symcon für weitergehende Informationen und Dokumentation zum System.",
            'nl-BE' => "Verbindt je Symcon-systeem met Remote 3. Maakt bediening mogelijk van systemen zoals KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus en vele andere. \n\nVoordat de installatie kan worden uitgevoerd, klik je in de instantie \"Remote Integration Driver\" in de objectboom op \"Token genereren\". Daar selecteer je ook de apparaten die via Remote 3 moeten worden bediend.\n\nAlleen apparaten die door de gebruiker expliciet voor bediening zijn vrijgegeven, worden weergegeven.\n\nBezoek de Symcon-supportpagina voor meer informatie en documentatie over het systeem."
        ];

        $response = [
            'kind' => 'resp',
            'req_id' => $reqId,
            'code' => 200,
            'msg' => 'driver_metadata',
            'msg_data' => [
                'driver_id' => 'uc_symcon_driver',
                'version' => self::Unfolded_Circle_Driver_Version,
                'min_core_api' => self::Unfolded_Circle_API_Minimum_Version,
                'name' => [
                    'fr' => 'Symcon (Symcon de ' . $first . ')',
                    'en' => 'Symcon (Symcon from ' . $first . ')',
                    'de' => 'Symcon (Symcon von ' . $first . ')',
                    'it' => 'Symcon (Symcon da ' . $first . ')',
                    'es' => 'Symcon (Symcon de ' . $first . ')',
                    'da' => 'Symcon (Symcon fra ' . $first . ')',
                    'nl' => 'Symcon (Symcon van ' . $first . ')',
                    'pl' => 'Symcon (Symcon od ' . $first . ')',
                    'de-CH' => 'Symcon (Symcon von ' . $first . ')',
                    'de-AT' => 'Symcon (Symcon von ' . $first . ')',
                    'nl-BE' => 'Symcon (Symcon van ' . $first . ')'
                ],
                'icon' => 'custom:symcon_icon.png',
                'description' => $descriptions,
                'port' => 9988,
                'developer' => [
                    'name' => 'Fonzo',
                    'email' => 'aggadur@gmail.com',
                    'url' => 'https://www.symcon.de/en/module-store/'
                ],
                'home_page' => 'https://www.symcon.de/en/',
                'release_date' => '2025-05-21',
                // Unfolded Circle official setup_data_schema format
                'setup_data_schema' => [
                    'title' => [
                        'en' => 'Symcon',
                        'de' => 'Symcon',
                        'fr' => 'Symcon'
                    ],
                    'settings' => [
                        [
                            'id' => 'info',
                            'label' => [
                                'en' => 'Setup progress for Symcon integration',
                                'de' => 'Setup Fortschritt Anbindung von Symcon',
                                'fr' => 'Progression de l’intégration Symcon',
                                'it' => 'Avanzamento configurazione Symcon',
                                'es' => 'Progreso de la integración Symcon',
                                'nl' => 'Voortgang van Symcon-integratie'
                            ],
                            'field' => [
                                'label' => [
                                    'value' => [
                                        'de' => "Diese Integration ermöglicht die Verbindung zwischen der Remote von Unfolded Circle und Symcon – der zentralen Plattform für professionelle Gebäude- und Hausautomation.\n\n\n🔑 **Wichtig vor dem Start:**\n\n• Navigieren Sie in Symcon zur *Remote Integration Driver*-Instanz und klicken Sie auf „Token generieren“.\n\n• Wählen Sie dort ebenfalls die Geräte aus, die über die Remote von Unfolded Circle gesteuert werden sollen. Nur explizit vom Nutzer freigegebene Geräte erscheinen in der Integration.\n\n\n\nℹ️ **Was ist Symcon?**\n\n• Symcon verbindet viele Systeme in einer leistungsstarken Plattform:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • AV-Systeme, MQTT u. v. m.\n\nDamit können Licht, Klima, Jalousien, Sensoren und Szenarien nahtlos gesteuert werden.\n\n👉 [Weitere Informationen](https://www.symcon.de)",
                                        'en' => "This integration enables connecting the Unfolded Circle Remote with Symcon – the central platform for professional building and home automation.\n\n\n🔑 **Before you begin:**\n\n• In Symcon, go to the *Remote Integration Driver* instance and click “Generate Token”.\n\n• There, select the devices to be controlled via the Unfolded Circle Remote. Only explicitly enabled devices will appear.\n\n\n\nℹ️ **What is Symcon?**\n\n• Symcon brings together many systems into one powerful platform:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • AV systems, MQTT and more.\n\nThis allows seamless control of lighting, climate, blinds, sensors and automation scenes.\n\n👉 [Learn more](https://www.symcon.de/en)",
                                        'fr' => "Cette intégration permet de connecter la télécommande Unfolded Circle à Symcon – la plateforme centrale pour l’automatisation des bâtiments et maisons intelligentes.\n\n\n🔑 **Avant de commencer :**\n• Dans Symcon, accédez à l’instance *Remote Integration Driver* et cliquez sur « Générer un jeton ».\n• Sélectionnez ensuite les appareils à contrôler via la télécommande. Seuls les appareils explicitement autorisés apparaîtront.\n\n\n\nℹ️ **Qu’est-ce que Symcon ?**\n\n• Symcon unifie de nombreux systèmes dans une plateforme puissante :\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • systèmes AV, MQTT, etc.\n\nCela permet un contrôle fluide de l’éclairage, du climat, des stores, des capteurs et des scènes.\n\n👉 [En savoir plus](https://www.symcon.de/fr)",
                                        'it' => "Questa integrazione consente di collegare il telecomando Unfolded Circle a Symcon – la piattaforma centrale per l’automazione professionale di edifici e case.\n\n\n🔑 **Prima di iniziare:**\n• In Symcon, vai all'istanza *Remote Integration Driver* e fai clic su “Genera token”.\n• Seleziona i dispositivi da controllare con il telecomando. Solo i dispositivi autorizzati appariranno nell'integrazione.\n\n\n\nℹ️ **Cos’è Symcon?**\n\n• Symcon unisce molti sistemi in una potente piattaforma:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • sistemi AV, MQTT e altro.\n\nPuoi controllare illuminazione, clima, tende, sensori e scenari complessi in modo fluido.\n\n👉 [Ulteriori informazioni](https://www.symcon.de/it)",
                                        'es' => "Esta integración conecta el control remoto de Unfolded Circle con Symcon – la plataforma central para la automatización profesional de edificios y hogares.\n\n\n🔑 **Antes de comenzar:**\n• En Symcon, ve a la instancia *Remote Integration Driver* y haz clic en “Generar token”.\n• Luego selecciona los dispositivos a controlar. Solo aparecerán los autorizados explícitamente.\n\n\n\nℹ️ **¿Qué es Symcon?**\n\n• Symcon integra muchos sistemas en una plataforma potente:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • sistemas AV, MQTT y más.\n\nPermite controlar fácilmente luces, clima, persianas, sensores y escenas automatizadas.\n\n👉 [Más información](https://www.symcon.de/es)",
                                        'nl' => "Deze integratie verbindt de Unfolded Circle afstandsbediening met Symcon – het centrale platform voor professionele gebouw- en huisautomatisering.\n\n\n🔑 **Voordat je begint:**\n• Ga in Symcon naar de *Remote Integration Driver*-instantie en klik op “Token genereren”.\n• Selecteer de apparaten die via de afstandsbediening bediend moeten worden. Alleen expliciet geactiveerde apparaten worden weergegeven.\n\n\n\nℹ️ **Wat is Symcon?**\n\n• Symcon combineert vele systemen in één krachtig platform:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • AV-systemen, MQTT en meer.\n\nHiermee kunnen verlichting, klimaat, zonwering, sensoren en scènes eenvoudig worden bediend.\n\n👉 [Meer informatie](https://www.symcon.de/nl)"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }

    private function SendDriverMetadata_Backup(string $clientIP, int $clientPort, int $reqId): void
    {
        $first = $this->GetSymconFirstName();

        // Updated descriptions
        $descriptions = [
            'de' => "Verbindet dein Symcon-System mit der Remote 3. Ermöglicht die Steuerung von Systemen wie KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus und viele weitere. \n\nBevor die Einrichtung durchgeführt werden kann, klicken Sie bitte in der Instanz „Remote Integration Driver“ im Objektbaum auf „Token generieren“. Dort wählen Sie auch die Geräte aus, die über die Remote 3 gesteuert werden sollen.\n\nEs werden ausschließlich Geräte angezeigt, die explizit vom Benutzer zur Steuerung freigegeben wurden.\n\nBesuchen Sie die Support-Seite der Firma Symcon für weitergehende Informationen und Dokumentation zum System.",
            'en' => "Connects your Symcon system to Remote 3. Enables control of systems such as KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus and many more. \n\nBefore setup can be performed, please click on “Generate Token” in the “Remote Integration Driver” instance in the object tree. There you also select the devices to be controlled via Remote 3.\n\nOnly devices explicitly enabled by the user for control will be displayed.\n\nVisit the Symcon support page for further information and system documentation.",
            'fr' => "Connecte votre système Symcon à la Remote 3. Permet le contrôle de systèmes tels que KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus et bien d'autres. \n\nAvant de procéder à la configuration, cliquez sur « Générer un jeton » dans l'instance « Remote Integration Driver » de l'arborescence des objets. Vous y sélectionnez également les appareils à contrôler via la Remote 3.\n\nSeuls les appareils explicitement autorisés par l'utilisateur pour le contrôle seront affichés.\n\nConsultez la page d'assistance Symcon pour plus d'informations et de documentation sur le système.",
            'it' => "Collega il tuo sistema Symcon a Remote 3. Consente il controllo di sistemi come KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus e molti altri. \n\nPrima di procedere con la configurazione, clicca su \"Genera token\" nell'istanza \"Remote Integration Driver\" nell'albero degli oggetti. Lì selezioni anche i dispositivi da controllare tramite Remote 3.\n\nVerranno mostrati solo i dispositivi esplicitamente autorizzati dall'utente per il controllo.\n\nVisita la pagina di supporto Symcon per ulteriori informazioni e documentazione sul sistema.",
            'es' => "Conecta tu sistema Symcon con Remote 3. Permite el control de sistemas como KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus y muchos más. \n\nAntes de realizar la configuración, haz clic en \"Generar token\" en la instancia \"Remote Integration Driver\" en el árbol de objetos. Allí también seleccionas los dispositivos que se controlarán a través de Remote 3.\n\nSolo se mostrarán los dispositivos que el usuario haya autorizado explícitamente para el control.\n\nVisita la página de soporte de Symcon para obtener más información y documentación sobre el sistema.",
            'da' => "Forbinder dit Symcon-system med Remote 3. Muliggør styring af systemer som KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus og mange flere. \n\nInden opsætningen kan udføres, skal du klikke på \"Generer token\" i instansen \"Remote Integration Driver\" i objekttræet. Her vælger du også de enheder, der skal styres via Remote 3.\n\nKun enheder, som brugeren eksplicit har givet tilladelse til, vil blive vist.\n\nBesøg Symcons supportside for yderligere information og dokumentation om systemet.",
            'nl' => "Verbindt je Symcon-systeem met Remote 3. Maakt bediening mogelijk van systemen zoals KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus en vele andere. \n\nVoordat de installatie kan worden uitgevoerd, klik je in de instantie \"Remote Integration Driver\" in de objectboom op \"Token genereren\". Daar selecteer je ook de apparaten die via Remote 3 moeten worden bediend.\n\nAlleen apparaten die door de gebruiker expliciet voor bediening zijn vrijgegeven, worden weergegeven.\n\nBezoek de Symcon-supportpagina voor meer informatie en documentatie over het systeem.",
            'pl' => "Łączy system Symcon z Remote 3. Umożliwia sterowanie systemami takimi jak KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus i wieloma innymi. \n\nPrzed rozpoczęciem konfiguracji kliknij „Generuj token” w instancji „Remote Integration Driver” w drzewie obiektów. Tam również wybierasz urządzenia, które mają być sterowane przez Remote 3.\n\nWyświetlane będą wyłącznie urządzenia, które użytkownik wyraźnie udostępnił do sterowania.\n\nOdwiedź stronę wsparcia Symcon, aby uzyskać więcej informacji i dokumentacji dotyczącej systemu.",
            'de-CH' => "Verbindet dein Symcon-System mit der Remote 3. Ermöglicht die Steuerung von Systemen wie KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus und viele weitere. \n\nBevor die Einrichtung durchgeführt werden kann, klicken Sie bitte in der Instanz „Remote Integration Driver“ im Objektbaum auf „Token generieren“. Dort wählen Sie auch die Geräte aus, die über die Remote 3 gesteuert werden sollen.\n\nEs werden ausschließlich Geräte angezeigt, die explizit vom Benutzer zur Steuerung freigegeben wurden.\n\nBesuchen Sie die Support-Seite der Firma Symcon für weitergehende Informationen und Dokumentation zum System.",
            'de-AT' => "Verbindet dein Symcon-System mit der Remote 3. Ermöglicht die Steuerung von Systemen wie KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus und viele weitere. \n\nBevor die Einrichtung durchgeführt werden kann, klicken Sie bitte in der Instanz „Remote Integration Driver“ im Objektbaum auf „Token generieren“. Dort wählen Sie auch die Geräte aus, die über die Remote 3 gesteuert werden sollen.\n\nEs werden ausschließlich Geräte angezeigt, die explizit vom Benutzer zur Steuerung freigegeben wurden.\n\nBesuchen Sie die Support-Seite der Firma Symcon für weitergehende Informationen und Dokumentation zum System.",
            'nl-BE' => "Verbindt je Symcon-systeem met Remote 3. Maakt bediening mogelijk van systemen zoals KNX, LCN, BACnet, Homematic IP, DMX, OPUS, Modbus en vele andere. \n\nVoordat de installatie kan worden uitgevoerd, klik je in de instantie \"Remote Integration Driver\" in de objectboom op \"Token genereren\". Daar selecteer je ook de apparaten die via Remote 3 moeten worden bediend.\n\nAlleen apparaten die door de gebruiker expliciet voor bediening zijn vrijgegeven, worden weergegeven.\n\nBezoek de Symcon-supportpagina voor meer informatie en documentatie over het systeem."
        ];

        $response = [
            'kind' => 'resp',
            'req_id' => $reqId,
            'code' => 200,
            'msg' => 'driver_metadata',
            'msg_data' => [
                'driver_id' => 'uc_symcon_driver',
                'version' => self::Unfolded_Circle_Driver_Version,
                'min_core_api' => self::Unfolded_Circle_API_Minimum_Version,
                'name' => [
                    'fr' => 'Symcon (Symcon de ' . $first . ')',
                    'en' => 'Symcon (Symcon from ' . $first . ')',
                    'de' => 'Symcon (Symcon von ' . $first . ')',
                    'it' => 'Symcon (Symcon da ' . $first . ')',
                    'es' => 'Symcon (Symcon de ' . $first . ')',
                    'da' => 'Symcon (Symcon fra ' . $first . ')',
                    'nl' => 'Symcon (Symcon van ' . $first . ')',
                    'pl' => 'Symcon (Symcon od ' . $first . ')',
                    'de-CH' => 'Symcon (Symcon von ' . $first . ')',
                    'de-AT' => 'Symcon (Symcon von ' . $first . ')',
                    'nl-BE' => 'Symcon (Symcon van ' . $first . ')'
                ],
                'icon' => 'custom:symcon_icon.png',
                'description' => $descriptions,
                'port' => 9988,
                'developer' => [
                    'name' => 'Fonzo',
                    'email' => 'aggadur@gmail.com',
                    'url' => 'https://www.symcon.de/en/module-store/'
                ],
                'home_page' => 'https://www.symcon.de/en/',
                'release_date' => '2025-05-21',
                // Unfolded Circle official setup_data_schema format
                'setup_data_schema' => [
                    'title' => [
                        'en' => 'Symcon',
                        'de' => 'Symcon',
                        'fr' => 'Symcon'
                    ],
                    'settings' => [
                        [
                            'id' => 'info',
                            'label' => [
                                'en' => 'Setup progress for Symcon integration',
                                'de' => 'Setup Fortschritt Anbindung von Symcon',
                                'fr' => 'Progression de l’intégration Symcon',
                                'it' => 'Avanzamento configurazione Symcon',
                                'es' => 'Progreso de la integración Symcon',
                                'nl' => 'Voortgang van Symcon-integratie'
                            ],
                            'field' => [
                                'label' => [
                                    'value' => [
                                        'de' => "Diese Integration ermöglicht die Verbindung zwischen der Remote von Unfolded Circle und Symcon – der zentralen Plattform für professionelle Gebäude- und Hausautomation.\n\n\n🔑 **Wichtig vor dem Start:**\n\n• Navigieren Sie in Symcon zur *Remote Integration Driver*-Instanz und klicken Sie auf „Token generieren“.\n\n• Wählen Sie dort ebenfalls die Geräte aus, die über die Remote von Unfolded Circle gesteuert werden sollen. Nur explizit vom Nutzer freigegebene Geräte erscheinen in der Integration.\n\n\n\nℹ️ **Was ist Symcon?**\n\n• Symcon verbindet viele Systeme in einer leistungsstarken Plattform:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • AV-Systeme, MQTT u. v. m.\n\nDamit können Licht, Klima, Jalousien, Sensoren und Szenarien nahtlos gesteuert werden.\n\n👉 [Weitere Informationen](https://www.symcon.de)",
                                        'en' => "This integration enables connecting the Unfolded Circle Remote with Symcon – the central platform for professional building and home automation.\n\n\n🔑 **Before you begin:**\n\n• In Symcon, go to the *Remote Integration Driver* instance and click “Generate Token”.\n\n• There, select the devices to be controlled via the Unfolded Circle Remote. Only explicitly enabled devices will appear.\n\n\n\nℹ️ **What is Symcon?**\n\n• Symcon brings together many systems into one powerful platform:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • AV systems, MQTT and more.\n\nThis allows seamless control of lighting, climate, blinds, sensors and automation scenes.\n\n👉 [Learn more](https://www.symcon.de/en)",
                                        'fr' => "Cette intégration permet de connecter la télécommande Unfolded Circle à Symcon – la plateforme centrale pour l’automatisation des bâtiments et maisons intelligentes.\n\n\n🔑 **Avant de commencer :**\n• Dans Symcon, accédez à l’instance *Remote Integration Driver* et cliquez sur « Générer un jeton ».\n• Sélectionnez ensuite les appareils à contrôler via la télécommande. Seuls les appareils explicitement autorisés apparaîtront.\n\n\n\nℹ️ **Qu’est-ce que Symcon ?**\n\n• Symcon unifie de nombreux systèmes dans une plateforme puissante :\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • systèmes AV, MQTT, etc.\n\nCela permet un contrôle fluide de l’éclairage, du climat, des stores, des capteurs et des scènes.\n\n👉 [En savoir plus](https://www.symcon.de/fr)",
                                        'it' => "Questa integrazione consente di collegare il telecomando Unfolded Circle a Symcon – la piattaforma centrale per l’automazione professionale di edifici e case.\n\n\n🔑 **Prima di iniziare:**\n• In Symcon, vai all'istanza *Remote Integration Driver* e fai clic su “Genera token”.\n• Seleziona i dispositivi da controllare con il telecomando. Solo i dispositivi autorizzati appariranno nell'integrazione.\n\n\n\nℹ️ **Cos’è Symcon?**\n\n• Symcon unisce molti sistemi in una potente piattaforma:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • sistemi AV, MQTT e altro.\n\nPuoi controllare illuminazione, clima, tende, sensori e scenari complessi in modo fluido.\n\n👉 [Ulteriori informazioni](https://www.symcon.de/it)",
                                        'es' => "Esta integración conecta el control remoto de Unfolded Circle con Symcon – la plataforma central para la automatización profesional de edificios y hogares.\n\n\n🔑 **Antes de comenzar:**\n• En Symcon, ve a la instancia *Remote Integration Driver* y haz clic en “Generar token”.\n• Luego selecciona los dispositivos a controlar. Solo aparecerán los autorizados explícitamente.\n\n\n\nℹ️ **¿Qué es Symcon?**\n\n• Symcon integra muchos sistemas en una plataforma potente:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • sistemas AV, MQTT y más.\n\nPermite controlar fácilmente luces, clima, persianas, sensores y escenas automatizadas.\n\n👉 [Más información](https://www.symcon.de/es)",
                                        'nl' => "Deze integratie verbindt de Unfolded Circle afstandsbediening met Symcon – het centrale platform voor professionele gebouw- en huisautomatisering.\n\n\n🔑 **Voordat je begint:**\n• Ga in Symcon naar de *Remote Integration Driver*-instantie en klik op “Token genereren”.\n• Selecteer de apparaten die via de afstandsbediening bediend moeten worden. Alleen expliciet geactiveerde apparaten worden weergegeven.\n\n\n\nℹ️ **Wat is Symcon?**\n\n• Symcon combineert vele systemen in één krachtig platform:\n\n  • KNX, LCN, DMX, Modbus, BACnet\n\n • Homematic IP, EnOcean, ZigBee, Z-Wave\n\n • AV-systemen, MQTT en meer.\n\nHiermee kunnen verlichting, klimaat, zonwering, sensoren en scènes eenvoudig worden bediend.\n\n👉 [Meer informatie](https://www.symcon.de/nl)"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }

    private function HandleSetDriverUserData_Simple(array $json, int $reqId, string $clientIP, int $clientPort): void
    {
        $this->SendDebug(__FUNCTION__, '📥 Setup-Daten vom Benutzer empfangen (vereinfachter Flow)', 0);

        $confirmation = [
            'kind' => 'resp',
            'req_id' => $reqId,
            'code' => 200,
            'msg' => 'result',
            'msg_data' => [
                'setup_action' => [
                    'type' => 'setup_complete'
                ]
            ]
        ];
        $this->PushToRemoteClient($confirmation, $clientIP, $clientPort);
    }

    /**
     * Handles the 'set_driver_user_data' message for setup flow.
     */
    private function HandleSetDriverUserData_Complex(array $json, int $reqId, string $clientIP, int $clientPort): void
    {
        $this->SendDebug(__FUNCTION__, '📥 Setup-Daten vom Benutzer empfangen', 0);
        // Enhanced debug output: log full msg_data and keys/values of input_values
        $this->SendDebug(__FUNCTION__, '📨 Vollständiger msg_data: ' . json_encode($json['msg_data'], JSON_PRETTY_PRINT), 0);
        $inputValues = $json['msg_data']['input_values'] ?? [];
        if (!empty($inputValues)) {
            foreach ($inputValues as $key => $value) {
                $this->SendDebug(__FUNCTION__, "🔑 Eingabe: $key => $value", 0);
            }
        } else {
            $this->SendDebug(__FUNCTION__, '⚠️ Keine input_values enthalten', 0);
        }
        $this->SendDebug(__FUNCTION__, '📊 input_values: ' . json_encode($inputValues), 0);

        // STEP 1: Confirmation (replaces step1.choice, explanation-only screen)
        if (isset($inputValues['step1.confirmation'])) {
            $this->SendDebug(__FUNCTION__, '➡️ Schritt 1: Einleitung bestätigt', 0);

            $token = $this->ReadAttributeString('token');
            if (empty($token)) {
                // Generate new token if not set (assume method exists)
                $this->GenerateToken();
                $token = $this->ReadAttributeString('token');
            }

            $nextStep = [
                'kind' => 'resp',
                'req_id' => $reqId,
                'code' => 200,
                'msg' => 'result',
                'msg_data' => [
                    'setup_action' => [
                        'type' => 'request_user_data',
                        'input' => [
                            'title' => ['en' => 'Access Token'],
                            'settings' => [
                                [
                                    'id' => 'step2.token',
                                    'label' => [
                                        'en' => 'Token for remote access',
                                        'de' => 'Token für Remote-Zugriff'
                                    ],
                                    'field' => [
                                        'text' => [
                                            'value' => $token
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            $this->PushToRemoteClient($nextStep, $clientIP, $clientPort);

            // STEP 2: Token entry and validation
        } elseif (isset($inputValues['step2.token'])) {
            $tokenUser = $inputValues['step2.token'];
            $tokenStored = $this->ReadAttributeString('token');

            if ($tokenUser !== $tokenStored) {
                $this->SendDebug(__FUNCTION__, "❌ Ungültiger Token: $tokenUser", 0);
                $retryStep = [
                    'kind' => 'resp',
                    'req_id' => $reqId,
                    'code' => 200,
                    'msg' => 'result',
                    'msg_data' => [
                        'setup_action' => [
                            'type' => 'request_user_data',
                            'input' => [
                                'title' => ['en' => 'Invalid Token'],
                                'settings' => [
                                    [
                                        'id' => 'step2.token',
                                        'label' => [
                                            'en' => 'Invalid token. Please try again:',
                                            'de' => 'Ungültiger Token. Bitte erneut eingeben:'
                                        ],
                                        'field' => [
                                            'text' => [
                                                'value' => ''
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
                $this->PushToRemoteClient($retryStep, $clientIP, $clientPort);
                return;
            }

            $this->SendDebug(__FUNCTION__, "✅ Gültiger Token bestätigt", 0);

            $confirmationStep = [
                'kind' => 'resp',
                'req_id' => $reqId,
                'code' => 200,
                'msg' => 'result',
                'msg_data' => [
                    'setup_action' => [
                        'type' => 'request_user_data',
                        'input' => [
                            'title' => ['en' => 'Token Valid'],
                            'settings' => [
                                [
                                    'id' => 'step3.ready',
                                    'label' => [
                                        'en' => 'Token accepted. You can now proceed to device selection.',
                                        'de' => 'Token akzeptiert. Du kannst jetzt mit der Geräteauswahl fortfahren.'
                                    ],
                                    'field' => [
                                        'label' => [
                                            'value' => [
                                                'en' => 'Setup complete – Remote 3 will now request available devices.',
                                                'de' => 'Setup abgeschlossen – Remote 3 wird nun die verfügbaren Geräte abfragen.'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            $this->PushToRemoteClient($confirmationStep, $clientIP, $clientPort);

            // STEP 3: (future) device selection, currently just finish
        } elseif (isset($inputValues['step3.device_selection']) || isset($inputValues['step3.ready'])) {
            // Placeholder: Setup complete
            $this->SendDebug(__FUNCTION__, "✅ Geräteauswahl abgeschlossen", 0);
            $nextStep = [
                'kind' => 'resp',
                'req_id' => $reqId,
                'code' => 200,
                'msg' => 'result',
                'msg_data' => [
                    'setup_action' => [
                        'type' => 'setup_complete'
                    ]
                ]
            ];
            $this->PushToRemoteClient($nextStep, $clientIP, $clientPort);

        } else {
            $this->SendDebug(__FUNCTION__, '⚠️ Unbekannte oder fehlende Eingabewerte', 0);
            $this->SendResultOK($reqId, $clientIP, $clientPort);
        }
    }

    private function SendAvailableEntities(string $clientIP, int $clientPort, int $reqId): void
    {
        $entities = [];

        // Buttons auslesen
        $buttonMapping = json_decode($this->ReadPropertyString('button_mapping'), true);
        foreach ($buttonMapping as $entry) {
            if (isset($entry['name']) && isset($entry['script_id'])) {
                $entities[] = [
                    'entity_id' => 'button_' . $entry['script_id'],
                    'entity_type' => 'button',
                    'features' => [Entity_Button::FEATURE_PRESS],
                    'name' => [
                        'en' => $entry['name'],
                        'de' => $entry['name']
                    ]
                ];
            }
        }

        // Generische Entitäten
        $mappings = [
            'switch' => ['property' => 'switch_mapping', 'feature' => [Entity_Switch::FEATURE_ON_OFF]],
            'cover' => ['property' => 'cover_mapping', 'feature' => [Entity_Cover::FEATURE_OPEN, Entity_Cover::FEATURE_CLOSE, Entity_Cover::FEATURE_STOP, Entity_Cover::FEATURE_POSITION]],
            'sensor' => ['property' => 'sensor_mapping', 'feature' => []],
            'climate' => ['property' => 'climate_mapping', 'feature' => []],
            'light' => ['property' => 'light_mapping', 'feature' => []],
            'media_player' => ['property' => 'media_player_mapping', 'feature' => []]
        ];

        foreach ($mappings as $type => $info) {
            $mapping = json_decode($this->ReadPropertyString($info['property']), true);
            foreach ($mapping as $entry) {
                if (!isset($entry['name'])) {
                    continue;
                }

                $features = $info['feature'];
                $entityId = null;

                switch ($type) {
                    case 'light':
                        if (!isset($entry['instance_id']) && !isset($entry['switch_var_id'])) continue 2;
                        $entityId = 'light_' . $entry['instance_id'];
                        $features = [Entity_Light::FEATURE_ON_OFF];
                        if (!empty($entry['brightness_var_id'])) {
                            $features[] = Entity_Light::FEATURE_DIM;
                        }
                        if (!empty($entry['color_temp_var_id'])) {
                            $features[] = Entity_Light::FEATURE_COLOR_TEMP;
                        }
                        if (!empty($entry['color_var_id'])) {
                            $features[] = Entity_Light::FEATURE_COLOR;
                        }
                        break;

                    case 'cover':
                        if (!isset($entry['instance_id']) && !isset($entry['position_var_id'])) continue 2;
                        $entityId = 'cover_' . $entry['instance_id'];
                        $features = [
                            Entity_Cover::FEATURE_OPEN,
                            Entity_Cover::FEATURE_CLOSE,
                            Entity_Cover::FEATURE_STOP,
                            Entity_Cover::FEATURE_POSITION
                        ];
                        break;

                    case 'media_player':
                        if (!isset($entry['instance_id'])) continue 2;
                        $entityId = 'media_player_' . $entry['instance_id'];
                        if (isset($entry['features']) && is_array($entry['features'])) {
                            $features = $this->ExtractMediaPlayerFeatures($entry);
                        }
                        break;

                    default:
                        if (!isset($entry['instance_id'])) continue 2;
                        $entityId = $type . '_' . $entry['instance_id'];
                        break;
                }

                $entity = [
                    'entity_id' => $entityId,
                    'entity_type' => $type,
                    'features' => $features,
                    'name' => [
                        'en' => $entry['name'],
                        'de' => $entry['name']
                    ]
                ];

                if ($type === 'media_player' && isset($entry['device_class'])) {
                    $entity['device_class'] = $entry['device_class'];
                }

                $entities[] = $entity;
            }
        }

        $response = [
            'kind' => 'resp',
            'req_id' => $reqId,
            'code' => 200,
            'msg' => 'available_entities',
            'msg_data' => [
                'available_entities' => $entities
            ]
        ];

        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }

    /**
     * Extrahiert und bereinigt die MediaPlayer-Features aus einem Mapping-Eintrag.
     */
    private function ExtractMediaPlayerFeatures(array $entry): array
    {
        $features = [];

        if (isset($entry['features']) && is_array($entry['features'])) {
            foreach ($entry['features'] as $feature) {
                if (!isset($feature['feature_key']) || !isset($feature['var_id'])) {
                    continue;
                }

                $key = $feature['feature_key'];
                $varId = (int)$feature['var_id'];

                // Skip if varId is invalid
                if ($varId <= 0 || !@IPS_VariableExists($varId)) {
                    continue;
                }

                // Base feature always included
                $features[] = $key;

                // Special rules
                if ($key === 'mute') {
                    $features[] = 'unmute';
                }

                if ($key === 'symcon_control') {
                    $var = IPS_GetVariable($varId);
                    $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];
                    if ($profile && IPS_VariableProfileExists($profile)) {
                        $profileData = IPS_GetVariableProfile($profile);
                        foreach ($profileData['Associations'] as $assoc) {
                            $v = strtolower($assoc['Name']);
                            if (strpos($v, 'play') !== false) $features[] = Entity_Media_Player::FEATURE_PLAY_PAUSE;
                            if (strpos($v, 'stop') !== false) $features[] = Entity_Media_Player::FEATURE_STOP;
                            if (strpos($v, 'rewind') !== false) $features[] = Entity_Media_Player::FEATURE_REWIND;
                            if (strpos($v, 'forward') !== false) $features[] = Entity_Media_Player::FEATURE_FAST_FORWARD;
                            if (strpos($v, 'next') !== false) $features[] = Entity_Media_Player::FEATURE_NEXT;
                            if (strpos($v, 'prev') !== false || strpos($v, 'zurück') !== false) $features[] = Entity_Media_Player::FEATURE_PREVIOUS;
                        }
                    }
                }

                if ($key === 'symcon_commands') {
                    // todo: Profilbasiertes Mapping möglich
                    $features[] = Entity_Media_Player::FEATURE_INFO;
                    $features[] = Entity_Media_Player::FEATURE_MENU;
                    $features[] = Entity_Media_Player::FEATURE_HOME;
                    $features[] = Entity_Media_Player::FEATURE_GUIDE;
                }

                if ($key === 'symcon_dpad') {
                    $features[] = Entity_Media_Player::FEATURE_DPAD;
                }

                if ($key === 'symcon_numpad') {
                    $features[] = Entity_Media_Player::FEATURE_NUMPAD;
                }
            }
        }

        // Fallback legacy support
        if (empty($features) && !empty($entry['features_list']) && is_array($entry['features_list'])) {
            foreach ($entry['features_list'] as $featureEntry) {
                if (!empty($featureEntry['feature'])) {
                    $features[] = $featureEntry['feature'];
                }
            }
        }

        return array_values(array_unique($features));
    }


    private function SendEntityStates(string $clientIP, int $clientPort, int $reqId): void
    {
        $this->SendDebug(__FUNCTION__, "▶️ Starte SendEntityStates", 0);
        $entities = [];
        // Switches
        $switchMapping = json_decode($this->ReadPropertyString('switch_mapping'), true);
        if (is_array($switchMapping)) {
            // $this->SendDebug(__FUNCTION__, "🔍 Verarbeite Switch-Mapping...", 0);
            foreach ($switchMapping as $entry) {
                if (isset($entry['var_id']) && is_numeric($entry['var_id'])) {
                    $varId = (int)$entry['var_id'];
                    $state = @GetValue($varId);
                    $stateStr = ($state) ? 'ON' : 'OFF';
                    $entities[] = [
                        'entity_id' => 'switch_' . $varId,
                        'entity_type' => 'switch',
                        'attributes' => [
                            Entity_Switch::ATTR_STATE => $stateStr
                        ]
                    ];
                }
            }
        }

        // Lights
        $lightMapping = json_decode($this->ReadPropertyString('light_mapping'), true);
        if (is_array($lightMapping)) {
            // $this->SendDebug(__FUNCTION__, "🔍 Verarbeite Light-Mapping...", 0);
            foreach ($lightMapping as $entry) {
                if (
                    isset($entry['switch_var_id']) && is_numeric($entry['switch_var_id']) &&
                    isset($entry['instance_id']) && !empty($entry['instance_id'])
                ) {
                    $varId = (int)$entry['switch_var_id'];
                    $state = @GetValue($varId);
                    $stateStr = ($state) ? 'ON' : 'OFF';
                    $attributes = [Entity_Light::ATTR_STATE => $stateStr];

                    if (!empty($entry['brightness_var_id']) && @IPS_VariableExists($entry['brightness_var_id'])) {
                        $attributes[Entity_Light::ATTR_BRIGHTNESS] = $this->ConvertBrightnessToRemote($entry['brightness_var_id']);
                    }
                    if (!empty($entry['color_temp_var_id']) && @IPS_VariableExists($entry['color_temp_var_id'])) {
                        $attributes[Entity_Light::ATTR_COLOR_TEMPERATURE] = @GetValue($entry['color_temp_var_id']);
                    }
                    if (!empty($entry['color_var_id']) && @IPS_VariableExists($entry['color_var_id'])) {
                        $hex = @GetValue($entry['color_var_id']);
                        $hs = $this->ConvertHexColorToHueSaturation((int)$hex);
                        $attributes[Entity_Light::ATTR_HUE] = $hs['hue'];
                        $attributes[Entity_Light::ATTR_SATURATION] = $hs['saturation'];
                    }

                    $entities[] = [
                        'entity_id' => 'light_' . $entry['instance_id'],
                        'entity_type' => 'light',
                        'attributes' => $attributes
                    ];
                }
            }
        }

        // Covers
        $coverMapping = json_decode($this->ReadPropertyString('cover_mapping'), true);
        if (is_array($coverMapping)) {
            // $this->SendDebug(__FUNCTION__, "🔍 Verarbeite Cover-Mapping...", 0);
            foreach ($coverMapping as $entry) {
                if (
                    isset($entry['position_var_id']) && is_numeric($entry['position_var_id']) &&
                    isset($entry['instance_id']) && !empty($entry['instance_id'])
                ) {
                    $varId = (int)$entry['position_var_id'];
                    $position = @GetValue($varId);
                    $stateStr = 'SETTING';
                    $entities[] = [
                        'entity_id' => 'cover_' . $entry['instance_id'],
                        'entity_type' => 'cover',
                        'attributes' => [
                            Entity_Cover::ATTR_STATE => $stateStr,
                            Entity_Cover::ATTR_POSITION => $position
                        ]
                    ];
                }
            }
        }

        // Climates
        $climateMapping = json_decode($this->ReadPropertyString('climate_mapping'), true);
        if (is_array($climateMapping)) {
            $this->SendDebug(__FUNCTION__, "🔍 Verarbeite Climate-Mapping...", 0);
            foreach ($climateMapping as $entry) {
                // Robustere Prüfung und ausführliche Debug-Ausgaben
                if (!isset($entry['instance_id'])) {
                    $this->SendDebug(__FUNCTION__, "⚠️ Eintrag ohne instance_id übersprungen: " . json_encode($entry), 0);
                    continue;
                }

                try {
                    $this->SendDebug(__FUNCTION__, "➡️ Climate-Instanz: " . $entry['instance_id'], 0);

                    if (!isset($entry['status_var_id']) || !is_numeric($entry['status_var_id'])) {
                        $this->SendDebug(__FUNCTION__, "⚠️ Kein status_var_id für climate_" . $entry['instance_id'], 0);
                        continue;
                    }

                    $attributes = [];
                    $state = 'OFF';

                    if (!empty($entry['mode_var_id']) && IPS_VariableExists($entry['mode_var_id'])) {
                        $value = GetValue($entry['mode_var_id']);
                        $label = $this->GetProfileValueLabel($entry['mode_var_id'], $value);
                        $allowedStates = ['HEAT', 'COOL', 'HEAT_COOL', 'FAN', 'AUTO', 'OFF'];
                        $this->SendDebug(__FUNCTION__, "🌡️ Modus-Wert ($value) → Label: $label", 0);
                        if (in_array($label, $allowedStates)) {
                            $state = $label;
                        }
                    }

                    $attributes['state'] = $state;

                    if (!empty($entry['target_temp_var_id']) && IPS_VariableExists($entry['target_temp_var_id'])) {
                        $attributes[Entity_Climate::ATTR_TARGET_TEMPERATURE] = GetValue($entry['target_temp_var_id']);
                    }

                    if (!empty($entry['current_temp_var_id']) && IPS_VariableExists($entry['current_temp_var_id'])) {
                        $attributes[Entity_Climate::ATTR_CURRENT_TEMPERATURE] = GetValue($entry['current_temp_var_id']);
                    }

                    if (!empty($entry['mode_var_id']) && IPS_VariableExists($entry['mode_var_id'])) {
                        $attributes[Entity_Climate::ATTR_HVAC_MODE] = "COOL"; // statisch für Test
                    }

                    $entities[] = [
                        'entity_id' => 'climate_' . $entry['instance_id'],
                        'entity_type' => 'climate',
                        'attributes' => $attributes
                    ];
                } catch (Throwable $e) {
                    $this->SendDebug(__FUNCTION__, "❌ Fehler bei Climate-Instanz {$entry['instance_id']}: " . $e->getMessage(), 0);
                    continue;
                }
            }
        }

        // Media Player
        $mediaMapping = json_decode($this->ReadPropertyString('media_player_mapping'), true);
        if (is_array($mediaMapping)) {
            $this->SendDebug(__FUNCTION__, "🔍 Verarbeite Media Player-Mapping...", 0);
            foreach ($mediaMapping as $entry) {
                if (!isset($entry['instance_id']) || !isset($entry['name']) || !isset($entry['features']) || !is_array($entry['features'])) {
                    $this->SendDebug(__FUNCTION__, "⚠️ Ungültiger Eintrag im Media Mapping übersprungen: " . json_encode($entry), 0);
                    continue;
                }

                $attributes = [];
                $entityId = 'mediaplayer_' . $entry['instance_id'];
                $stateSet = false;

                foreach ($entry['features'] as $feature) {
                    if (!isset($feature['feature_key']) || !isset($feature['var_id']) || !$feature['var_id']) {
                        continue;
                    }

                    $key = $feature['feature_key'];
                    $varId = (int)$feature['var_id'];

                    if (!IPS_VariableExists($varId)) {
                        continue;
                    }

                    $value = @GetValue($varId);

                    switch ($key) {
                        case 'on_off':
                            $attributes[Entity_Media_Player::ATTR_STATE] = $value ? 'ON' : 'OFF';
                            $stateSet = true;
                            break;
                        case 'symcon_control':
                            $attributes[Entity_Media_Player::ATTR_STATE] = $this->GetMediaPlayerStateFromControlVariable($varId);
                            $stateSet = true;
                            break;
                        case 'volume':
                            $attributes[Entity_Media_Player::ATTR_VOLUME] = (float)$value;
                            break;
                        case 'muted':
                            $attributes[Entity_Media_Player::ATTR_MUTED] = (bool)$value;
                            break;
                        case 'repeat':
                            $attributes[Entity_Media_Player::ATTR_REPEAT] = (bool)$value;
                            break;
                        case 'shuffle':
                            $attributes[Entity_Media_Player::ATTR_SHUFFLE] = (bool)$value;
                            break;
                        case 'media_title':
                            $attributes[Entity_Media_Player::ATTR_MEDIA_TITLE] = $value;
                            break;
                        case 'media_artist':
                            $attributes[Entity_Media_Player::ATTR_MEDIA_ARTIST] = $value;
                            break;
                        case 'media_album':
                            $attributes[Entity_Media_Player::ATTR_MEDIA_ALBUM] = $value;
                            break;
                        case 'media_duration':
                            $attributes[Entity_Media_Player::ATTR_MEDIA_DURATION] = $this->ConvertTimeStringToSeconds($value);
                            break;
                        case 'media_position':
                            $attributes[Entity_Media_Player::ATTR_MEDIA_POSITION] = $this->ConvertTimeStringToSeconds($value);
                            break;
                        case 'media_image_url':
                            $attributes[Entity_Media_Player::ATTR_MEDIA_IMAGE_URL] = $value;
                            break;
                        case 'media_type':
                            $attributes[Entity_Media_Player::ATTR_MEDIA_TYPE] = $value;
                            break;
                        case 'source':
                            $attributes[Entity_Media_Player::ATTR_SOURCE] = $value;
                            break;
                        case 'sound_mode':
                            $attributes[Entity_Media_Player::ATTR_SOUND_MODE] = $value;
                            break;
                    }
                }

                if (!$stateSet) {
                    $attributes[Entity_Media_Player::ATTR_STATE] = 'ON'; // Fallback-Zustand
                }

                $entities[] = [
                    'entity_id' => $entityId,
                    'entity_type' => 'media_player',
                    'attributes' => $attributes
                ];
            }
        }

        $response = [
            'kind' => 'resp',
            'req_id' => $reqId,
            'code' => 200,
            'msg' => 'entity_states',
            'msg_data' => $entities
        ];
        // $this->SendDebug(__FUNCTION__, "📤 Sende Antwort an RemoteClient (req_id: $reqId)", 0);
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
        // $this->SendDebug(__FUNCTION__, "✅ SendEntityStates abgeschlossen", 0);
    }

    /**
     * Gibt das Label (Name) einer Association anhand des aktuellen Wertes zurück.
     *
     * @param int $varId Die ID der Variablen
     * @param mixed $value Der aktuelle Wert
     * @return string       Das zugehörige Label (Großbuchstaben), oder leer bei Fehler
     */
    private function GetProfileValueLabel(int $varId, $value): string
    {
        if (!IPS_VariableExists($varId)) {
            return '';
        }

        $var = IPS_GetVariable($varId);
        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

        if (!$profile || !IPS_VariableProfileExists($profile)) {
            return '';
        }

        $profileData = IPS_GetVariableProfile($profile);
        foreach ($profileData['Associations'] as $assoc) {
            if ((string)$assoc['Value'] === (string)$value) {
                return strtoupper(trim($assoc['Name']));
            }
        }

        return '';
    }

    private function ConvertTimeStringToSeconds($input): float
    {
        if (!is_string($input)) {
            return 0;
        }

        $parts = explode(':', $input);
        $parts = array_reverse($parts);
        $seconds = 0;

        foreach ($parts as $index => $value) {
            if (!is_numeric($value)) {
                return 0; // ungültig, z.B. 'Pause'
            }
            $seconds += intval($value) * pow(60, $index);
        }

        return (float)$seconds;
    }

    /**
     * Interpretiert den aktuellen Status eines MediaPlayers anhand der Control-Variable und deren Profil.
     *
     * @param int $varId
     * @return string
     */
    private function GetMediaPlayerStateFromControlVariable(int $varId): string
    {
        if (!IPS_VariableExists($varId)) {
            return 'UNKNOWN';
        }

        $value = @GetValue($varId);
        $var = IPS_GetVariable($varId);
        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

        if (!$profile || !IPS_VariableProfileExists($profile)) {
            return 'UNKNOWN';
        }

        $profileData = IPS_GetVariableProfile($profile);
        foreach ($profileData['Associations'] as $assoc) {
            if ((string)$assoc['Value'] === (string)$value) {
                $label = strtolower($assoc['Name']);
                if (strpos($label, 'play') !== false) {
                    return 'PLAYING';
                }
                if (strpos($label, 'pause') !== false) {
                    return 'PAUSED';
                }
                if (strpos($label, 'stop') !== false) {
                    return 'OFF';
                }
                if (strpos($label, 'standby') !== false) {
                    return 'STANDBY';
                }
                if (strpos($label, 'buffer') !== false) {
                    return 'BUFFERING';
                }
            }
        }

        return 'ON'; // fallback wenn kein Mapping passt
    }


    private function SendResultOK(int $id, string $clientIP, int $clientPort): void
    {
        $response = [
            'kind' => 'resp',
            'msg' => 'result',
            'req_id' => $id,
            'code' => 200,
            'msg_data' => new stdClass()
        ];
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }

    private function NotifyDriverSetupComplete(string $clientIP, int $clientPort): void
    {
        $event = [
            'kind' => 'event',
            'msg' => 'driver_setup_change',
            'msg_data' => [
                'event_type' => 'STOP',
                'state' => 'OK'
            ],
            'cat' => 'DEVICE'
        ];
        $this->PushToRemoteClient($event, $clientIP, $clientPort);
    }

    private function sendAuthFailure(string $clientIP, int $clientPort): void
    {
        $response = [
            'kind' => 'resp',
            'req_id' => 0,
            'code' => 401,
            'msg' => 'auth_required',
            'msg_data' => [
                'message' => 'Unauthorized – Invalid or missing token'
            ]
        ];
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }

    /**
     * Sends driver version information to the remote client.
     *
     * @param string $clientIP
     * @param int $clientPort
     * @param int $reqId
     */
    private function SendDriverVersion(string $clientIP, int $clientPort, int $reqId): void
    {
        $response = [
            'kind' => 'resp',
            'msg' => 'driver_version',
            'req_id' => $reqId,
            'code' => 200,
            'msg_data' => [
                'name' => 'Symcon Integration Driver',
                'version' => [
                    'api' => self::Unfolded_Circle_API_Version,
                    'driver' => self::Unfolded_Circle_Driver_Version
                ]
            ]
        ];
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }

    private function PushToRemoteClient(array $data, string $clientIP, int $clientPort): void
    {
        // Encode message to JSON
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->SendDebug(__FUNCTION__, '❌ JSON Encoding Error (message): ' . json_last_error_msg(), 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '📤 Response to ' . $clientIP . ': ' . $json, 0);

        // Pack into a WebSocket frame (binary)
        $packed = WebSocketUtils::PackData($json);
        $packedHex = bin2hex($packed);
        $this->SendDebug(__FUNCTION__, '📤 Packed Data (hex): ' . $packedHex, 0);

        // IMPORTANT: Never put binary into JSON. Send HEX and let the parent (Server Socket) convert back to binary.
        $sendPayload = [
            'DataID' => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', // Server Socket
            'ClientIP' => $clientIP,
            'ClientPort' => $clientPort,
            'Type' => 0,
            'Buffer' => $packedHex
        ];

        $jsonPayload = json_encode($sendPayload, JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            $this->SendDebug(__FUNCTION__, '❌ JSON Encoding Error (envelope): ' . json_last_error_msg(), 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '📤 Final JSON Payload: ' . $jsonPayload, 0);
        $this->SendDataToParent($jsonPayload);
    }

    public function TestPushToRemote()
    {
        $testMessage = [
            'kind' => 'resp',
            'req_id' => 999,
            'msg' => 'test_echo',
            'msg_data' => [
                'text' => 'Dies ist ein Testpaket von Symcon'
            ]
        ];

        // Beispielwerte für ClientIP und Port – bitte im Skript korrekt setzen
        $clientIP = '192.168.55.125';
        $clientPort = 9988;

        $this->PushToRemoteClient($testMessage, $clientIP, $clientPort);
    }

    /**
     * Sende rohe Strings (z.B. HTTP-Header, WebSocket-Frames) direkt an den Client.
     * IMPORTANT: Never put binary / raw frame bytes into JSON. Always send HEX and let the parent (Server Socket) convert back to binary.
     */
    private function PushRawToRemoteClient(string $data, string $clientIP, int $clientPort): void
    {
        // IMPORTANT: Never put binary / raw frame bytes into JSON. Always send HEX and let the parent (Server Socket) convert back to binary.
        $this->SendDebug(__FUNCTION__, '📤 Raw response (string) to ' . $clientIP . ': ' . $data, 0);

        // Convert to bytes as-is and send HEX
        $hex = bin2hex($data);
        $this->SendDebug(__FUNCTION__, '📤 Raw response (hex,len=' . strlen($hex) . '): ' . $hex, 0);

        $payload = [
            'DataID' => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', // Server Socket
            'ClientIP' => $clientIP,
            'ClientPort' => $clientPort,
            'Type' => 0,
            'Buffer' => $hex
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->SendDebug(__FUNCTION__, '❌ JSON Encoding Error (raw envelope): ' . json_last_error_msg(), 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '📤 Raw envelope to Server Socket: ' . $json, 0);
        $this->SendDataToParent($json);
    }

    private function PushPongToRemoteClient(string $data, string $clientIP, int $clientPort): void
    {
        // IMPORTANT: Never put binary / raw frame bytes into JSON. Always send HEX and let the parent (Server Socket) convert back to binary.
        $bytes = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        $hex = bin2hex($bytes);

        $payload = json_encode([
            'DataID' => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', // Server Socket
            'ClientIP' => $clientIP,
            'ClientPort' => $clientPort,
            'Type' => 0,
            'Buffer' => $hex
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            $this->SendDebug(__FUNCTION__, '❌ JSON Encoding Error (pong envelope): ' . json_last_error_msg(), 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '📤 PONG (hex,len=' . strlen($hex) . '): ' . $hex, 0);
        $this->SendDebugExtended(__FUNCTION__, "PONG", 0);
        $this->SendDataToParent($payload);
    }

    protected function SendPayloadToChildren($data)
    {
        // An Childs weiterleiten
        $payload = json_encode([
            'DataID' => '{34A21C2C-646B-1014-D032-DF7E7A88B419}',
            'Buffer' => $data
        ]);
        $this->SendDataToChildren($payload);
    }

    private function GetSymconFirstName(): string
    {
        $email = @IPS_GetLicensee();
        if (empty($email) || strpos($email, '@') === false) {
            return 'Symcon';
        }
        $username = explode('@', $email)[0];

        // Trenne an Punkt, Unterstrich oder Bindestrich
        $parts = preg_split('/[\._\-]/', $username);

        // Nimm den ersten sinnvollen Teil
        $first = $parts[0] ?? 'Symcon';

        // Großschreibung des ersten Buchstabens
        $first = ucfirst(strtolower($first));

        return $first;
    }

    private function RegisterMdnsService()
    {
        $this->SendDebug(__FUNCTION__, '🔧 DNS-SD Dienst registrieren', 0);

        $mdnsID = @IPS_GetInstanceListByModuleID('{780B2D48-916C-4D59-AD35-5A429B2355A5}')[0] ?? 0;
        if ($mdnsID === 0) {
            $this->SendDebug(__FUNCTION__, '⚠️ Keine DNS-SD Control Instanz gefunden!', 0);
            return;
        }

        $entries = json_decode(IPS_GetProperty($mdnsID, 'Services'), true) ?? [];

        $serviceName = 'Symcon';
        $serviceType = '_uc-integration._tcp';
        $servicePort = self::DEFAULT_WS_PORT;

        // Prevent duplicates:
        // If there is already any _uc-integration._tcp service on the same port, do NOT add another one.
        // Reason: Users may already have created/edited the entry manually in DNS-SD (as in the screenshot),
        // and adding a second entry on the same port is confusing for discovery.
        $existingOnPort = array_filter($entries, function ($e) use ($serviceType, $servicePort) {
            $regType = $e['RegType'] ?? '';
            $port = (int)($e['Port'] ?? 0);
            return ($regType === $serviceType) && ($port === $servicePort);
        });

        if (!empty($existingOnPort)) {
            $names = array_map(fn($e) => ($e['Name'] ?? '?') . '@' . ($e['Port'] ?? '?'), array_values($existingOnPort));
            $this->SendDebug(__FUNCTION__, 'ℹ️ mDNS-Eintrag existiert bereits (RegType=' . $serviceType . ', Port=' . $servicePort . '): ' . json_encode($names) . ' – kein weiterer Eintrag wird hinzugefügt.', 0);
            return;
        }

        $first = $this->GetSymconFirstName();

        $newEntry = [
            'Name' => $serviceName,
            'RegType' => $serviceType,
            'Domain' => '',
            'Host' => '',
            'Port' => $servicePort,
            'TXTRecords' => [
                // Keep TXT minimal and stable. User can still edit it in the DNS-SD instance UI if desired.
                ['Value' => 'name=Symcon von ' . $first],
                ['Value' => 'ver=' . self::Unfolded_Circle_Driver_Version],
                ['Value' => 'developer=Fonzo'],
                ['Value' => 'pwd=true']
            ]
        ];

        $entries[] = $newEntry;

        IPS_SetProperty($mdnsID, 'Services', json_encode(array_values($entries)));
        IPS_ApplyChanges($mdnsID);

        $this->SendDebug(__FUNCTION__, '✅ mDNS-Eintrag hinzugefügt: ' . json_encode($newEntry), 0);
    }

    private function UnregisterMdnsService()
    {
        $mdnsID = @IPS_GetInstanceListByModuleID('{780B2D48-916C-4D59-AD35-5A429B2355A5}')[0] ?? 0;
        if ($mdnsID === 0) {
            return;
        }

        $entries = json_decode(IPS_GetProperty($mdnsID, 'Services'), true) ?? [];
        $filtered = array_filter($entries, function ($entry) {
            return $entry['RegType'] !== '_uc-integration._tcp' || $entry['Name'] !== 'Symcon';
        });

        IPS_SetProperty($mdnsID, 'Services', json_encode(array_values($filtered)));
        IPS_ApplyChanges($mdnsID);
    }

    /**
     * Prüft, ob das Symcon-Icon bereits auf Remote 3 existiert.
     *
     * @param string $apiKey
     * @param string $ip
     * @return bool
     */
    private function RemoteIconExists(string $apiKey, string $ip): bool
    {
        $url = "http://{$ip}/api/resources/Icon?page=1&limit=50";
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/json',
                    "Authorization: $apiKey"
                ]
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler beim Abruf der Icons von Remote 3', 0);
            return false;
        }

        $icons = json_decode($response, true);
        if (!is_array($icons)) {
            $this->SendDebug(__FUNCTION__, '❌ Ungültige JSON-Antwort erhalten', 0);
            return false;
        }

        foreach ($icons as $icon) {
            if (($icon['id'] ?? '') === 'symcon_icon.png') {
                $this->SendDebug(__FUNCTION__, '✅ Symcon-Icon bereits vorhanden', 0);
                return true;
            }
        }

        $this->SendDebug(__FUNCTION__, 'ℹ️ Symcon-Icon nicht vorhanden', 0);
        return false;
    }

    /**
     * Lädt das Symcon-Icon zu Remote 3 hoch.
     *
     * @param string $apiKey
     * @param string $ip
     */
    private function UploadSymconIcon(string $apiKey, string $ip): void
    {
        $iconPath = __DIR__ . '/../libs/symcon_icon.png';

        if (!file_exists($iconPath)) {
            $this->SendDebug(__FUNCTION__, "❌ Icon-Datei nicht gefunden: $iconPath", 0);
            return;
        }

        $boundary = uniqid();
        $delimiter = '-------------' . $boundary;

        $fileContents = file_get_contents($iconPath);
        $filename = basename($iconPath);

        $data = "--$delimiter\r\n";
        $data .= "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n";
        $data .= "Content-Type: image/png\r\n\r\n";
        $data .= $fileContents . "\r\n";
        $data .= "--$delimiter--\r\n";

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => [
                    "Content-Type: multipart/form-data; boundary=$delimiter",
                    'Accept: application/json',
                    "Authorization: $apiKey"
                ],
                'content' => $data
            ]
        ];

        $context = stream_context_create($options);
        $url = "http://{$ip}/api/resources/Icon";
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler beim Hochladen des Icons', 0);
        } else {
            $this->SendDebug(__FUNCTION__, '✅ Icon erfolgreich hochgeladen: ' . $response, 0);
        }
    }

    /**
     * Prüft und lädt das Symcon-Icon hoch, falls es nicht existiert.
     */
    private function CheckAndUploadSymconIcon(): void
    {
        $remotes = json_decode($this->ReadAttributeString('remote_cores'), true);
        if (!is_array($remotes)) {
            $this->SendDebug(__FUNCTION__, '⚠️ Keine gültige Remote Core Liste gefunden', 0);
            return;
        }

        foreach ($remotes as $remote) {
            $ip = $remote['host'];
            $apiKey = $remote['api_key'];
            $this->SendDebug(__FUNCTION__, "🔍 Prüfe Icon für Remote {$remote['name']} @ $ip", 0);

            if (!$this->RemoteIconExists($apiKey, $ip)) {
                $this->UploadSymconIcon($apiKey, $ip);
            }
        }
    }

    /**
     * Aktualisiert die Liste der Remote Core Instanzen und deren Daten.
     */
    public function RefreshRemoteCores()
    {
        $coreInstances = IPS_GetInstanceListByModuleID('{C810D534-2395-7C43-D0BE-6DEC069B2516}');
        $remotes = [];

        foreach ($coreInstances as $id) {
            $apiKey = @UCR_GetApiKey($id);
            if (empty($apiKey)) {
                $this->SendDebug(__FUNCTION__, "⚠️ Kein API-Key für Instanz $id gefunden", 0);
                continue;
            }

            $remote = [
                'instance_id' => $id,
                'api_key' => $apiKey,
                'name' => IPS_GetProperty($id, 'name'),
                'hostname' => IPS_GetProperty($id, 'hostname'),
                'host' => IPS_GetProperty($id, 'host'),
                'remote_id' => IPS_GetProperty($id, 'remote_id'),
                'model' => IPS_GetProperty($id, 'model'),
                'version' => IPS_GetProperty($id, 'version'),
                'ver_api' => IPS_GetProperty($id, 'ver_api'),
                'https_port' => IPS_GetProperty($id, 'https_port')
            ];

            $remotes[] = $remote;
        }

        $this->WriteAttributeString('remote_cores', json_encode($remotes));
        $this->SendDebug(__FUNCTION__, '✅ Remote Cores aktualisiert: ' . json_encode($remotes), 0);
        return $remotes;
    }

    private function HandleEntityCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityType = $msgData['entity_type'] ?? '';

        switch ($entityType) {
            case 'button':
                $this->HandleButtonCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            case 'climate':
                $this->HandleClimateCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            case 'cover':
                $this->HandleCoverCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            case 'ir_emitter':
                $this->HandleIREmitterCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            case 'light':
                $this->HandleLightCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            case 'media_player':
                $this->HandleMediaPlayerCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            case 'remote':
                $this->HandleRemoteCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            case 'switch':
                $this->HandleSwitchCommand($msgData, $clientIP, $clientPort, $reqId);
                break;

            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannter entity_type: $entityType", 0);
                break;
        }
    }

    private function HandleButtonCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';

        $this->SendDebug(__FUNCTION__, "🔘 Button-Command: $cmdId für $entityId", 0);
        // Semaphore Lock hinzufügen (analog zu HandleSwitchCommand)
        if (preg_match('/_(\d+)$/', $entityId, $match)) {
            $objectId = (int)$match[1];
            $lockName = 'UCR_' . $objectId;
        } else {
            $this->SendDebug(__FUNCTION__, "❌ Konnte Objekt-ID aus Entity-ID nicht extrahieren: $entityId", 0);
            return;
        }

        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->SendDebug(__FUNCTION__, "❌ Semaphore '$lockName' konnte nicht gesetzt werden (Timeout)", 0);
            return;
        }

        if ($cmdId === 'push') {
            $mapping = json_decode($this->ReadPropertyString('button_mapping'), true);
            foreach ($mapping as $entry) {
                if ('button_' . $entry['script_id'] === $entityId) {
                    if (IPS_ScriptExists($entry['script_id'])) {
                        IPS_RunScript($entry['script_id']);
                    } else {
                        $this->SendDebug(__FUNCTION__, "⚠️ Skript-ID {$entry['script_id']} existiert nicht", 0);
                    }
                    $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
                    IPS_SemaphoreLeave($lockName);
                    return;
                }
            }
            $this->SendDebug(__FUNCTION__, "⚠️ Kein passender Button gefunden für Entity-ID $entityId", 0);
            IPS_SemaphoreLeave($lockName);
        } else {
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function HandleClimateCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';

        $this->SendDebug(__FUNCTION__, "🌡️ Climate-Command: $cmdId für $entityId", 0);

        if (preg_match('/_(\d+)$/', $entityId, $match)) {
            $objectId = (int)$match[1];
            $lockName = 'UCR_' . $objectId;
        } else {
            $this->SendDebug(__FUNCTION__, "❌ Konnte Objekt-ID aus Entity-ID nicht extrahieren: $entityId", 0);
            return;
        }

        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->SendDebug(__FUNCTION__, "❌ Semaphore '$lockName' konnte nicht gesetzt werden (Timeout)", 0);
            return;
        }

        $climateMapping = json_decode($this->ReadPropertyString('climate_mapping'), true);
        $status_var_id = $current_temp_var_id = $target_temp_var_id = $mode_var_id = null;

        foreach ($climateMapping as $entry) {
            if ('climate_' . $entry['instance_id'] === $entityId) {
                $status_var_id = $entry['status_var_id'] ?? null;
                $current_temp_var_id = $entry['current_temp_var_id'] ?? null;
                $target_temp_var_id = $entry['target_temp_var_id'] ?? null;
                $mode_var_id = $entry['mode_var_id'] ?? null;
                break;
            }
        }

        if (!$status_var_id) {
            $this->SendDebug(__FUNCTION__, "⚠️ Kein passender Climate-Eintrag gefunden für Entity-ID $entityId", 0);
            IPS_SemaphoreLeave($lockName);
            return;
        }

        $attributes = [];

        switch ($cmdId) {
            case 'on':
                if ($status_var_id) {
                    RequestAction($status_var_id, true);
                    $attributes['state'] = 'ON';
                }
                break;
            case 'off':
                if ($status_var_id) {
                    RequestAction($status_var_id, false);
                    $attributes['state'] = 'OFF';
                }
                break;
            case 'target_temperature':
                if (isset($params['target_temperature']) && $target_temp_var_id) {
                    RequestAction($target_temp_var_id, (float)$params['target_temperature']);
                    $attributes['temperature'] = (float)$params['target_temperature'];
                }
                break;
            case 'hvac_mode':
                if (isset($params['hvac_mode']) && $mode_var_id) {
                    RequestAction($mode_var_id, $params['hvac_mode']);
                    $attributes['hvac_mode'] = $params['hvac_mode'];
                }
                break;
            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannter Climate-Command: $cmdId", 0);
                IPS_SemaphoreLeave($lockName);
                return;
        }

        if (!empty($attributes)) {
            $this->SendEntityChange($entityId, 'climate', $attributes);
        }
        $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
        IPS_SemaphoreLeave($lockName);
    }

    private function HandleCoverCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';
        $params = $msgData['params'] ?? [];

        $this->SendDebug(__FUNCTION__, "🪟 Cover-Command: $cmdId für $entityId", 0);

        if (preg_match('/_(\d+)$/', $entityId, $match)) {
            $objectId = (int)$match[1];
            $lockName = 'UCR_' . $objectId;
        } else {
            $this->SendDebug(__FUNCTION__, "❌ Konnte Objekt-ID aus Entity-ID nicht extrahieren: $entityId", 0);
            return;
        }

        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->SendDebug(__FUNCTION__, "❌ Semaphore '$lockName' konnte nicht gesetzt werden (Timeout)", 0);
            return;
        }

        $mapping = json_decode($this->ReadPropertyString('cover_mapping'), true);
        $positionVar = $controlVar = null;
        $entryFound = null;
        foreach ($mapping as $entry) {
            if ('cover_' . $entry['instance_id'] === $entityId) {
                $positionVar = $entry['position_var_id'] ?? null;
                $controlVar = $entry['control_var_id'] ?? null;
                $entryFound = $entry;
                break;
            }
        }

        if (!$positionVar && !$controlVar) {
            $this->SendDebug(__FUNCTION__, "⚠️ Kein passender Cover-Eintrag gefunden für Entity-ID $entityId", 0);
            IPS_SemaphoreLeave($lockName);
            return;
        }

        $attributes = [];

        switch ($cmdId) {
            case 'open':
                if ($controlVar) {
                    $this->SendDebug(__FUNCTION__, "🔧 Versuche zu öffnen: controlVar=$controlVar", 0);
                    if (IPS_VariableExists($controlVar)) {
                        RequestAction($controlVar, 0); // 0 = open
                        $this->SendDebug(__FUNCTION__, "✅ Öffne Cover (RequestAction $controlVar mit 0)", 0);
                        $attributes['state'] = 'OPEN';
                    } else {
                        $this->SendDebug(__FUNCTION__, "❌ Variable für open existiert nicht: ID=$controlVar", 0);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, "⚠️ controlVar für open fehlt", 0);
                }
                break;
            case 'close':
                if ($controlVar) {
                    $this->SendDebug(__FUNCTION__, "🔧 Versuche zu schließen: controlVar=$controlVar", 0);
                    if (IPS_VariableExists($controlVar)) {
                        RequestAction($controlVar, 2); // 2 = close
                        $this->SendDebug(__FUNCTION__, "✅ Schließe Cover (RequestAction $controlVar mit 2)", 0);
                        $attributes['state'] = 'CLOSED';
                    } else {
                        $this->SendDebug(__FUNCTION__, "❌ Variable für close existiert nicht: ID=$controlVar", 0);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, "⚠️ controlVar für close fehlt", 0);
                }
                break;
            case 'stop':
                if ($controlVar) {
                    $this->SendDebug(__FUNCTION__, "🔧 Versuche zu stoppen: controlVar=$controlVar", 0);
                    if (IPS_VariableExists($controlVar)) {
                        RequestAction($controlVar, 1); // 1 = stop
                        $this->SendDebug(__FUNCTION__, "✅ Stoppe Cover (RequestAction $controlVar mit 1)", 0);
                        $attributes['state'] = 'STOPPED';
                    } else {
                        $this->SendDebug(__FUNCTION__, "❌ Variable für stop existiert nicht: ID=$controlVar", 0);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, "⚠️ controlVar für stop fehlt", 0);
                }
                break;
            case 'position':
                if (isset($params['position']) && $positionVar) {
                    $this->SendDebug(__FUNCTION__, "🔧 Zielposition erhalten: " . $params['position'], 0);
                    if (IPS_VariableExists($positionVar)) {
                        RequestAction($positionVar, (int)$params['position']);
                        $this->SendDebug(__FUNCTION__, "✅ Position gesetzt auf " . $params['position'], 0);
                        $attributes['state'] = 'SETTING';
                        $attributes['position'] = (int)$params['position'];
                    } else {
                        $this->SendDebug(__FUNCTION__, "❌ Variable für Position existiert nicht: ID=$positionVar", 0);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, "⚠️ Position-Parameter oder ID fehlt", 0);
                }
                break;
            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannter Cover-Command: $cmdId", 0);
                IPS_SemaphoreLeave($lockName);
                return;
        }

        if (!empty($attributes)) {
            $this->SendEntityChange($entityId, 'cover', $attributes);
        }
        $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
        IPS_SemaphoreLeave($lockName);
    }

    private function HandleIREmitterCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';

        $this->SendDebug(__FUNCTION__, "🎵 MediaPlayer-Command: $cmdId für $entityId", 0);
        // TODO: Ansteuerung einer Climate-Instanz basierend auf cmdId
        $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
    }

    private function HandleLightCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';
        $params = $msgData['params'] ?? [];

        if (preg_match('/_(\d+)$/', $entityId, $match)) {
            $objectId = (int)$match[1];
            $lockName = 'UCR_' . $objectId;
        } else {
            $this->SendDebug(__FUNCTION__, "❌ Konnte Objekt-ID aus Entity-ID nicht extrahieren: $entityId", 0);
            return;
        }

        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->SendDebug(__FUNCTION__, "❌ Semaphore '$lockName' konnte nicht gesetzt werden (Timeout)", 0);
            return;
        }

        if (!empty($params)) {
            $this->SendDebug(__FUNCTION__, "💡 Light-Command: $cmdId für $entityId (mit Parametern: " . json_encode($params) . ")", 0);
        } else {
            $this->SendDebug(__FUNCTION__, "💡 Light-Command: $cmdId für $entityId", 0);
        }

        $lightMapping = json_decode($this->ReadPropertyString('light_mapping'), true);
        $switch_var_id = $brightness_var_id = $color_var_id = $color_temp_var_id = null;

        foreach ($lightMapping as $entry) {
            if ('light_' . $entry['instance_id'] === $entityId) {
                $switch_var_id = $entry['switch_var_id'] ?? null;
                $brightness_var_id = $entry['brightness_var_id'] ?? null;
                $color_var_id = $entry['color_var_id'] ?? null;
                $color_temp_var_id = $entry['color_temp_var_id'] ?? null;
                break;
            }
        }

        if (!$switch_var_id) {
            $this->SendDebug(__FUNCTION__, "⚠️ Kein passender Light-Eintrag gefunden für Entity-ID $entityId", 0);
            IPS_SemaphoreLeave($lockName);
            return;
        }

        // Unterstützte cmd_id Werte: on, off, toggle
        $newState = null;
        $currentState = @GetValue($switch_var_id);

        if ($cmdId === 'on') {
            $newState = true;
        } elseif ($cmdId === 'off') {
            $newState = false;
        } elseif ($cmdId === 'toggle') {
            if (is_bool($currentState)) {
                $newState = !$currentState;
            }
        }
        $this->SendDebug(__FUNCTION__, "💡 Light-Command: $cmdId für $entityId, setze Status von " . json_encode($currentState) . " auf " . json_encode($newState), 0);
        // NEU: Block ersetzt, damit Parameter immer weiterverarbeitet werden!
        if ($newState !== null && $newState !== $currentState) {
            $this->SendDebug(__FUNCTION__, "✅ RequestAction für Switch VarID $switch_var_id mit Wert " . json_encode($newState), 0);
            RequestAction($switch_var_id, $newState);
            usleep(10000); // Wartezeit zur Synchronisation
        }

        // Auch wenn kein Schaltvorgang notwendig war, verarbeite die Parameter weiter unten

        // Auswertung der optionalen Parameter
        if (isset($params['brightness']) && $brightness_var_id && IPS_VariableExists($brightness_var_id)) {
            $brightness = $this->ConvertBrightnessToSymcon((int)$params['brightness'], $brightness_var_id);
            $this->SendDebug(__FUNCTION__, "✅ Set brightness to $brightness", 0);
            RequestAction($brightness_var_id, $brightness);
            usleep(10000);
        }

        if (isset($params['color_temperature']) && $color_temp_var_id && IPS_VariableExists($color_temp_var_id)) {
            $value = (int)$params['color_temperature'];
            $this->SendDebug(__FUNCTION__, "✅ Set color temperature to $value", 0);
            RequestAction($color_temp_var_id, $value);
            usleep(10000);
        }

        if ((isset($params['hue']) || isset($params['saturation'])) && $color_var_id && IPS_VariableExists($color_var_id)) {
            $h = $params['hue'] ?? 0;
            $s = $params['saturation'] ?? 0;
            $hexColor = $this->ConvertHueSaturationToHexColor($h, $s);
            $this->SendDebug(__FUNCTION__, "✅ Set color to HEX $hexColor (aus Hue $h / Sat $s)", 0);
            RequestAction($color_var_id, $hexColor);
            usleep(10000);
        }

        // Aktualisiere den tatsächlichen Zustand nach RequestAction
        $updatedState = @GetValue($switch_var_id);
        $attributes = ['state' => $updatedState ? 'ON' : 'OFF'];
        if (isset($params['brightness'])) {
            $attributes['brightness'] = $this->ConvertBrightnessToRemote($brightness_var_id);
        }
        if (!empty($color_var_id) && IPS_VariableExists($color_var_id)) {
            $hex = @GetValue($color_var_id);
            $hs = $this->ConvertHexColorToHueSaturation((int)$hex);
            $attributes['hue'] = $hs['hue'];
            $attributes['saturation'] = $hs['saturation'];
        }
        if (isset($params['color_temperature'])) {
            $attributes['color_temperature'] = (int)$params['color_temperature'];
        }
        $this->SendEntityChange($entityId, 'light', $attributes);
        $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
        IPS_SemaphoreLeave($lockName);
    }

    private function ConvertBrightnessToSymcon(int $remoteValue, int $varId): int
    {
        $var = IPS_GetVariable($varId);
        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

        if (!$profile || !IPS_VariableProfileExists($profile)) {
            return $remoteValue;
        }

        $profileData = IPS_GetVariableProfile($profile);
        $min = $profileData['MinValue'];
        $max = $profileData['MaxValue'];

        if ($min >= $max) {
            return $remoteValue;
        }

        return (int)round(($remoteValue / 255) * ($max - $min) + $min);
    }

    private function ConvertBrightnessToRemote(int $varId): int
    {
        $var = IPS_GetVariable($varId);
        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

        if (!$profile || !IPS_VariableProfileExists($profile)) {
            return (int)GetValue($varId);
        }

        $profileData = IPS_GetVariableProfile($profile);
        $min = $profileData['MinValue'];
        $max = $profileData['MaxValue'];

        if ($min >= $max) {
            return (int)GetValue($varId);
        }

        $symconValue = (int)GetValue($varId);
        return (int)round((($symconValue - $min) / ($max - $min)) * 255);
    }

    private function ConvertHueSaturationToHexColor(int $hue, int $saturation): int
    {
        $h = $hue / 360;
        $s = $saturation / 255;
        $v = 1;

        $i = floor($h * 6);
        $f = $h * 6 - $i;
        $p = $v * (1 - $s);
        $q = $v * (1 - $f * $s);
        $t = $v * (1 - (1 - $f) * $s);

        switch ($i % 6) {
            case 0:
                $r = $v;
                $g = $t;
                $b = $p;
                break;
            case 1:
                $r = $q;
                $g = $v;
                $b = $p;
                break;
            case 2:
                $r = $p;
                $g = $v;
                $b = $t;
                break;
            case 3:
                $r = $p;
                $g = $q;
                $b = $v;
                break;
            case 4:
                $r = $t;
                $g = $p;
                $b = $v;
                break;
            case 5:
                $r = $v;
                $g = $p;
                $b = $q;
                break;
        }

        return ((int)($r * 255) << 16) + ((int)($g * 255) << 8) + (int)($b * 255);
    }

    private function ConvertHexColorToHueSaturation(int $hexColor): array
    {
        $r = (($hexColor >> 16) & 0xFF) / 255;
        $g = (($hexColor >> 8) & 0xFF) / 255;
        $b = ($hexColor & 0xFF) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        // Hue
        if ($delta == 0) {
            $h = 0;
        } elseif ($max == $r) {
            $h = 60 * fmod((($g - $b) / $delta), 6);
        } elseif ($max == $g) {
            $h = 60 * ((($b - $r) / $delta) + 2);
        } else {
            $h = 60 * ((($r - $g) / $delta) + 4);
        }

        if ($h < 0) {
            $h += 360;
        }

        // Saturation
        $s = ($max == 0) ? 0 : $delta / $max;

        return [
            'hue' => (int)round($h),
            'saturation' => (int)round($s * 255)
        ];
    }

    private function HandleMediaPlayerCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';

        $this->SendDebug(__FUNCTION__, "🎵 MediaPlayer-Command: $cmdId für $entityId", 0);

        if (preg_match('/_(\d+)$/', $entityId, $match)) {
            $objectId = (int)$match[1];
            $lockName = 'UCR_' . $objectId;
        } else {
            $this->SendDebug(__FUNCTION__, "❌ Konnte Objekt-ID aus Entity-ID nicht extrahieren: $entityId", 0);
            return;
        }

        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->SendDebug(__FUNCTION__, "❌ Semaphore '$lockName' konnte nicht gesetzt werden (Timeout)", 0);
            return;
        }

        $mapping = json_decode($this->ReadPropertyString('media_player_mapping'), true);
        $found = null;
        foreach ($mapping as $entry) {
            if (!isset($entry['features']) || !is_array($entry['features'])) {
                continue;
            }
            foreach ($entry['features'] as $feature) {
                if ('media_player_' . $entry['instance_id'] === $entityId) {
                    $found = $entry;
                    break 2;
                }
            }
        }

        if (!$found) {
            $this->SendDebug(__FUNCTION__, "⚠️ Kein passender MediaPlayer-Eintrag für Entity-ID $entityId", 0);
            IPS_SemaphoreLeave($lockName);
            return;
        }

        $attributes = [];

        // Build feature map for lookup
        $featureMap = [];
        foreach ($found['features'] as $feature) {
            if (isset($feature['feature_key']) && isset($feature['var_id'])) {
                $featureMap[$feature['feature_key']] = $feature['var_id'];
            }
        }

        switch ($cmdId) {
            case 'on':
            case 'off':
            case 'toggle':
                if (isset($featureMap['on_off'])) {
                    $newValue = ($cmdId === 'toggle') ? !GetValue($featureMap['on_off']) : ($cmdId === 'on');
                    RequestAction($featureMap['on_off'], $newValue);
                    $attributes['state'] = $newValue ? 'ON' : 'OFF';
                }
                break;

            case 'play_pause':
                if (isset($featureMap['symcon_control'])) {
                    $varId = $featureMap['symcon_control'];
                    if (IPS_VariableExists($varId)) {
                        $var = IPS_GetVariable($varId);
                        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

                        if ($profile && IPS_VariableProfileExists($profile)) {
                            $profileData = IPS_GetVariableProfile($profile);
                            $currentValue = GetValue($varId);
                            $newValue = null;

                            foreach ($profileData['Associations'] as $assoc) {
                                $label = strtolower($assoc['Name']);
                                if (strpos($label, 'play') !== false && (string)$assoc['Value'] !== (string)$currentValue) {
                                    $newValue = $assoc['Value'];
                                    $attributes['state'] = 'PLAYING';
                                    break;
                                }
                                if (strpos($label, 'pause') !== false && (string)$assoc['Value'] !== (string)$currentValue) {
                                    $newValue = $assoc['Value'];
                                    $attributes['state'] = 'PAUSED';
                                    break;
                                }
                            }

                            if ($newValue !== null) {
                                RequestAction($varId, $newValue);
                            } else {
                                $this->SendDebug(__FUNCTION__, "⏭ Keine passende Alternative für play/pause im Profil gefunden", 0);
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, "⚠ Kein gültiges Profil für play/pause vorhanden", 0);
                        }
                    }
                }
                break;

            case 'back':
                if (isset($featureMap['symcon_control'])) {
                    $varId = $featureMap['symcon_control'];
                    if (IPS_VariableExists($varId)) {
                        $var = IPS_GetVariable($varId);
                        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

                        if ($profile && IPS_VariableProfileExists($profile)) {
                            $profileData = IPS_GetVariableProfile($profile);
                            $currentValue = GetValue($varId);
                            $newValue = null;

                            foreach ($profileData['Associations'] as $assoc) {
                                $label = strtolower($assoc['Name']);
                                if ((strpos($label, 'back') !== false || strpos($label, 'zurück') !== false) && (string)$assoc['Value'] !== (string)$currentValue) {
                                    $newValue = $assoc['Value'];
                                    break;
                                }
                            }

                            if ($newValue !== null) {
                                RequestAction($varId, $newValue);
                                $attributes['state'] = strtoupper($cmdId);
                            } else {
                                $this->SendDebug(__FUNCTION__, "⏭ Keine passende Alternative für $cmdId im Profil gefunden", 0);
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, "⚠ Kein gültiges Profil für $cmdId vorhanden", 0);
                        }
                    }
                }
                break;
            case 'stop':
            case 'previous':
            case 'next':
            case 'fast_forward':
            case 'rewind':
                if (isset($featureMap['symcon_control'])) {
                    $varId = $featureMap['symcon_control'];
                    if (IPS_VariableExists($varId)) {
                        $var = IPS_GetVariable($varId);
                        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

                        if ($profile && IPS_VariableProfileExists($profile)) {
                            $profileData = IPS_GetVariableProfile($profile);
                            $currentValue = GetValue($varId);
                            $newValue = null;

                            foreach ($profileData['Associations'] as $assoc) {
                                $label = strtolower($assoc['Name']);
                                if (
                                    (strpos($label, 'stop') !== false && $cmdId === 'stop') ||
                                    (strpos($label, 'previous') !== false && $cmdId === 'previous') ||
                                    (strpos($label, 'next') !== false && $cmdId === 'next') ||
                                    ((strpos($label, 'fast') !== false || strpos($label, 'vor') !== false) && strpos($label, 'forward') !== false && $cmdId === 'fast_forward') ||
                                    (strpos($label, 'rewind') !== false || strpos($label, 'zurück') !== false && $cmdId === 'rewind')
                                ) {
                                    if ((string)$assoc['Value'] !== (string)$currentValue) {
                                        $newValue = $assoc['Value'];
                                        break;
                                    }
                                }
                            }

                            if ($newValue !== null) {
                                RequestAction($varId, $newValue);
                                $attributes['state'] = strtoupper($cmdId);
                            } else {
                                $this->SendDebug(__FUNCTION__, "⏭ Keine passende Alternative für $cmdId im Profil gefunden", 0);
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, "⚠ Kein gültiges Profil für $cmdId vorhanden", 0);
                        }
                    }
                }
                break;
            case 'cursor_up':
            case 'cursor_down':
            case 'cursor_left':
            case 'cursor_right':
            case 'cursor_enter':
                if (isset($featureMap['symcon_dpad'])) {
                    $varId = $featureMap['symcon_dpad'];
                    if (IPS_VariableExists($varId)) {
                        $var = IPS_GetVariable($varId);
                        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

                        if ($profile && IPS_VariableProfileExists($profile)) {
                            $profileData = IPS_GetVariableProfile($profile);
                            $currentValue = GetValue($varId);
                            $newValue = null;

                            foreach ($profileData['Associations'] as $assoc) {
                                $label = strtolower($assoc['Name']);
                                if (
                                    (strpos($label, 'up') !== false && $cmdId === 'cursor_up') ||
                                    (strpos($label, 'down') !== false && $cmdId === 'cursor_down') ||
                                    (strpos($label, 'left') !== false && $cmdId === 'cursor_left') ||
                                    (strpos($label, 'right') !== false && $cmdId === 'cursor_right') ||
                                    (strpos($label, 'enter') !== false && $cmdId === 'cursor_enter')
                                ) {
                                    if ((string)$assoc['Value'] !== (string)$currentValue) {
                                        $newValue = $assoc['Value'];
                                        break;
                                    }
                                }
                            }

                            if ($newValue !== null) {
                                RequestAction($varId, $newValue);
                                $attributes['state'] = strtoupper($cmdId);
                            } else {
                                $this->SendDebug(__FUNCTION__, "⏭ Keine passende Alternative für $cmdId im Profil gefunden", 0);
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, "⚠ Kein gültiges Profil für $cmdId vorhanden", 0);
                        }
                    }
                }
                break;
            case 'digit_0':
            case 'digit_1':
            case 'digit_2':
            case 'digit_3':
            case 'digit_4':
            case 'digit_5':
            case 'digit_6':
            case 'digit_7':
            case 'digit_8':
            case 'digit_9':
                if (isset($featureMap['symcon_numpad'])) {
                    $varId = $featureMap['symcon_numpad'];
                    if (IPS_VariableExists($varId)) {
                        $var = IPS_GetVariable($varId);
                        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];

                        if ($profile && IPS_VariableProfileExists($profile)) {
                            $profileData = IPS_GetVariableProfile($profile);
                            $digit = str_replace('digit_', '', $cmdId);
                            $targetValue = null;

                            foreach ($profileData['Associations'] as $assoc) {
                                if ((string)$assoc['Name'] === $digit) {
                                    $targetValue = $assoc['Value'];
                                    break;
                                }
                            }

                            if ($targetValue !== null) {
                                RequestAction($varId, $targetValue);
                                $attributes['state'] = strtoupper($cmdId);
                            } else {
                                $this->SendDebug(__FUNCTION__, "⏭ Keine passende Ziffer $digit im Profil gefunden", 0);
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, "⚠ Kein gültiges Profil für $cmdId vorhanden", 0);
                        }
                    }
                }
                break;
            case 'function_red':
            case 'function_green':
            case 'function_yellow':
            case 'function_blue':
            case 'home':
            case 'menu':
            case 'context_menu':
            case 'guide':
            case 'info':
            case 'back':
            case 'record':
            case 'my_recordings':
            case 'live':
            case 'eject':
            case 'open_close':
            case 'audio_track':
            case 'subtitle':
            case 'settings':
                $this->SendDebug(__FUNCTION__, "⚠️ Befehl $cmdId ist dokumentiert aber erfordert manuelles Mapping oder Skriptausführung", 0);
                break;

            case 'seek':
                if (isset($msgData['params']['media_position']) && isset($featureMap['media_position'])) {
                    RequestAction($featureMap['media_position'], (int)$msgData['params']['media_position']);
                    $attributes['media_position'] = (int)$msgData['params']['media_position'];
                }
                break;

            case 'volume':
                if (isset($msgData['params']['volume']) && isset($featureMap['volume'])) {
                    RequestAction($featureMap['volume'], (float)$msgData['params']['volume']);
                    $attributes['volume'] = (float)$msgData['params']['volume'];
                }
                break;

            case 'volume_up':
            case 'volume_down':
                if (isset($featureMap['volume']) && IPS_VariableExists($featureMap['volume'])) {
                    $cur = GetValue($featureMap['volume']);
                    $delta = ($cmdId === 'volume_up') ? 5 : -5;
                    RequestAction($featureMap['volume'], max(0, $cur + $delta));
                    $attributes['volume'] = max(0, $cur + $delta);
                }
                break;

            case 'mute_toggle':
                if (isset($featureMap['muted'])) {
                    $val = GetValue($featureMap['muted']);
                    RequestAction($featureMap['muted'], !$val);
                    $attributes['muted'] = !$val;
                }
                break;

            case 'mute':
                if (isset($featureMap['muted'])) {
                    RequestAction($featureMap['muted'], true);
                    $attributes['muted'] = true;
                }
                break;

            case 'unmute':
                if (isset($featureMap['muted'])) {
                    RequestAction($featureMap['muted'], false);
                    $attributes['muted'] = false;
                }
                break;

            case 'repeat':
                if (isset($msgData['params']['repeat']) && isset($featureMap['repeat'])) {
                    RequestAction($featureMap['repeat'], (bool)$msgData['params']['repeat']);
                    $attributes['repeat'] = (bool)$msgData['params']['repeat'];
                }
                break;

            case 'shuffle':
                if (isset($msgData['params']['shuffle']) && isset($featureMap['shuffle'])) {
                    RequestAction($featureMap['shuffle'], (bool)$msgData['params']['shuffle']);
                    $attributes['shuffle'] = (bool)$msgData['params']['shuffle'];
                }
                break;

            case 'channel_up':
            case 'channel_down':
                $this->SendDebug(__FUNCTION__, "⚠️ Befehl $cmdId ist dokumentiert aber keine direkte Variable vorgesehen", 0);
                break;

            case 'select_source':
                if (isset($msgData['params']['source']) && isset($featureMap['source'])) {
                    RequestAction($featureMap['source'], $msgData['params']['source']);
                    $attributes['source'] = $msgData['params']['source'];
                }
                break;

            case 'select_sound_mode':
                if (isset($msgData['params']['mode']) && isset($featureMap['sound_mode'])) {
                    RequestAction($featureMap['sound_mode'], $msgData['params']['mode']);
                    $attributes['sound_mode'] = $msgData['params']['mode'];
                }
                break;

            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannter MediaPlayer-Command: $cmdId", 0);
                break;
        }

        if (!empty($attributes)) {
            $this->SendEntityChange($entityId, 'media_player', $attributes);
        }
        $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
        IPS_SemaphoreLeave($lockName);
    }

    /**
     * Führt einen Remote-Befehl aus, indem das im Mapping hinterlegte Skript aufgerufen wird.
     * Überträgt die cmd_id sowie params als $_IPS-Daten an das Skript.
     */
    private function HandleRemoteCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';

        $this->SendDebug(__FUNCTION__, "🎮 Remote-Command: $cmdId für $entityId", 0);

        if (preg_match('/_(\d+)$/', $entityId, $match)) {
            $objectId = (int)$match[1];
            $lockName = 'UCR_' . $objectId;
        } else {
            $this->SendDebug(__FUNCTION__, "❌ Konnte Objekt-ID aus Entity-ID nicht extrahieren: $entityId", 0);
            return;
        }

        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->SendDebug(__FUNCTION__, "❌ Semaphore '$lockName' konnte nicht gesetzt werden (Timeout)", 0);
            return;
        }

        $mapping = json_decode($this->ReadPropertyString('remote_mapping'), true);
        $commandScript = null;

        foreach ($mapping as $entry) {
            if ('remote_' . $entry['instance_id'] === $entityId) {
                $commandScript = $entry['script_id'] ?? null;
                break;
            }
        }

        if (!$commandScript || !IPS_ScriptExists($commandScript)) {
            $this->SendDebug(__FUNCTION__, "⚠️ Kein passender Remote-Eintrag oder Skript gefunden für Entity-ID $entityId", 0);
            IPS_SemaphoreLeave($lockName);
            return;
        }
        $params = "";
        // Übergabe der cmd_id und weiterer Daten an das Skript
        $cmdData = [
            'cmd' => $cmdId,
            'params' => $params
        ];

        IPS_RunScriptEx($commandScript, $cmdData);
        $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
        IPS_SemaphoreLeave($lockName);
    }

    private function HandleSwitchCommand(array $msgData, $clientIP, $clientPort, $reqId): void
    {
        $entityId = $msgData['entity_id'] ?? '';
        $cmdId = $msgData['cmd_id'] ?? '';

        // Semaphore Lock hinzufügen
        if (preg_match('/_(\d+)$/', $entityId, $match)) {
            $objectId = (int)$match[1];
            $lockName = 'UCR_' . $objectId;
        } else {
            $this->SendDebug(__FUNCTION__, "❌ Konnte Objekt-ID aus Entity-ID nicht extrahieren: $entityId", 0);
            return;
        }
        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->SendDebug(__FUNCTION__, "❌ Semaphore '$lockName' konnte nicht gesetzt werden (Timeout)", 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, "Switch-Command: $cmdId für $entityId", 0);
        $mapping = json_decode($this->ReadPropertyString('switch_mapping'), true);
        foreach ($mapping as $entry) {
            if ('switch_' . $entry['instance_id'] === $entityId) {
                $varId = (int)$entry['var_id'];
                $current = @GetValue($varId);

                if ($cmdId === 'on') {
                    $newState = true;
                } elseif ($cmdId === 'off') {
                    $newState = false;
                } elseif ($cmdId === 'toggle') {
                    if (is_bool($current)) {
                        $newState = !$current;
                    } else {
                        $this->SendDebug(__FUNCTION__, "⚠️ Aktueller Wert ist kein Bool: $current", 0);
                        IPS_SemaphoreLeave($lockName);
                        return;
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, "⚠️ Unbekannter Switch-Command: $cmdId", 0);
                    IPS_SemaphoreLeave($lockName);
                    return;
                }

                if ($newState !== null && $current !== $newState) {
                    $this->SendDebug(__FUNCTION__, "✅ RequestAction für VarID $varId mit Wert " . json_encode($newState), 0);
                    RequestAction($varId, $newState);
                    usleep(10000); // 10ms
                    $updated = @GetValue($varId);  // neuen Zustand auslesen
                    $stateStr = $updated ? 'ON' : 'OFF';
                    $this->SendEntityChange("switch_$varId", "switch", ['state' => $stateStr]);
                } else {
                    $this->SendDebug(__FUNCTION__, "⏩ Kein RequestAction erforderlich – Zustand unverändert", 0);
                }
                $this->SendSuccessResponse((int)$reqId, $clientIP, (int)$clientPort);
                // Semaphore am Ende freigeben
                IPS_SemaphoreLeave($lockName);
                return;
            }
        }
        $this->SendDebug(__FUNCTION__, "⚠️ Kein passender Switch gefunden für Entity-ID $entityId", 0);
        IPS_SemaphoreLeave($lockName);
    }

    /**
     * Sendet eine Abschlussantwort ("kind":"resp", "code":200) an den Remote-Client nach erfolgreichem Switch-Command.
     */
    private function SendSuccessResponse(int $reqId, string $clientIP, int $clientPort): void
    {
        $response = [
            'kind' => 'resp',
            'req_id' => $reqId,
            'code' => 200,
            'msg' => 'result',
            'msg_data' => new stdClass()
        ];
        $this->SendDebug(__FUNCTION__, "📤 Abschlussantwort an $clientIP:$clientPort für req_id $reqId", 0);
        $this->PushToRemoteClient($response, $clientIP, $clientPort);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug(__FUNCTION__, '✅ Kernel READY – sende Initial-Events', 0);
            $this->RegisterHook('/hook/unfoldedcircle');
            $this->RegisterMdnsService();
            $this->RefreshRemoteCores();
            $this->SendDebug(__FUNCTION__, '🔁 Setze Timerintervalle: PingDeviceState=30s, UpdateAllEntityStates=15s', 0);
            $this->SetTimerInterval("PingDeviceState", 30000); // alle 30 Sekunden den Status senden
            $this->SetTimerInterval("UpdateAllEntityStates", 15000); // alle 15 Sekunden den Status senden
            $this->SendInitialOnlineEventsForAllClients();
            $this->EnsureTokenInitialized();
        }
        if ($Message == VM_UPDATE) {
            $this->SendDebug(__FUNCTION__, "📣 Variablen-Update empfangen: ID $SenderID", 0);

            // Semaphore-Check für Switches (Events von RequestAction blockieren)
            $lockName = 'UCR_' . $SenderID;
            if (!IPS_SemaphoreEnter($lockName, 1)) {
                $this->SendDebug(__FUNCTION__, "⏸ $SenderID gesperrt durch aktiven Befehl – Event wird unterdrückt", 0);
                return;
            }
            IPS_SemaphoreLeave($lockName);

            $this->SendEntityStateUpdate($SenderID);
        }
    }

    /**
     * Sendet ein entity_change Event an alle authentifizierten oder freigegebenen Remote-Clients.
     */
    private function SendEntityChange(string $entityId, string $entityType, array $attributes): void
    {
        // For light entities, if color_var_id is available, add hue/saturation from hex color
        if ($entityType === 'light') {
            $lightMapping = json_decode($this->ReadPropertyString('light_mapping'), true);
            if (is_array($lightMapping)) {
                foreach ($lightMapping as $entry) {
                    if ('light_' . $entry['switch_var_id'] === $entityId) {
                        if (!empty($entry['color_var_id']) && @IPS_VariableExists($entry['color_var_id'])) {
                            $hex = @GetValue($entry['color_var_id']);
                            $hs = $this->ConvertHexColorToHueSaturation((int)$hex);
                            $attributes['hue'] = $hs['hue'];
                            $attributes['saturation'] = $hs['saturation'];
                        }
                        break;
                    }
                }
            }
        }
        $event = [
            'kind' => 'event',
            'msg' => 'entity_change',
            'msg_data' => [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'attributes' => $attributes
            ],
            'cat' => 'ENTITY'
        ];

        $sessions = json_decode($this->ReadAttributeString('client_sessions'), true) ?? [];
        $whitelistConfig = json_decode($this->ReadPropertyString('ip_whitelist'), true);
        $ipWhitelist = array_column($whitelistConfig ?? [], 'ip');

        foreach ($sessions as $ip => $info) {
            $auth = $info['authenticated'] ?? false;
            $sub = $info['subscribed'] ?? false;
            $port = $info['port'] ?? 0;
            $whitelisted = in_array($ip, $ipWhitelist);

            if ((!$auth && !$whitelisted) || !$port) {
                continue;
            }

            $this->SendDebug(__FUNCTION__, "📤 Sende entity_change für $entityId an $ip:$port", 0);
            $this->PushToRemoteClient($event, $ip, (int)$port);
        }
    }

    /**
     * Prüft, ob die Variable in der switch_mapping referenziert ist und sendet ein entity_state Event an alle authentifizierten Remote-Clients.
     * Fügt detaillierte Debug-Ausgaben für bessere Nachvollziehbarkeit hinzu.
     * Verwendet einen RAM-Puffer für den letzten gesendeten Zustand, um Attributschreibungen zu vermeiden.
     */
    private array $stateBuffer = [];

    public function SendEntityStateUpdate(int $varId): void
    {
        // $this->SendDebug(__FUNCTION__, "🔄 Aktualisiere Zustand für VarID: $varId", 0);

        // 1. Switches
        $switchMapping = json_decode($this->ReadPropertyString('switch_mapping'), true);
        if (is_array($switchMapping)) {
            foreach ($switchMapping as $entry) {
                if (isset($entry['var_id']) && (int)$entry['var_id'] === $varId) {
                    $state = @GetValue($varId);
                    $stateStr = ($state) ? 'ON' : 'OFF';
                    $currentBool = (bool)$state;
                    // RAM-Puffer für Zustand
                    if (isset($this->stateBuffer[$varId]) && $this->stateBuffer[$varId] === $currentBool) {
                        // $this->SendDebug(__FUNCTION__, "⏭️ Zustand hat sich nicht geändert (weiter: $currentBool)", 0);
                        return;
                    }
                    $this->stateBuffer[$varId] = $currentBool;

                    $this->SendDebugExtended(__FUNCTION__, "✅ Gefundener Switch-Eintrag für VarID $varId → State: $stateStr", 0);

                    $event = [
                        'kind' => 'event',
                        'msg' => 'entity_state',
                        'msg_data' => [
                            'entity_id' => 'switch_' . $varId,
                            'entity_type' => 'switch',
                            'attributes' => [
                                'state' => $stateStr
                            ]
                        ]
                    ];

                    $this->BroadcastEventToClients($event);
                    return;
                }
            }
        }

        // 2. Buttons
        $buttonMapping = json_decode($this->ReadPropertyString('button_mapping'), true);
        if (is_array($buttonMapping)) {
            foreach ($buttonMapping as $entry) {
                if (isset($entry['var_id']) && (int)$entry['var_id'] === $varId) {
                    $this->SendDebug(__FUNCTION__, "✅ Gefundener Button-Eintrag für VarID $varId", 0);

                    $event = [
                        'kind' => 'event',
                        'msg' => 'entity_state',
                        'msg_data' => [
                            'entity_id' => 'button_' . $entry['script_id'],
                            'entity_type' => 'button',
                            'attributes' => [
                                'state' => 'AVAILABLE'
                            ]
                        ]
                    ];

                    $this->BroadcastEventToClients($event);
                    return;
                }
            }
        }

        // 3. Sensoren
        $sensorMapping = json_decode($this->ReadPropertyString('sensor_mapping'), true);
        if (is_array($sensorMapping)) {
            foreach ($sensorMapping as $entry) {
                if (!isset($entry['var_id']) || (int)$entry['var_id'] !== $varId) {
                    continue;
                }
                $sensorType = $entry['sensor_type'] ?? 'generic';
                $value = @GetValue($varId);
                $unit = '';
                // Versuche Einheit aus Profil abzuleiten
                $v = IPS_GetVariable($varId);
                $profile = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
                if ($profile) {
                    $profileDetails = IPS_GetVariableProfile($profile);
                    $unit = $profileDetails['Suffix'] ?? '';
                }
                $event = [
                    'kind' => 'event',
                    'msg' => 'entity_change',
                    'cat' => 'ENTITY',
                    'msg_data' => [
                        'entity_type' => 'sensor',
                        'entity_id' => 'sensor_' . $varId,
                        'attributes' => [
                            'value' => $value,
                            'unit' => $unit
                        ]
                    ]
                ];
                $this->BroadcastEventToClients($event);
                return;
            }
        }

        // 4. Light (Leuchtmittel)
        $lightMapping = json_decode($this->ReadPropertyString('light_mapping'), true);
        if (is_array($lightMapping)) {
            foreach ($lightMapping as $entry) {
                if (!isset($entry['switch_var_id']) || (int)$entry['switch_var_id'] !== $varId) {
                    continue;
                }
                $value = @GetValue($varId);
                $state = $value ? 'ON' : 'OFF';
                $attributes = ['state' => $state];

                if (!empty($entry['brightness_var_id']) && IPS_VariableExists($entry['brightness_var_id'])) {
                    $attributes['brightness'] = $this->ConvertBrightnessToRemote($entry['brightness_var_id']);
                }
                if (!empty($entry['color_var_id']) && IPS_VariableExists($entry['color_var_id'])) {
                    $color = json_decode(@GetValue($entry['color_var_id']), true);
                    if (is_array($color)) {
                        $attributes['hue'] = $color['hue'] ?? 0;
                        $attributes['saturation'] = $color['saturation'] ?? 0;
                    }
                }
                if (!empty($entry['color_temp_var_id']) && IPS_VariableExists($entry['color_temp_var_id'])) {
                    $attributes['color_temperature'] = (int)@GetValue($entry['color_temp_var_id']);
                }

                $event = [
                    'kind' => 'event',
                    'msg' => 'entity_change',
                    'cat' => 'ENTITY',
                    'msg_data' => [
                        'entity_type' => 'light',
                        'entity_id' => 'light_' . $varId,
                        'attributes' => $attributes
                    ]
                ];
                $this->SendDebug(__FUNCTION__, "📤 Entity-Change für Light $varId", 0);
                $this->BroadcastEventToClients($event);
                return;
            }
        }

        // 5. Cover (Jalousie, Rollladen)
        $coverMapping = json_decode($this->ReadPropertyString('cover_mapping'), true);
        if (is_array($coverMapping)) {
            foreach ($coverMapping as $entry) {
                if (!isset($entry['position_var_id']) || (int)$entry['position_var_id'] !== $varId) {
                    continue;
                }
                $position = @GetValue($varId);
                $state = 'SETTING';
                $event = [
                    'kind' => 'event',
                    'msg' => 'entity_change',
                    'cat' => 'ENTITY',
                    'msg_data' => [
                        'entity_type' => 'cover',
                        'entity_id' => 'cover_' . $varId,
                        'attributes' => [
                            'state' => $state,
                            'position' => $position
                        ]
                    ]
                ];
                $this->SendDebug(__FUNCTION__, "📤 Entity-Change für Cover $varId", 0);
                $this->BroadcastEventToClients($event);
                return;
            }
        }

        // 6. Climate (Klima)
        $climateMapping = json_decode($this->ReadPropertyString('climate_mapping'), true);
        if (is_array($climateMapping)) {
            foreach ($climateMapping as $entry) {
                if (!isset($entry['status_var_id']) || (int)$entry['status_var_id'] !== $varId) {
                    continue;
                }
                // Dynamische State-Bestimmung
                $state = 'OFF';
                if (isset($entry['mode_var_id']) && IPS_VariableExists($entry['mode_var_id'])) {
                    $value = GetValue($entry['mode_var_id']);
                    $label = $this->GetProfileValueLabel($entry['mode_var_id'], $value);
                    $allowedStates = ['HEAT', 'COOL', 'HEAT_COOL', 'FAN', 'AUTO', 'OFF'];
                    if (in_array($label, $allowedStates)) {
                        $state = $label;
                    }
                }
                $attributes = [];
                $attributes['state'] = $state;
                if (!empty($entry['current_temp_var_id']) && IPS_VariableExists($entry['current_temp_var_id'])) {
                    $attributes['current_temperature'] = (float)@GetValue($entry['current_temp_var_id']);
                }
                if (!empty($entry['target_temp_var_id']) && IPS_VariableExists($entry['target_temp_var_id'])) {
                    $attributes['target_temperature'] = (float)@GetValue($entry['target_temp_var_id']);
                }
                if (!empty($entry['mode_var_id']) && IPS_VariableExists($entry['mode_var_id'])) {
                    $attributes['hvac_mode'] = @GetValue($entry['mode_var_id']);
                }
                $event = [
                    'kind' => 'event',
                    'msg' => 'entity_change',
                    'cat' => 'ENTITY',
                    'msg_data' => [
                        'entity_type' => 'climate',
                        'entity_id' => 'climate_' . $entry['status_var_id'],
                        'attributes' => $attributes
                    ]
                ];
                $this->SendDebug(__FUNCTION__, "📤 Entity-Change für Climate {$entry['status_var_id']}", 0);
                $this->BroadcastEventToClients($event);
                return;
            }
        }

        // 7. Media Player
        $mediaMapping = json_decode($this->ReadPropertyString('media_player_mapping'), true);
        if (is_array($mediaMapping)) {
            foreach ($mediaMapping as $entry) {
                if (!isset($entry['features']) || !is_array($entry['features'])) {
                    continue;
                }

                foreach ($entry['features'] as $feature) {
                    if (!isset($feature['var_id']) || (int)$feature['var_id'] !== $varId) {
                        continue;
                    }

                    $attributes = [];
                    $entityId = null;

                    foreach ($entry['features'] as $f) {
                        if (!isset($f['feature_key']) || !isset($f['var_id'])) {
                            continue;
                        }

                        $fKey = $f['feature_key'];
                        $fVar = $f['var_id'];

                        if (!IPS_VariableExists($fVar)) {
                            continue;
                        }

                        $val = @GetValue($fVar);

                        switch ($fKey) {
                            case 'on_off':
                                $attributes['state'] = $val ? 'ON' : 'OFF';
                                $entityId = 'mediaplayer_' . $fVar;
                                break;
                            case 'symcon_control':
                                if (method_exists($this, 'GetMediaPlayerStateFromControlVariable')) {
                                    $attributes['state'] = $this->GetMediaPlayerStateFromControlVariable($fVar);
                                }
                                break;
                            case 'volume':
                                $attributes['volume'] = (float)$val;
                                break;
                            case 'muted':
                                $attributes['muted'] = (bool)$val;
                                break;
                            case 'repeat':
                                $attributes['repeat'] = (bool)$val;
                                break;
                            case 'shuffle':
                                $attributes['shuffle'] = (bool)$val;
                                break;
                            case 'media_title':
                                $attributes['media_title'] = $val;
                                break;
                            case 'media_artist':
                                $attributes['media_artist'] = $val;
                                break;
                            case 'media_album':
                                $attributes['media_album'] = $val;
                                break;
                            case 'media_duration':
                                if (method_exists($this, 'ConvertTimeStringToSeconds')) {
                                    $attributes['media_duration'] = $this->ConvertTimeStringToSeconds($val);
                                }
                                break;
                            case 'media_position':
                                if (method_exists($this, 'ConvertTimeStringToSeconds')) {
                                    $attributes['media_position'] = $this->ConvertTimeStringToSeconds($val);
                                }
                                break;
                            case 'media_image_url':
                                $attributes['media_image_url'] = $val;
                                break;
                            case 'media_type':
                                $attributes['media_type'] = $val;
                                break;
                            case 'source':
                                $attributes['source'] = $val;
                                break;
                            case 'sound_mode':
                                $attributes['sound_mode'] = $val;
                                break;
                        }
                    }

                    if (!empty($entityId)) {
                        $event = [
                            'kind' => 'event',
                            'msg' => 'entity_change',
                            'cat' => 'ENTITY',
                            'msg_data' => [
                                'entity_type' => 'media_player',
                                'entity_id' => $entityId,
                                'attributes' => $attributes
                            ]
                        ];
                        $this->SendDebug(__FUNCTION__, "📤 Entity-Change für Media Player $entityId", 0);
                        $this->BroadcastEventToClients($event);
                        return;
                    }
                }
            }
        }

        $this->SendDebug(__FUNCTION__, "⚠️ Kein Mapping für VarID $varId gefunden", 0);
    }

    /**
     * Broadcasts an event to all authenticated or whitelisted clients.
     *
     * @param array $event
     * @return void
     */
    private function BroadcastEventToClients(array $event): void
    {
        $sessions = json_decode($this->ReadAttributeString('client_sessions'), true) ?? [];
        $whitelistConfig = json_decode($this->ReadPropertyString('ip_whitelist'), true);
        $ipWhitelist = array_column($whitelistConfig ?? [], 'ip');
        foreach ($sessions as $ip => $info) {
            $auth = $info['authenticated'] ?? false;
            $port = $info['port'] ?? 0;
            $whitelisted = in_array($ip, $ipWhitelist);
            if ((!$auth && !$whitelisted) || !$port) {
                continue;
            }
            $this->SendDebug(__FUNCTION__, "📤 Sende Event an $ip:$port", 0);
            $this->PushToRemoteClient($event, $ip, (int)$port);
        }
    }

    /**
     * Sendet Initial-Online-Events für alle authentifizierten oder freigegebenen Remote-Clients.
     */
    private function SendInitialOnlineEventsForAllClients(): void
    {
        $sessions = $this->readSessions();
        $whitelistConfig = json_decode($this->ReadPropertyString('ip_whitelist'), true);
        $ipWhitelist = array_column($whitelistConfig ?? [], 'ip');

        foreach ($sessions as $clientIP => $entry) {
            if (!is_array($entry) || !($entry['authenticated'] ?? false) || !isset($entry['port'])) {
                // Auch Whitelist berücksichtigen
                $whitelisted = in_array($clientIP, $ipWhitelist);
                if (!$whitelisted || !isset($entry['port'])) {
                    continue;
                }
            }

            $port = (int)$entry['port'];

            // Sensoren melden sich online
            $sensorMapping = json_decode($this->ReadPropertyString('sensor_mapping'), true);
            if (is_array($sensorMapping)) {
                foreach ($sensorMapping as $sensor) {
                    if (!isset($sensor['var_id'])) {
                        continue;
                    }
                    $value = @GetValue($sensor['var_id']);
                    $unit = '';
                    $v = IPS_GetVariable($sensor['var_id']);
                    $profile = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
                    if ($profile && IPS_VariableProfileExists($profile)) {
                        $profileDetails = IPS_GetVariableProfile($profile);
                        $unit = $profileDetails['Suffix'] ?? '';
                    }

                    $event = [
                        'kind' => 'event',
                        'msg' => 'entity_change',
                        'cat' => 'ENTITY',
                        'msg_data' => [
                            'entity_type' => 'sensor',
                            'entity_id' => 'sensor_' . $sensor['var_id'],
                            'attributes' => [
                                'state' => 'ON',
                                'value' => $value,
                                'unit' => $unit
                            ]
                        ]
                    ];

                    $this->SendDebug(__FUNCTION__, "📤 Online-Event für sensor_{$sensor['var_id']} an $clientIP:$port", 0);
                    $this->PushToRemoteClient($event, $clientIP, $port);
                }
            }

            // Schalter melden sich online
            $switchMapping = json_decode($this->ReadPropertyString('switch_mapping'), true);
            if (is_array($switchMapping)) {
                foreach ($switchMapping as $switch) {
                    if (!isset($switch['var_id'])) {
                        continue;
                    }
                    $value = @GetValue($switch['var_id']);
                    $state = $value ? 'ON' : 'OFF';

                    $event = [
                        'kind' => 'event',
                        'msg' => 'entity_change',
                        'cat' => 'ENTITY',
                        'msg_data' => [
                            'entity_type' => 'switch',
                            'entity_id' => 'switch_' . $switch['var_id'],
                            'attributes' => [
                                'state' => $state
                            ]
                        ]
                    ];

                    $this->SendDebug(__FUNCTION__, "📤 Online-Event für switch_{$switch['var_id']} an $clientIP:$port", 0);
                    $this->PushToRemoteClient($event, $clientIP, $port);
                }
            }

            // Leuchtmittel melden sich online
            $lightMapping = json_decode($this->ReadPropertyString('light_mapping'), true);
            if (is_array($lightMapping)) {
                foreach ($lightMapping as $light) {
                    if (!isset($light['switch_var_id'])) {
                        continue;
                    }

                    $value = @GetValue($light['switch_var_id']);
                    $state = $value ? 'ON' : 'OFF';
                    $attributes = ['state' => $state];

                    if (!empty($light['brightness_var_id']) && IPS_VariableExists($light['brightness_var_id'])) {
                        $attributes['brightness'] = (int)@GetValue($light['brightness_var_id']);
                    }

                    if (!empty($light['color_var_id']) && IPS_VariableExists($light['color_var_id'])) {
                        $color = json_decode(@GetValue($light['color_var_id']), true);
                        if (is_array($color)) {
                            $attributes['hue'] = $color['hue'] ?? 0;
                            $attributes['saturation'] = $color['saturation'] ?? 0;
                        }
                    }

                    if (!empty($light['color_temp_var_id']) && IPS_VariableExists($light['color_temp_var_id'])) {
                        $attributes['color_temperature'] = (int)@GetValue($light['color_temp_var_id']);
                    }

                    $event = [
                        'kind' => 'event',
                        'msg' => 'entity_change',
                        'cat' => 'ENTITY',
                        'msg_data' => [
                            'entity_type' => 'light',
                            'entity_id' => 'light_' . $light['switch_var_id'],
                            'attributes' => $attributes
                        ]
                    ];

                    $this->SendDebug(__FUNCTION__, "📤 Online-Event für light_{$light['switch_var_id']} an $clientIP:$port", 0);
                    $this->PushToRemoteClient($event, $clientIP, $port);
                }
            }
        }
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData(): void
    {
        $this->SendDebug(__FUNCTION__, '🛜 SERVER REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? '---'), 0);

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
        $remotePort = intval($_SERVER['REMOTE_PORT']) ?? 0;
        $this->SendDebug(__FUNCTION__, "📥 Request URI: $uri | Method: $method | IP: $remoteIP", 0);

        if (strpos($uri, '/hook/unfoldedcircle') !== 0) {
            return;
        }

        if (!$this->authenticateClient($remoteIP, $remotePort, $_SERVER['HTTP_AUTH_TOKEN'] ?? null)) {
            $this->SendDebug(__FUNCTION__, '❌ Webhook-Zugriff verweigert – Authentifizierung fehlgeschlagen', 0);

            $this->PushToRemoteClientHook([
                'kind' => 'resp',
                'msg' => 'auth_required',
                'req_id' => 0,
                'msg_data' => [
                    'code' => 401,
                    'message' => 'Unauthorized – Invalid or missing token'
                ]
            ], $remoteIP, $remotePort);
            http_response_code(401);
            return;
        }

        $payload = file_get_contents('php://input');
        $this->SendDebug(__FUNCTION__, "Raw Data: " . $payload, 0);


        // Prüfen auf PING-Frame (WebSocket)
        if (WebSocketUtils::IsPingFrame($payload)) {
            $this->SendDebug(__FUNCTION__, "🔁 PING erkannt – sende PONG", 0);
            // $pong = WebSocketUtils::PackPong();
            // todo is webhook sending PONG ?
            // $this->PushPongToRemoteClient($pong);
            return;
        }

        // JSON-Nutzdaten lesen
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler: Ungültiges JSON empfangen!', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '📨 Empfangene Daten: ' . json_encode($data), 0);


        $response = [];

        if (isset($data['msg'])) {
            switch ($data['msg']) {
                case 'get_driver_version':
                    $response = [
                        'kind' => 'resp',
                        'msg' => 'driver_version',
                        'req_id' => $data['req_id'] ?? 0,
                        'msg_data' => [
                            'name' => 'Symcon Integration Driver',
                            'version' => '0.1.0',
                            'api_version' => '1.0.0'
                        ]
                    ];
                    break;

                case 'get_device_state':
                    $response = [
                        'kind' => 'resp',
                        'msg' => 'device_state',
                        'req_id' => $data['req_id'] ?? 0,
                        'msg_data' => [
                            'state' => 'ready'
                        ]
                    ];
                    break;

                default:
                    $this->SendDebug(__FUNCTION__, '⚠️ Unbekannte Anfrage: ' . $data['msg'], 0);
                    $response = [
                        'kind' => 'resp',
                        'msg' => 'result',
                        'req_id' => $data['req_id'] ?? 0,
                        'msg_data' => [
                            'code' => 501,
                            'message' => 'Not implemented'
                        ]
                    ];
                    break;
            }

            $this->SendDebug(__FUNCTION__, '📤 Antwort: ' . json_encode($response), 0);
            $this->PushToRemoteClientHook($response, $remoteIP, $remotePort);
        }
    }

    private function PushToRemoteClientHook(array $data, string $remoteIP, int $remotePort): void
    {
        $json = json_encode($data);
        $this->SendDebug(__FUNCTION__, '📡 Sende an Remote: ' . $json, 0);
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            WC_PushMessageEx($ids[0], '/hook/unfoldedcircle', $json, $remoteIP, $remotePort);
        }
    }

    public function GenerateToken(): void
    {
        $token = bin2hex(random_bytes(16)); // 32 characters hex string
        $this->WriteAttributeString('token', $token);
        $this->SendDebug(__FUNCTION__, '🔑 Neuer Token generiert: ' . $token, 0);
        $this->UpdateFormField("token", "value", $token);
    }

    /**
     * Scans the object tree for known variable profiles and suggests device mappings
     */
    public function SuggestDeviceMappings(): void
    {
        $result = [
            'switches' => [],
            'buttons' => []
        ];

        $allObjects = IPS_GetObjectList();

        foreach ($allObjects as $id) {
            if (!IPS_VariableExists($id)) {
                continue;
            }

            $v = IPS_GetVariable($id);
            $profile = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
            $name = IPS_GetName($id);
            $parent = IPS_GetName(IPS_GetParent($id));

            // Check for Switch (bool with ~Switch or similar profile)
            if ($v['VariableType'] === 0 && preg_match('/switch|toggle/i', $profile)) {
                $result['switches'][] = [
                    'name' => "$parent → $name",
                    'var_id' => $id,
                    'profile' => $profile
                ];
                continue;
            }

            // Check for Button (bool without feedback, likely script trigger)
            if ($v['VariableType'] === 0 && $profile === '') {
                $result['buttons'][] = [
                    'name' => "$parent → $name",
                    'var_id' => $id,
                    'profile' => '(none)'
                ];
                continue;
            }
        }

        $this->SendDebug(__FUNCTION__, '📋 Gerätevorschläge: ' . json_encode($result), 0);

        echo json_encode($result, JSON_PRETTY_PRINT);
    }

    /**
     * Manuelle Registrierung des Treibers bei Remote-Instanzen
     */
    public function RegisterDriverManually()
    {
        $this->RefreshRemoteCores();
        $remotes = json_decode($this->ReadAttributeString('remote_cores'), true);
        $token = $this->ReadAttributeString('token');

        if (!is_array($remotes)) {
            $this->SendDebug(__FUNCTION__, "❌ Keine Remote-Instanzen gefunden", 0);
            return;
        }

        foreach ($remotes as $remote) {


            $ip = $remote['host'];
            $apiKey = $remote['api_key'];

            $hostValue = trim($this->ReadPropertyString('callback_IP'));
            if ($hostValue === '') {
                $hostValue = $ip; // Fallback: Remote IP
            }

            $this->SendDebugExtended(__FUNCTION__, "🔍 Registriere Treiber bei $ip (Symcon Host: $hostValue)", 0);
            $this->SendDebugExtended(__FUNCTION__, "📡 API-Key: $apiKey | Token: $token", 0);
            $payload = [
                'driver_id' => 'symcon-unfoldedcircle',
                'name' => [
                    'en' => 'Symcon external driver',
                    'de' => 'Symcon externer Treiber',
                    'da' => 'Symcon ekstern driver',
                    'nl' => 'Symcon externe driver',
                    'fr' => 'Pilote externe Symcon',
                    'es' => 'Controlador externo de Symcon'
                ],
                'driver_url' => 'ws://' . $hostValue . ':9988',
                'token' => $token,
                'auth_method' => 'MESSAGE',
                'version' => '0.0.1',
                'icon' => 'custom:symcon_icon.png',
                'enabled' => true,
                'description' => [
                    'en' => 'Driver for controlling devices connected to Symcon',
                    'de' => 'Ansteuerung von an Symcon angebundenen Geräten',
                    'da' => 'Styring af enheder tilsluttet Symcon',
                    'nl' => 'Aansturing van apparaten gekoppeld aan Symcon',
                    'fr' => 'Contrôle des appareils connectés à Symcon',
                    'es' => 'Control de dispositivos conectados a Symcon'
                ],
                'device_discovery' => false,
                'setup_data_schema' => new stdClass(),
                'release_date' => '2025-05-19'
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Bearer ' . $apiKey
                    ],
                    'content' => json_encode($payload)
                ]
            ]);

            $url = "http://{$ip}/api/intg/drivers";
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $this->SendDebug(__FUNCTION__, "❌ Fehler bei POST an $url", 0);
            } else {
                $this->SendDebug(__FUNCTION__, "✅ Treiberregistrierung erfolgreich: $response", 0);
            }
        }
    }

    /**
     * Formats the client session list for display in the configuration form.
     *
     * @return array
     */
    private function FormatSessionList(): array
    {
        $sessions = $this->readSessions();  // uses the persistent client_sessions attribute
        $result = [];

        // Discovery-Instanz finden
        $discoveryId = @IPS_GetInstanceListByModuleID('{4C0ABD10-D25B-0D92-9B2A-9E10E24659B0}')[0] ?? 0;
        $knownRemotes = [];
        if ($discoveryId) {
            $knownRemotes = @UCR_GetKnownRemotes($discoveryId);
        }

        $seenIPs = [];

        foreach ($sessions as $clientKey => $info) {
            if (strpos($clientKey, ':') !== false) {
                [$ip, $port] = explode(':', $clientKey, 2);
            } else {
                $ip = $clientKey;
                $port = $info['port'] ?? '';
                if ($port === '') {
                    $this->SendDebug(__FUNCTION__, "⚠️ Kein Port gefunden für clientKey: $clientKey", 0);
                    continue;
                }
            }

            if (in_array($ip, $seenIPs)) {
                $this->SendDebug(__FUNCTION__, "ℹ️ Überspringe Duplikat für IP: $ip", 0);
                continue;
            }
            $seenIPs[] = $ip;
            $remote = array_filter($knownRemotes, fn($r) => $r['host'] === $ip);
            $remote = array_values($remote)[0] ?? [];

            $result[] = [
                'name' => $remote['name'] ?? '—',
                'version' => $remote['version'] ?? '—',
                'api_version' => $remote['ver_api'] ?? '—',
                'model' => $remote['model'] ?? '—',
                'ip' => $ip,
                'port' => $port,
                'authenticated' => $info['authenticated'] ? '✅ Yes' : '❌ No',
                'last_seen' => $info['last_seen'] ?? 'N/A'
            ];
        }

        return $result;
    }

    /**
     * Entfernt alle client_sessions-Einträge mit ungültigem Key-Format oder fehlerhafter Struktur.
     * Kann manuell über ein Aktionsfeld im Formular ausgelöst werden.
     */
    public function CleanupClientSessions(): void
    {
        $sessions = json_decode($this->ReadAttributeString('client_sessions'), true);
        if (!is_array($sessions)) {
            $this->SendDebug(__FUNCTION__, '⚠️ client_sessions ist kein Array', 0);
            return;
        }

        $cleaned = [];

        foreach ($sessions as $clientKey => $info) {
            // Akzeptiere IP:Port oder IP-only, wenn Port im Info-Block vorhanden und numerisch
            if (strpos($clientKey, ':') === false) {
                if (!isset($info['port']) || !is_numeric($info['port'])) {
                    $this->SendDebug(__FUNCTION__, "🧹 Entferne veralteten IP-Key ohne gültigen Port: $clientKey", 0);
                    continue;
                }
            }

            if (!is_array($info) || !isset($info['authenticated']) || !isset($info['subscribed'])) {
                $this->SendDebug(__FUNCTION__, "🧹 Entferne ungültigen Datenblock für $clientKey", 0);
                continue;
            }

            $cleaned[$clientKey] = $info;
        }

        $this->WriteAttributeString('client_sessions', json_encode($cleaned));
        $this->SendDebug(__FUNCTION__, '✅ Aufgeräumte Sitzungen: ' . json_encode($cleaned), 0);
    }

    /**
     * Liefert die var_id für ein Feature, z. B. "volume", aus einer Instanz anhand der bekannten Zuordnung.
     */
    private function ResolveFeatureVarID(int $instanceID, string $featureKey): ?int
    {
        $instance = IPS_GetInstance($instanceID);
        $guid = $instance['ModuleInfo']['ModuleID'];

        // DeviceRegistry-Mapping abrufen
        if (!class_exists('DeviceRegistry')) {
            $this->SendDebug(__FUNCTION__, "❌ DeviceRegistry-Klasse nicht gefunden", 0);
            return null;
        }

        $deviceMapping = DeviceRegistry::getDeviceMappingByGUID($guid);
        if (!$deviceMapping || !isset($deviceMapping['mapping'])) {
            $this->SendDebug(__FUNCTION__, "❌ Kein Mapping in DeviceRegistry für GUID $guid", 0);
            return null;
        }

        // FeatureKey zu Ident auflösen
        $identMap = array_flip($deviceMapping['mapping']);
        if (!isset($identMap[$featureKey])) {
            $this->SendDebug(__FUNCTION__, "❌ Kein Mapping für Feature $featureKey in GUID $guid", 0);
            return null;
        }

        $expectedIdent = $identMap[$featureKey];
        $varID = @IPS_GetObjectIDByIdent($expectedIdent, $instanceID);

        if (!$varID || !IPS_VariableExists($varID)) {
            $this->SendDebug(__FUNCTION__, "❌ Variable mit Ident $expectedIdent nicht gefunden in Instanz $instanceID", 0);
            return null;
        }

        return $varID;
    }

    /**
     * Extracts known client session IPs for use in the whitelist select field.
     *
     * @return array Array of ['value' => IP, 'caption' => string]
     */
    private function GetKnownClientIPOptions(): array
    {
        $sessions = $this->readSessions();
        $options = [];

        foreach ($sessions as $clientKey => $info) {
            $clientKey = (string)$clientKey;
            $ip = $clientKey;
            $this->SendDebug(__FUNCTION__, '🔎 Option source key=' . $clientKey . ' (colons=' . substr_count($clientKey, ':') . ')', 0);

            // Key format: [IPv6]:port
            if (preg_match('/^\[(.+)]:(\d+)$/', $clientKey, $m)) {
                $ip = $m[1];
            } // Key format: IPv4:port (exactly one colon)
            elseif (substr_count($clientKey, ':') === 1 && preg_match('/^([^:]+):(\d+)$/', $clientKey, $m)) {
                $ip = $m[1];
            }
            // Otherwise: treat as pure IP (IMPORTANT: IPv6 contains many colons)

            $this->SendDebug(__FUNCTION__, '✅ Option parsed ip=' . $ip, 0);
            // Deduplicate
            $existingValues = array_column($options, 'value');
            if (!in_array($ip, $existingValues, true)) {
                $caption = $ip;
                if (is_array($info) && !empty($info['model'])) {
                    $caption .= ' (' . $info['model'] . ')';
                }
                $options[] = [
                    'caption' => $caption,
                    'value' => $ip
                ];
            }
        }

        return $options;
    }

    /**
     * Dumps raw and parsed client_sessions attribute and triggers GetKnownClientIPOptions debug.
     */
    public function DumpClientSessions(): void
    {
        $raw = $this->ReadAttributeString('client_sessions');
        $this->SendDebug(__FUNCTION__, '📦 client_sessions (raw)=' . $raw, 0);

        $parsed = $this->readSessions();
        $this->SendDebug(__FUNCTION__, '📦 client_sessions (parsed)=' . json_encode($parsed), 0);

        $this->GetKnownClientIPOptions(); // triggers detailed option logs
    }

    /**
     * Erkennt den Sensor-Typ einer Variable anhand des Profils und gibt diesen per Debug aus.
     * Nutzt ausschließlich die übergebene Variable-ID und greift nicht auf Mapping oder RowIndex zu.
     *
     * @param int $VariableID
     */
    public function AutoDetectSensorType(int $VariableID): void
    {
        $this->SendDebug(__FUNCTION__, "🔍 Auto-Erkennung Sensor-Typ für VarID $VariableID", 0);

        if (!IPS_VariableExists($VariableID)) {
            $this->SendDebug(__FUNCTION__, "❌ Variable $VariableID existiert nicht", 0);
            return;
        }

        $v = IPS_GetVariable($VariableID);
        $profile = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
        $profile = strtolower($profile);

        $type = 'generic';

        if (strpos($profile, 'temp') !== false || strpos($profile, '°c') !== false) {
            $type = 'temperature';
        } elseif (strpos($profile, 'humid') !== false) {
            $type = 'humidity';
        } elseif (strpos($profile, 'lux') !== false || strpos($profile, 'illum') !== false) {
            $type = 'illuminance';
        } elseif (strpos($profile, 'volt') !== false) {
            $type = 'voltage';
        }

        $this->SendDebug(__FUNCTION__, "✅ Ermittelter Typ für Profil '$profile': $type", 0);
        $this->UpdateFormField("sensor_type", "value", $type);
        $this->UpdateFormField("sensor_type", "visible", true);

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

    private function SendDebugExtended(string $function, string $message, int $format): void
    {
        if ($this->ReadPropertyBoolean('extended_debug')) {
            $this->SendDebug($function, $message, $format);
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
    protected function FormHead()
    {
        $this->EnsureTokenInitialized();
        $token = $this->ReadAttributeString('token');

        $form = [
            [
                'type' => 'Image',
                'image' => $this->LoadImageAsBase64()
            ],
            [
                'type' => 'ValidationTextBox',
                'name' => 'token',
                'caption' => '🔑 Token',
                'value' => $token,
                'enabled' => false
            ],
            [
                'type' => 'PopupButton',
                'caption' => '🔍 Search for Devices',
                'popup' => [
                    'caption' => '🔍 Device Search',
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'popup_button_suggestions',
                            'caption' => '🔘 Button (Script)',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ],
                        [
                            'type' => 'List',
                            'name' => 'popup_climate_suggestions',
                            'caption' => '🔥 Climate (Thermostat)',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ],
                        [
                            'type' => 'List',
                            'name' => 'popup_cover_suggestions',
                            'caption' => '🪟 Cover (Roller Blind)',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ],
                        [
                            'type' => 'List',
                            'name' => 'popup_light_suggestions',
                            'caption' => '💡 Light (Switch)',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ],
                        [
                            'type' => 'List',
                            'name' => 'popup_media_suggestions',
                            'caption' => '🎵 Media Player',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ],
                        [
                            'type' => 'List',
                            'name' => 'popup_remote_suggestions',
                            'caption' => '🎮 Remote Device',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ],
                        [
                            'type' => 'List',
                            'name' => 'popup_sensor_suggestions',
                            'caption' => '📈 Sensor',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ],
                        [
                            'type' => 'List',
                            'name' => 'popup_switch_suggestions',
                            'caption' => '💡 Switch (Binary)',
                            'columns' => [
                                ['caption' => 'Register', 'name' => 'register', 'width' => '250px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => '📦 Object', 'name' => 'label', 'width' => 'auto'],
                                ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ],
                            'add' => false,
                            'delete' => false,
                            'rowCount' => 8
                        ]
                    ],
                    'buttons' => [
                        [
                            'type' => 'Button',
                            'caption' => '➕ Add Devices',
                            'onClick' => 'UCR_ApplySuggestedDevices($id);'
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '🟢 Button Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'button_mapping',
                        'caption' => 'Button Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '200px',
                                'add' => 'Button',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Script',
                                'name' => 'script_id',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectScript'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '💡 Switch Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'switch_mapping',
                        'caption' => 'Switch Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '200px',
                                'add' => 'Switch',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => 'auto',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ],
                            [
                                'caption' => 'Variable',
                                'name' => 'var_id',
                                'width' => '800px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable',
                                    'filters' => [
                                        [
                                            'caption' => 'Nur boolsche Variablen',
                                            'expression' => 'is_bool($Variable["VariableType"])'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '🔥 Climate Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'climate_mapping',
                        'caption' => 'Climate Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => 'auto',
                                'add' => 'Climate',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => '400px',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ],
                            [
                                'caption' => 'Status Variable',
                                'name' => 'status_var_id',
                                'width' => '400px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ],
                            [
                                'caption' => 'Current Temperature Variable',
                                'name' => 'current_temp_var_id',
                                'width' => '400px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ],
                            [
                                'caption' => 'Target Temperature Variable',
                                'name' => 'target_temp_var_id',
                                'width' => '400px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ],
                            [
                                'caption' => 'Mode Variable',
                                'name' => 'mode_var_id',
                                'width' => '400px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '🪟 Cover Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'cover_mapping',
                        'caption' => 'Cover Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '400px',
                                'add' => 'Cover',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => '400px',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ],
                            [
                                'caption' => 'Position Variable',
                                'name' => 'position_var_id',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ],
                            [
                                'caption' => 'Control Variable',
                                'name' => 'control_var_id',
                                'width' => '650px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '📡 IR Emitter Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'ir_mapping',
                        'caption' => 'IR Emitter Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '200px',
                                'add' => 'IR Emitter',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => '400px',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ],
                            [
                                'caption' => 'Variable',
                                'name' => 'var_id',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '💡 Light Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'light_mapping',
                        'caption' => 'Light Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => 'auto',
                                'add' => 'Light',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => '400px',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ],
                            [
                                'caption' => 'Switch Variable',
                                'name' => 'switch_var_id',
                                'width' => '250px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ],
                            [
                                'caption' => 'Brightness Variable',
                                'name' => 'brightness_var_id',
                                'width' => '250px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ],
                            [
                                'caption' => 'Color Variable',
                                'name' => 'color_var_id',
                                'width' => '250px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ],
                            [
                                'caption' => 'Color Temperature Variable',
                                'name' => 'color_temp_var_id',
                                'width' => '250px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            // Media Player
            [
                'type' => 'ExpansionPanel',
                'caption' => '🎵 Media Player Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'media_player_mapping',
                        'caption' => 'Media Player Mapping',
                        'add' => true,
                        'delete' => true,
                        'rowCount' => 5,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '300px',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ],
                                'add' => 'New Media Player',
                                'save' => true
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => '400px',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ],
                                'save' => true
                            ],
                            [
                                'caption' => 'Device Class',
                                'name' => 'device_class',
                                'width' => 'auto',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => [
                                        ['caption' => 'receiver', 'value' => 'receiver'],
                                        ['caption' => 'set_top_box', 'value' => 'set_top_box'],
                                        ['caption' => 'speaker', 'value' => 'speaker'],
                                        ['caption' => 'streaming_box', 'value' => 'streaming_box'],
                                        ['caption' => 'tv', 'value' => 'tv']
                                    ]
                                ],
                                'add' => 'speaker',
                                'save' => true
                            ],
                            [
                                'caption' => 'Features',
                                'name' => 'features',
                                'width' => '300px',
                                'edit' => [
                                    'type' => 'List',
                                    'rowCount' => 10,
                                    'columns' => [
                                        [
                                            'caption' => 'Name',
                                            'name' => 'feature_name',
                                            'width' => '300px',
                                            'save' => true
                                        ],
                                        [
                                            'caption' => 'Attribute',
                                            'name' => 'attribute_key',
                                            'width' => '300px',
                                            'save' => true
                                        ],
                                        [
                                            'caption' => 'Feature Key',
                                            'name' => 'feature_key',
                                            'width' => '10px',
                                            'save' => true,
                                            'visible' => false
                                        ],
                                        [
                                            'caption' => 'Description',
                                            'name' => 'description',
                                            'width' => 'auto',
                                            'save' => false
                                        ],
                                        [
                                            'caption' => 'Variable',
                                            'name' => 'var_id',
                                            'width' => '350px',
                                            'edit' => [
                                                'type' => 'SelectVariable'
                                            ],
                                            'add' => 0,
                                            'save' => true
                                        ]
                                    ],
                                    'values' => [
                                        ['feature_name' => 'State', 'attribute_key' => Entity_Media_Player::ATTR_STATE, 'feature_key' => Entity_Media_Player::FEATURE_ON_OFF, 'description' => 'State of the media player'],
                                        ['feature_name' => 'Volume', 'attribute_key' => Entity_Media_Player::ATTR_VOLUME, 'feature_key' => Entity_Media_Player::FEATURE_VOLUME, 'description' => 'Current volume level (0–100)'],
                                        ['feature_name' => 'Muted', 'attribute_key' => Entity_Media_Player::ATTR_MUTED, 'feature_key' => Entity_Media_Player::FEATURE_MUTE, 'description' => 'Mute status of the player'],
                                        ['feature_name' => 'Navigation Control', 'attribute_key' => 'symcon_control', 'feature_key' => 'symcon_control', 'description' => 'Playback Control (Play/Pause/ Stop)'],
                                        ['feature_name' => 'Repeat', 'attribute_key' => Entity_Media_Player::ATTR_REPEAT, 'feature_key' => Entity_Media_Player::FEATURE_REPEAT, 'description' => 'Repeat mode: OFF, ALL, ONE'],
                                        ['feature_name' => 'Shuffle', 'attribute_key' => Entity_Media_Player::ATTR_SHUFFLE, 'feature_key' => Entity_Media_Player::FEATURE_SHUFFLE, 'description' => 'Shuffle mode: on/off'],
                                        ['feature_name' => 'Duration', 'attribute_key' => Entity_Media_Player::ATTR_MEDIA_DURATION, 'feature_key' => Entity_Media_Player::FEATURE_MEDIA_DURATION, 'description' => 'Duration of the current media (in seconds)'],
                                        ['feature_name' => 'Position', 'attribute_key' => Entity_Media_Player::ATTR_MEDIA_POSITION, 'feature_key' => Entity_Media_Player::FEATURE_MEDIA_POSITION, 'description' => 'Playback position (in seconds)'],
                                        ['feature_name' => 'Title', 'attribute_key' => Entity_Media_Player::ATTR_MEDIA_TITLE, 'feature_key' => Entity_Media_Player::FEATURE_MEDIA_TITLE, 'description' => 'Title of the current media'],
                                        ['feature_name' => 'Artist', 'attribute_key' => Entity_Media_Player::ATTR_MEDIA_ARTIST, 'feature_key' => Entity_Media_Player::FEATURE_MEDIA_ARTIST, 'description' => 'Artist of the current media'],
                                        ['feature_name' => 'Album', 'attribute_key' => Entity_Media_Player::ATTR_MEDIA_ALBUM, 'feature_key' => Entity_Media_Player::FEATURE_MEDIA_ALBUM, 'description' => 'Album of the current media'],
                                        ['feature_name' => 'Image', 'attribute_key' => Entity_Media_Player::ATTR_MEDIA_IMAGE_URL, 'feature_key' => Entity_Media_Player::FEATURE_MEDIA_IMAGE_URL, 'description' => 'URL of image representing the media'],
                                        ['feature_name' => 'Type', 'attribute_key' => Entity_Media_Player::ATTR_MEDIA_TYPE, 'feature_key' => Entity_Media_Player::FEATURE_MEDIA_TYPE, 'description' => 'Type of media being played'],
                                        ['feature_name' => 'Direction Pad', 'attribute_key' => 'symcon_dpad', 'feature_key' => Entity_Media_Player::FEATURE_DPAD, 'description' => 'Directional pad navigation, provides up / down / left / right / enter commands.'],
                                        ['feature_name' => 'Number Pad', 'attribute_key' => 'symcon_numpad', 'feature_key' => Entity_Media_Player::FEATURE_NUMPAD, 'description' => 'Number pad, provides digit_0, .. , digit_9 commands'],
                                        ['feature_name' => 'Commands', 'attribute_key' => 'symcon_commands', 'feature_key' => Entity_Media_Player::FEATURE_HOME, 'description' => 'Commands like Home, Menu, Guide, Info; color ButonsList of available input/media sources'],
                                        ['feature_name' => 'Channel', 'attribute_key' => 'symcon_channel', 'feature_key' => Entity_Media_Player::FEATURE_CHANNEL_SWITCHER, 'description' => 'Channels'],
                                        ['feature_name' => 'Source', 'attribute_key' => Entity_Media_Player::ATTR_SOURCE, 'feature_key' => Entity_Media_Player::FEATURE_SELECT_SOURCE, 'description' => 'Current input or media source'],
                                        ['feature_name' => 'Sound Mode', 'attribute_key' => Entity_Media_Player::ATTR_SOUND_MODE, 'feature_key' => Entity_Media_Player::FEATURE_SELECT_SOUND_MODE, 'description' => 'Current sound mode']
                                    ]
                                ],
                                'add' => []
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '🎮 Remote Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'remote_mapping',
                        'caption' => 'Remote Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '200px',
                                'add' => 'Remote',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => '400px',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ],
                            [
                                'caption' => 'Variable',
                                'name' => 'var_id',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '📈 Sensor Assignment',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'sensor_mapping',
                        'caption' => 'Sensor Instances',
                        'rowCount' => 5,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'Name',
                                'name' => 'name',
                                'width' => '400px',
                                'add' => 'Sensor',
                                'edit' => [
                                    'type' => 'ValidationTextBox'
                                ]
                            ],
                            [
                                'caption' => 'Instance ID',
                                'name' => 'instance_id',
                                'width' => '400px',
                                'add' => 0,
                                'edit' => [
                                    'type' => 'SelectInstance'
                                ]
                            ],
                            [
                                'caption' => 'Variable',
                                'name' => 'var_id',
                                'width' => 'auto',
                                'add' => '',
                                'edit' => [
                                    'type' => 'SelectVariable',
                                    'onChange' => 'UCR_AutoDetectSensorType($id, $var_id);'
                                ]
                            ],
                            [
                                'caption' => 'Sensor Type',
                                'name' => 'sensor_type',
                                'width' => '200px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => [
                                        ['caption' => 'Bitte auswählen...', 'value' => ''],
                                        ['caption' => 'temperature', 'value' => 'temperature'],
                                        ['caption' => 'humidity', 'value' => 'humidity'],
                                        ['caption' => 'illuminance', 'value' => 'illuminance'],
                                        ['caption' => 'voltage', 'value' => 'voltage'],
                                        ['caption' => 'generic', 'value' => 'generic']
                                    ],
                                    'visible' => false
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '🧾 Client Session Log',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'session_log',
                        'caption' => 'Clients',
                        'rowCount' => 6,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            ['caption' => 'Remote Name', 'name' => 'name', 'width' => '150px'],
                            ['caption' => 'Version', 'name' => 'version', 'width' => '100px'],
                            ['caption' => 'API Version', 'name' => 'api_version', 'width' => '100px'],
                            ['caption' => 'Model', 'name' => 'model', 'width' => '150px'],
                            ['caption' => 'IP Address', 'name' => 'ip', 'width' => '150px'],
                            ['caption' => 'Port', 'name' => 'port', 'width' => '80px'],
                            ['caption' => 'Authenticated', 'name' => 'authenticated', 'width' => '120px'],
                            ['caption' => 'Last Seen', 'name' => 'last_seen', 'width' => 'auto']
                        ],
                        'values' => $this->FormatSessionList()
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => '🔐 IP Whitelist (temporary access)',
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'ip_whitelist',
                        'caption' => 'Allowed IP Addresses',
                        'rowCount' => 3,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'caption' => 'IP Address',
                                'name' => 'ip',
                                'width' => '300px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => $this->GetKnownClientIPOptions()
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            // EXPANSION PANEL: Expert Settings
            [
                'type' => 'ExpansionPanel',
                'caption' => '⚙️ Expert Settings',
                'items' => [
                    [
                        'type' => 'Label',
                        'caption' => 'This driver communicates via TCP port 9988.'
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'If Symcon is running inside a Docker container, this port must be mapped externally.'
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'extended_debug',
                        'caption' => 'Enable extended debug output'
                    ],
                    [
                        'type' => 'Button',
                        'caption' => '🔧 Manually register driver with Remote 3',
                        'onClick' => 'UCR_RegisterDriverManually($id);'
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'callback_IP',
                        'caption' => 'Callback IP (IP of Symcon Server, only needed if automatic DNS name is not working)'
                    ],
                    [
                        'type' => 'Button',
                        'caption' => '🧪 Debug: Dump client_sessions',
                        'onClick' => 'UCR_DumpClientSessions($id);'
                    ]
                ]
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
                'caption' => '🔄 Generate new token',
                'onClick' => 'UCR_GenerateToken($id);'
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
                'caption' => '🛠 Creating instance'],
            [
                'code' => IS_ACTIVE,
                'icon' => 'active',
                'caption' => '✅ Remote 3 Integration Driver created'],
            [
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => '🔌 Interface closed']];

        return $form;
    }
}



