<?php

declare(strict_types=1);

class Remote3DockManager extends IPSModule
{
    private function EnsureApiKey(): bool
    {
        $apiKey = $this->ReadAttributeString('api_key');
        if ($apiKey != '') {
            return true;
        }

        $host = $this->ReadPropertyString('host');
        $url = "http://$host/api/auth/api_keys";
        $user = $this->ReadPropertyString('web_config_user');
        $pass = $this->ReadPropertyString('web_config_pass');

        $headers = ['Content-Type: application/json'];
        $body = json_encode([
            'name' => 'Symcon Remote Access',
            'scopes' => ['admin'],
            'description' => 'Created from Symcon module'
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $user . ':' . $pass,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $this->SendDebug(__FUNCTION__, "📥 HTTP-Code: $httpCode", 0);
        $this->SendDebug(__FUNCTION__, "📥 Response: $response", 0);

        if ($error !== '') {
            $this->SendDebug(__FUNCTION__, "❌ CURL Error: $error", 0);
            return false;
        }

        $data = json_decode($response, true);
        if (isset($data['api_key'])) {
            $this->WriteAttributeString('api_key', $data['api_key']);
            $this->SendDebug(__FUNCTION__, '✅ API-Key gespeichert.', 0);
            return true;
        }

        $this->SendDebug(__FUNCTION__, '❌ Kein API-Key erhalten.', 0);
        return false;
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString('api_key', '');
        $this->RegisterAttributeString('auth_mode', '');
        $this->RegisterPropertyString('hostname', '');
        $this->RegisterPropertyString('host', '');
        $this->RegisterPropertyString('port', '');
        $this->RegisterPropertyString('https_port', '');
        $this->RegisterPropertyString('ws_path', '');
        $this->RegisterPropertyString('ws_port', '');
        $this->RegisterPropertyString('ws_host', '');
        $this->RegisterPropertyString('ws_https_port', '');
        $this->RegisterPropertyString('ws_https_host', '');
        $this->RegisterAttributeString('ws_auth_mode', '');
        $this->RegisterAttributeString('ws_api_key', '');

        $this->RegisterPropertyString('web_config_user', 'web-configurator');
        $this->RegisterPropertyString('web_config_pass', '');


        $this->ConnectParent('{D68FD31F-0E90-7019-F16C-1949BD3079EF}'); // Websocket Client

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

    }

    public function ForwardData($JSONString)
    {
        $this->SendDebug(__FUNCTION__, '📥 Eingehende Daten: ' . $JSONString, 0);

        $data = json_decode($JSONString);

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
                return $this->CallGetVersion($buffer);
            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannte Methode: $method", 0);
                return json_encode(['error' => 'Unbekannte Methode']);
        }
        // $this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => $data->Buffer]));
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug(__FUNCTION__, $JSONString, 0);
        $data = json_decode($JSONString);


        // $this->SendDataToChildren(json_encode(['DataID' => '{76BD37C4-C1A4-AA3A-4AFF-599D64F5E989}', 'Buffer' => $data->Buffer]));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SendDebug(__FUNCTION__, 'Kernel READY', 0);
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

        return json_decode($result, true);
    }

    protected function CallGetVersion($data)
    {
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /pub/version...', 0);
        $response = $this->SendRestRequest('GET', '/pub/version');

        if (!is_array($response)) {
            $this->SendDebug(__FUNCTION__, '❌ Ungültige Antwortstruktur.', 0);
            return json_encode(['success' => false, 'message' => 'Invalid response']);
        }

        $this->SendDebug(__FUNCTION__, '✅ Antwort erhalten: ' . json_encode($response), 0);
        return json_encode(['success' => true, 'data' => $response]);
    }

    public function CallGetSystem()
    {
        // GET /api/system
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /system...', 0);
    }

    public function CallGetStatus()
    {
        // GET /api/pub/status
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /pub/status...', 0);
    }

    public function CallGetHealthCheck()
    {
        // GET /api/pub/health_check
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /pub/health_check...', 0);
    }


    public function CallGetNetworkConfig()
    {
        // GET /api/cfg/network
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /cfg/network...', 0);
    }

    public function CallGetDisplayConfig()
    {
        // GET /api/cfg/display
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /cfg/display...', 0);
    }

    public function CallGetSoundConfig()
    {
        // GET /api/cfg/sound
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /cfg/sound...', 0);
    }

    public function CallGetRemotes()
    {
        // GET /api/remotes
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /remotes...', 0);
    }

    public function CallGetEntities()
    {
        // GET /api/entities
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /entities...', 0);
    }

    public function CallGetActivities()
    {
        // GET /api/activities
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /activities...', 0);
    }

    public function CallGetIntg()
    {
        // GET /api/intg
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /intg...', 0);
    }

    public function CallGetDock()
    {
        // GET /api/dock
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /dock...', 0);
    }

    public function CallGetDockDiscovery()
    {
        // GET /api/dock/discovery
        $this->SendDebug(__FUNCTION__, '⏳ Requesting /dock/discovery...', 0);
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
        $form = [
            [
                'type' => 'ValidationTextBox',
                'name' => 'web_config_pass',
                'caption' => 'Web-Konfigurator Passwort'
            ],
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
        $form = [];
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
                'caption' => 'Remote 3 Dock Manager created.'],
            [
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => 'interface closed.']];

        return $form;
    }
}