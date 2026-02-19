<?php

declare(strict_types=1);

class Remote3Device extends IPSModule
{
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

        $this->RegisterAttributeString('last_status_response', '');
        $this->RegisterAttributeString('scopes', '');
        $this->RegisterAttributeString('external_systems', '');
        $this->RegisterAttributeString('system_information', '');
        $this->RegisterAttributeString('battery_state', '');
        $this->RegisterAttributeString('activities', '');
        $this->RegisterAttributeString('activity_mapping', '');

        $this->RequireParent('{C810D534-2395-7C43-D0BE-6DEC069B2516}');

        $this->RegisterVariableProfiles();
        $this->UpdateActivityProfile();

        // Register OnlineStatus variable
        $this->MaintainVariable('OnlineStatus', 'Online Status', VARIABLETYPE_BOOLEAN, 'UCR.OnlineStatus', 10, true);
        $this->MaintainVariable('DeviceName', 'Gerätename', VARIABLETYPE_STRING, '', 20, true);
        $this->MaintainVariable('Hostname', 'Hostname', VARIABLETYPE_STRING, '', 30, true);
        $this->MaintainVariable('Model', 'Modell', VARIABLETYPE_STRING, '', 40, true);


        $this->MaintainVariable('MacAddress', 'MAC-Adresse', VARIABLETYPE_STRING, '', 50, true);
        $this->MaintainVariable('ApiVersion', 'API-Version', VARIABLETYPE_STRING, '', 60, true);
        $this->MaintainVariable('CoreVersion', 'Core-Version', VARIABLETYPE_STRING, '', 70, true);
        $this->MaintainVariable('UiVersion', 'UI-Version', VARIABLETYPE_STRING, '', 80, true);
        $this->MaintainVariable('OsVersion', 'OS-Version', VARIABLETYPE_STRING, '', 90, true);

        $this->MaintainVariable('HealthDB', 'Status Datenbank', VARIABLETYPE_STRING, '', 100, true);
        $this->MaintainVariable('HealthUI', 'Status Benutzeroberfläche', VARIABLETYPE_STRING, '', 110, true);
        $this->MaintainVariable('HealthStorage', 'Status Speicher', VARIABLETYPE_STRING, '', 120, true);

        $this->MaintainVariable('RamUsed', 'RAM belegt (MB)', VARIABLETYPE_FLOAT, '', 200, true);
        $this->MaintainVariable('RamFree', 'RAM frei (MB)', VARIABLETYPE_FLOAT, '', 210, true);

        $this->MaintainVariable('DiskUsed', 'Speicher belegt (MB)', VARIABLETYPE_FLOAT, '', 300, true);
        $this->MaintainVariable('DiskFree', 'Speicher frei (MB)', VARIABLETYPE_FLOAT, '', 310, true);

        $this->MaintainVariable('CpuLoad1Min', 'CPU-Last 1 Minute', VARIABLETYPE_FLOAT, 'UCR.CpuPercent', 400, true);
        $this->MaintainVariable('CpuLoad5Min', 'CPU-Last 5 Minuten', VARIABLETYPE_FLOAT, 'UCR.CpuPercent', 410, true);

        // Battery variables
        $this->MaintainVariable('BatteryCapacity', 'Batteriekapazität (%)', VARIABLETYPE_INTEGER, '', 500, true);
        $this->MaintainVariable('BatteryStatus', 'Batteriestatus', VARIABLETYPE_STRING, '', 510, true);
        $this->MaintainVariable('BatteryPowered', 'Externe Stromversorgung', VARIABLETYPE_BOOLEAN, '', 520, true);

        // Register variable for selected activity
        $this->MaintainVariable('ActiveActivity', 'Aktive Aktivität', VARIABLETYPE_INTEGER, 'UCR.ActivityProfile', 600, true);
        $this->EnableAction('ActiveActivity');

        //We need to call the RegisterHook function on Kernel READY
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    /**
     * Dynamically update activity variable profile based on activities attribute.
     */
    protected function UpdateActivityProfile()
    {
        $profileName = 'UCR.ActivityProfile';

        // Ensure profile exists and is of correct type
        if (IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);
            if ($profile['ProfileType'] !== VARIABLETYPE_INTEGER) {
                IPS_DeleteVariableProfile($profileName);
                IPS_CreateVariableProfile($profileName, VARIABLETYPE_INTEGER);
            }
        } else {
            IPS_CreateVariableProfile($profileName, VARIABLETYPE_INTEGER);
        }

        // Reset any previous associations
        $associations = IPS_GetVariableProfile($profileName)['Associations'] ?? [];
        foreach ($associations as $assoc) {
            IPS_SetVariableProfileAssociation($profileName, $assoc['Value'], '', '', -1);
        }

        $activitiesRaw = json_decode($this->ReadAttributeString('activities'), true);
        $mapping = [];

        if (is_array($activitiesRaw)) {
            foreach (array_values($activitiesRaw) as $index => $activity) {
                if (isset($activity['entity_id']) && isset($activity['name']['de'])) {
                    $mapping[$index] = $activity['entity_id'];
                    IPS_SetVariableProfileAssociation($profileName, $index, $activity['name']['de'], '', -1);
                }
            }
        }

        $this->WriteAttributeString('activity_mapping', json_encode($mapping));
    }

    /**
     * Register custom variable profiles if not already existing.
     */
    protected function RegisterVariableProfiles()
    {
        // CPU Percent Profile
        $profile = 'UCR.CpuPercent';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits($profile, 1);
            IPS_SetVariableProfileText($profile, '', ' %');
        }
        // OnlineStatus Profile
        $profile = 'UCR.OnlineStatus';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation($profile, 1, 'Online', '', 0x00FF00);
            IPS_SetVariableProfileAssociation($profile, 0, 'Standby', '', 0xFF0000);
        }
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

        if (IPS_GetKernelRunlevel() == KR_READY) {
            // check if variables are empty
            $this->CheckVariables();
        }
    }

    protected function CheckVariables()
    {
        $needInit = false;
        $varsToCheck = [
            'Model',
            'DeviceName',
            'Hostname',
            'MacAddress',
            'ApiVersion',
            'CoreVersion',
            'UiVersion',
            'OsVersion'
        ];

        foreach ($varsToCheck as $ident) {
            if ($this->GetValue($ident) === '') {
                $needInit = true;
                break;
            }
        }

        if ($needInit) {
            $this->SendDebug(__FUNCTION__, '🟡 Initialisiere Variablen durch RequestVersion()', 0);
            $this->RequestVersion();
        } else {
            $this->SendDebug(__FUNCTION__, '✅ Alle Versionsvariablen enthalten bereits Werte.', 0);
        }

        // Optional: unabhängig prüfen, ob Statusdaten fehlen
        if ($this->ReadAttributeString('last_status_response') === '') {
            $this->SendDebug(__FUNCTION__, '🟡 Initialisiere Statusdaten durch RequestStatus()', 0);
            $this->RequestStatus();
        } else {
            $this->SendDebug(__FUNCTION__, '✅ Statusdaten bereits vorhanden.', 0);
        }
    }


    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        //Never delete this line!
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->CheckVariables();
        }
    }

    public function RequestAction($ident, $value)
    {
        $this->SendDebug(__FUNCTION__, "RequestAction gestartet für Ident: $ident, Wert: $value", 0);

        switch ($ident) {
            case 'ActiveActivity':
                $mapping = json_decode($this->ReadAttributeString('activity_mapping'), true);
                if (is_array($mapping) && isset($mapping[$value])) {
                    $activityId = $mapping[$value];
                    $this->TriggerActivity($activityId);
                } else {
                    $this->SendDebug(__FUNCTION__, '❌ Kein gültiges Mapping für den Index gefunden.', 0);
                }
                break;
        }
    }

    protected function TriggerActivity(string $activityId, string $state = 'on')
    {
        $this->SendDebug(__FUNCTION__, "🔁 Aktivität auslösen: $activityId (state: $state)", 0);

        $cmd = $state === 'off' ? 'activity.off' : 'activity.on';

        $params = [
            'entity_id' => $activityId,
            'cmd_id' => $cmd
        ];

        $result = $this->SendDataToRemoteCore('CallSendEntityCommand', $params);

        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler beim Triggern der Aktivität.', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, '✅ Aktivität erfolgreich ausgeführt.', 0);
    }

    /**
     * Trigger an activity by its name and language, with optional state.
     *
     * @param string $activityName Name of the activity
     * @param string $lang Language (default: 'de')
     * @param string $state State to set ('on' or 'off', default: 'on')
     */
    public function TriggerActivityByName(string $activityName, string $lang = 'de', string $state = 'on')
    {
        $this->SendDebug(__FUNCTION__, "🔁 Aktivität anhand Name auslösen: $activityName (Sprache: $lang, Zustand: $state)", 0);

        $activitiesRaw = json_decode($this->ReadAttributeString('activities'), true);

        if (!is_array($activitiesRaw)) {
            $this->SendDebug(__FUNCTION__, '❌ Keine Aktivitäten verfügbar.', 0);
            return;
        }

        foreach ($activitiesRaw as $activity) {
            if (($activity['name'][$lang] ?? '') === $activityName) {
                $activityId = $activity['entity_id'];
                $this->SendDebug(__FUNCTION__, "➡️ Gefundene ID: $activityId", 0);
                $this->TriggerActivity($activityId, $state);
                return;
            }
        }

        $this->SendDebug(__FUNCTION__, "❌ Keine Aktivität mit dem Namen '$activityName' für Sprache '$lang' gefunden.", 0);
    }

    public function RequestVersion()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetVersion an Splitter', 0);

        $result = $this->SendDataToRemoteCore('CallGetVersion');

        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }

        $versionData = $result['data'];
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($versionData), 0);

        $this->SetValue('Model', $versionData['model'] ?? '');
        $this->SetValue('DeviceName', $versionData['device_name'] ?? '');
        $this->SetValue('Hostname', $versionData['hostname'] ?? '');
        $this->SetValue('MacAddress', $versionData['address'] ?? '');
        $this->SetValue('ApiVersion', $versionData['api'] ?? '');
        $this->SetValue('CoreVersion', $versionData['core'] ?? '');
        $this->SetValue('UiVersion', $versionData['ui'] ?? '');
        $this->SetValue('OsVersion', $versionData['os'] ?? '');

        return $versionData;
    }


    public function RequestStatus()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetStatus an Splitter', 0);

        $result = $this->SendDataToRemoteCore('CallGetStatus');

        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }

        $statusData = $result['data'];
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($statusData), 0);

        // Write complete JSON to attribute
        $this->WriteAttributeString('last_status_response', json_encode($statusData));

        $cpuPercent_one = round(($statusData['load_avg']['one'] ?? 0) / 4 * 100, 1);
        $cpuPercent_five = round(($statusData['load_avg']['five'] ?? 0) / 4 * 100, 1);

        // Extract and set key values as variables (converted to MB where appropriate)
        $this->SetValue('RamUsed', isset($statusData['memory']['used_memory']) ? round($statusData['memory']['used_memory'] / 1024 / 1024, 1) : 0);
        $this->SetValue('RamFree', isset($statusData['memory']['available_memory']) ? round($statusData['memory']['available_memory'] / 1024 / 1024, 1) : 0);
        $this->SetValue('CpuLoad1Min', $cpuPercent_one);
        $this->SetValue('CpuLoad5Min', $cpuPercent_five);
        $this->SetValue('DiskUsed', isset($statusData['filesystem']['user_data']['used']) ? round($statusData['filesystem']['user_data']['used'] / 1024 / 1024, 1) : 0);
        $this->SetValue('DiskFree', isset($statusData['filesystem']['user_data']['available']) ? round($statusData['filesystem']['user_data']['available'] / 1024 / 1024, 1) : 0);
        return $statusData;
    }


    public function RequestHealthCheck()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetHealthCheck an Splitter', 0);

        $result = $this->SendDataToRemoteCore('CallGetHealthCheck');

        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }

        $health = $result['data'];
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($health), 0);

        $this->MaintainVariable('HealthDB', 'Status Datenbank', VARIABLETYPE_STRING, '', 101, true);
        $this->MaintainVariable('HealthUI', 'Status Benutzeroberfläche', VARIABLETYPE_STRING, '', 102, true);
        $this->MaintainVariable('HealthStorage', 'Status Speicher', VARIABLETYPE_STRING, '', 103, true);

        $this->SetValue('HealthDB', $health['db'] ?? '');
        $this->SetValue('HealthUI', $health['ui'] ?? '');
        $this->SetValue('HealthStorage', $health['storage'] ?? '');

        return $health;
    }

    /**
     * Request available scopes from core and store in attribute
     */
    public function RequestScopes()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetScopes an Splitter', 0);
        $result = $this->SendDataToRemoteCore('CallGetScopes');
        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($result['data']), 0);
        $this->WriteAttributeString('scopes', json_encode($result['data']));
        return $result['data'];
    }

    /**
     * Request registered external systems
     */
    public function RequestExternalSystems()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetExternalSystems an Splitter', 0);
        $result = $this->SendDataToRemoteCore('CallGetExternalSystems');
        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($result['data']), 0);
        $this->WriteAttributeString('external_systems', json_encode($result['data']));
        return $result['data'];
    }

    /**
     * Request System Infomation
     */
    public function RequestSystemInformation()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetSystemInformation an Splitter', 0);
        $result = $this->SendDataToRemoteCore('CallGetSystemInformation');
        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($result['data']), 0);
        $this->WriteAttributeString('system_information', json_encode($result['data']));
        return $result['data'];
    }

    /**
     * Request Battery State and update variables
     */
    public function RequestBatteryState()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetBatteryState an Splitter', 0);
        $result = $this->SendDataToRemoteCore('CallGetBatteryState');
        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($result['data']), 0);
        $this->WriteAttributeString('battery_state', json_encode($result['data']));
        // Set battery variables
        $this->SetValue('BatteryCapacity', $result['data']['capacity'] ?? 0);
        $this->SetValue('BatteryStatus', $result['data']['status'] ?? '');
        $this->SetValue('BatteryPowered', $result['data']['power_supply'] ?? false);
        return $result['data'];
    }

    /**
     * Request Activities
     */
    public function RequestActivities()
    {
        $this->SendDebug(__FUNCTION__, '📡 Anfrage: CallGetActivities an Splitter', 0);
        $result = $this->SendDataToRemoteCore('CallGetActivities');
        if (!isset($result['success']) || !$result['success']) {
            $this->SendDebug(__FUNCTION__, '❌ Fehler bei API-Aufruf', 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, '📦 Antwortdaten: ' . json_encode($result['data']), 0);
        $this->WriteAttributeString('activities', json_encode($result['data']));
        // Update activity profile after activities are refreshed
        $this->UpdateActivityProfile();
        return $result['data'];
    }

    protected function SendDataToRemoteCore(string $method, $params = [])
    {
        // Special handling for CallSendEntityCommand to adjust payload per command
        if ($method === 'CallSendEntityCommand') {
            $entityId = $params['entity_id'] ?? null;
            $cmdId = $params['cmd_id'] ?? null;
            if (in_array($cmdId, ['activity.on', 'activity.off'])) {
                $payload = [
                    'entity_id' => $entityId,
                    'cmd_id' => $cmdId
                ];
            } else {
                $payload = [
                    'cmd_id' => $cmdId
                ];
            }
            $params = $payload;
        }
        $data = [
            'DataID' => '{AC2A1323-0258-76DC-5AA8-9B0C092820A5}',
            'Buffer' => [
                'method' => $method,
                'params' => $params
            ]
        ];
        $this->SendDebug(__FUNCTION__, json_encode($data), 0);
        $response = $this->SendDataToParent(json_encode($data));
        $result = json_decode($response, true);
        return $result;
    }


    /**
     * Empfängt gezielt Driver Events vom Integration Driver und aktualisiert den OnlineStatus entsprechend.
     *
     * @param array $eventData
     */
    public function ReceiveDriverEvent(array $eventData): void
    {
        $this->SendDebug(__FUNCTION__, '🔔 Driver Event empfangen: ' . json_encode($eventData), 0);

        $msg = $eventData['msg'] ?? '';

        if ($msg === 'connect') {
            $this->SetValue('OnlineStatus', true);

            // Alle Daten abfragen
            $this->RequestVersion();
            $this->RequestStatus();
            $this->RequestHealthCheck();
            $this->RequestBatteryState();
            $this->RequestScopes();
            $this->RequestExternalSystems();
            $this->RequestSystemInformation();
            $this->RequestActivities();

            // IO-Instanz über die Parent-Kette neu starten
            $splitterID = IPS_GetParent($this->InstanceID);
            $ioID = IPS_GetParent($splitterID);

            if (IPS_InstanceExists($ioID)) {
                if (IPS_GetInstance($ioID)['InstanceStatus'] === IS_ACTIVE) {
                    IPS_SetInstanceStatus($ioID, IS_INACTIVE);
                    IPS_Sleep(1000); // kurze Pause für Reinitialisierung
                }
                IPS_SetInstanceStatus($ioID, IS_ACTIVE);
                $this->SendDebug(__FUNCTION__, '🔌 WebSocket IO (über Parent-Kette) neu initialisiert.', 0);
            }
        } elseif ($msg === 'enter_standby') {
            $this->SetValue('OnlineStatus', false);

            // IO-Instanz über die Parent-Kette deaktivieren
            $splitterID = IPS_GetParent($this->InstanceID);
            $ioID = IPS_GetParent($splitterID);

            if (IPS_InstanceExists($ioID)) {
                IPS_SetInstanceStatus($ioID, IS_INACTIVE);
                $this->SendDebug(__FUNCTION__, '🔌 WebSocket IO (über Parent-Kette) deaktiviert.', 0);
            }
        }

        // Debug-Meldung zum Ergebnis
        $this->SendDebug(__FUNCTION__, "🎯 Status aktualisiert (Online: " . json_encode($this->GetValue('OnlineStatus')) . ")", 0);
    }


    public function ReceiveData($JSONString)
    {
        $this->SendDebug(__FUNCTION__, $JSONString, 0);
        $data = json_decode($JSONString, true);
        if (!isset($data['Buffer']['msg']) || !isset($data['Buffer']['kind'])) {
            return;
        }

        if ($data['Buffer']['kind'] !== 'event') {
            $this->SendDebug(__FUNCTION__, '🔕 Kein Event, wird ignoriert.', 0);
            return;
        }

        $msg = $data['Buffer']['msg'];
        $msgData = $data['Buffer']['msg_data'] ?? [];

        $this->SendDebug(__FUNCTION__, "📨 Event empfangen: $msg", 0);

        switch ($msg) {
            case 'battery_status':
                $this->SendDebug(__FUNCTION__, '🔋 Batterie-Status aktualisieren', 0);
                $this->WriteAttributeString('battery_state', json_encode($msgData));
                $this->SetValue('BatteryCapacity', $msgData['capacity'] ?? 0);
                $this->SetValue('BatteryStatus', $msgData['status'] ?? '');
                $this->SetValue('BatteryPowered', $msgData['power_supply'] ?? false);
                break;

            case 'power_mode_change':
                $mode = $msgData['mode'] ?? '';
                $this->SendDebug(__FUNCTION__, "💤 Power-Mode: $mode", 0);
                $this->SetValue('OnlineStatus', in_array($mode, ['NORMAL', 'IDLE']));
                break;

            default:
                $this->SendDebug(__FUNCTION__, "⚠️ Unbekannter Event-Typ: $msg", 0);
                break;
        }
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
        $scopes = json_decode($this->ReadAttributeString('scopes'), true);
        $external = json_decode($this->ReadAttributeString('external_systems'), true);
        $system = json_decode($this->ReadAttributeString('system_information'), true);
        $battery = json_decode($this->ReadAttributeString('battery_state'), true);

        // Parse activities attribute
        $activitiesRaw = json_decode($this->ReadAttributeString('activities'), true);
        $activities = [];
        if (is_array($activitiesRaw)) {
            foreach ($activitiesRaw as $activity) {
                $activities[] = [
                    'name' => $activity['name']['de'] ?? '',
                    'description' => $activity['description']['de'] ?? '',
                    'entity_id' => $activity['entity_id'] ?? '',
                    'state' => $activity['attributes']['state'] ?? '',
                    'enabled' => isset($activity['enabled']) ? ($activity['enabled'] ? 'Ja' : 'Nein') : ''
                ];
            }
        }

        return [
            [
                'type' => 'Image',
                'image' => $this->LoadImageAsBase64()
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Aktivitäten',
                'items' => [
                    [
                        'type' => 'List',
                        'caption' => 'Verfügbare Aktivitäten',
                        'name' => 'Activities',
                        'rowCount' => 10,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => '250px'],
                            ['caption' => 'Beschreibung', 'name' => 'description', 'width' => 'auto'],
                            ['caption' => 'ID', 'name' => 'entity_id', 'width' => '450px'],
                            ['caption' => 'Status', 'name' => 'state', 'width' => '80px'],
                            ['caption' => 'Aktiviert', 'name' => 'enabled', 'width' => '80px']
                        ],
                        'values' => $activities
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Systeminformationen',
                'items' => [
                    [
                        'type' => 'ValidationTextBox',
                        'caption' => 'Modellname',
                        'name' => 'model_name',
                        'value' => $system['model_name'] ?? '',
                        'enabled' => false
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'caption' => 'Modellnummer',
                        'name' => 'model_number',
                        'value' => $system['model_number'] ?? '',
                        'enabled' => false
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'caption' => 'Seriennummer',
                        'name' => 'serial_number',
                        'value' => $system['serial_number'] ?? '',
                        'enabled' => false
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'caption' => 'Hardware Revision',
                        'name' => 'hw_revision',
                        'value' => $system['hw_revision'] ?? '',
                        'enabled' => false
                    ],
                    [
                        'type' => 'ValidationTextBox',
                        'caption' => 'Herstellungsdatum',
                        'name' => 'manufacturing_date',
                        'value' => $system['manufacturing_date'] ?? '',
                        'enabled' => false
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Verfügbare Scopes',
                'items' => [
                    [
                        'type' => 'List',
                        'caption' => 'Verfügbare Scopes',
                        'name' => 'AvailableScopes',
                        'rowCount' => 10,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => '200px'],
                            ['caption' => 'Beschreibung', 'name' => 'description', 'width' => 'auto']
                        ],
                        'values' => is_array($scopes) ? $scopes : []
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Registrierte externe Systeme',
                'items' => [
                    [
                        'type' => 'List',
                        'caption' => 'Registrierte externe Systeme',
                        'name' => 'ExternalSystems',
                        'rowCount' => 10,
                        'add' => false,
                        'delete' => false,
                        'columns' => [
                            ['caption' => 'System', 'name' => 'system', 'width' => '150px'],
                            ['caption' => 'Name', 'name' => 'name', 'width' => 'auto']
                        ],
                        'values' => is_array($external) ? $external : []
                    ]
                ]
            ]
        ];
    }

    /**
     * return form actions by token
     *
     * @return array
     */
    protected function FormActions()
    {
        return [
            [
                'type' => 'Button',
                'caption' => 'Version abfragen',
                'onClick' => 'UCR_RequestVersion($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Status abfragen',
                'onClick' => 'UCR_RequestStatus($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Systemprüfung abfragen',
                'onClick' => 'UCR_RequestHealthCheck($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Scopes abrufen',
                'onClick' => 'UCR_RequestScopes($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Registrierte externe Systeme abrufen',
                'onClick' => 'UCR_RequestExternalSystems($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'System Information abrufen',
                'onClick' => 'UCR_RequestSystemInformation($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Batterie Status abrufen',
                'onClick' => 'UCR_RequestBatteryState($id);'
            ],
            [
                'type' => 'Button',
                'caption' => 'Aktivitäten abrufen',
                'onClick' => 'UCR_RequestActivities($id);'
            ]
        ];
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
                'caption' => 'Remote 3 Device created.'],
            [
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => 'interface closed.']];

        return $form;
    }
}
