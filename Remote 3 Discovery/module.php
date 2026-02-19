<?php

declare(strict_types=1);

class Remote3Discovery extends IPSModule
{
    const DEFAULT_WS_PROTOCOL = 'ws://';
    const DEFAULT_WS_PORT = 8080;

    const DEFAULT_WSS_PORT = 8443;
    const DEFAULT_WS_PATH = '/ws';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString('devices', '[]');
        $this->RegisterAttributeString('known_remotes', '[]');
        $this->RegisterAttributeString('active_remotes', '[]');
        $this->RegisterAttributeString('docks', '[]');
        $this->RegisterAttributeString('known_docks', '[]');
        $this->RegisterAttributeString('active_docks', '[]');

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterTimer('Discovery', 0, 'UCR_Discover($_IPS[\'TARGET\']);');
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

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->StartDiscovery();
        $this->UpdateKnownAndActiveDevices();

        // Status Error Kategorie zum Import auswählen
        $this->SetStatus(IS_ACTIVE);
    }

    public function GetKnownRemotes()
    {
        $knownRemotes = json_decode($this->ReadAttributeString('known_remotes'), true);
        return $knownRemotes;
    }

    private function StartDiscovery(): void
    {
        if (empty($this->DiscoverDevices())) {
            $this->SendDebug('Discover:', 'could not find Remote 3 info', 0);
        } else {
            $this->WriteAttributeString('devices', json_encode($this->DiscoverDevices()));
        }
        if (empty($this->DiscoverDocks())) {
            $this->SendDebug('Discover:', 'could not find Remote 3 dock info', 0);
        } else {
            $this->WriteAttributeString('docks', json_encode($this->DiscoverDocks()));
        }
        $this->SetTimerInterval('Discovery', 300000);
    }

    private function DiscoverDevices(): array
    {
        $devices = $this->SearchRemotes();
        $this->SendDebug('Discover Response:', json_encode($devices), 0);
        $remote_info = $this->GetRemoteInfo($devices);
        if (empty($remote_info)) {
            $this->SendDebug('Discover:', 'could not find Remote 3 info', 0);
        } else {
            foreach ($remote_info as $device) {
                $this->SendDebug('name:', $device['name'], 0);
                $this->SendDebug('hostname:', $device['hostname'], 0);
                $this->SendDebug('host:', $device['host'], 0);
                $this->SendDebug('port:', $device['port'], 0);
                $this->SendDebug('id:', $device['id'], 0);
                $this->SendDebug('model:', $device['model'], 0);
                $this->SendDebug('version:', $device['version'], 0);
                $this->SendDebug('ver_api:', $device['ver_api'], 0);
                $this->SendDebug('https_port:', $device['https_port'], 0);
            }
        }
        return $remote_info;
    }

    private function DiscoverDocks(): array
    {
        $docks = $this->SearchDocks();
        $this->SendDebug('Discover Response:', json_encode($docks), 0);
        $dock_info = $this->GetDockInfo($docks);
        if (empty($dock_info)) {
            $this->SendDebug('Discover:', 'could not find Remote 3 dock info', 0);
        } else {
            foreach ($dock_info as $dock) {
                $this->SendDebug('name:', $dock['name'], 0);
                $this->SendDebug('hostname:', $dock['hostname'], 0);
                $this->SendDebug('host:', $dock['host'], 0);
                $this->SendDebug('port:', $dock['port'], 0);
                $this->SendDebug('id:', $dock['id'], 0);
                $this->SendDebug('model:', $dock['model'], 0);
                $this->SendDebug('version:', $dock['version'], 0);
                $this->SendDebug('rev:', $dock['rev'], 0);
                $this->SendDebug('ws_path:', $dock['ws_path'], 0);
            }
        }
        return $dock_info;
    }

    public function SearchRemotes(): array
    {
        $mDNSInstanceID = $this->GetDNSSD();
        $remotes = ZC_QueryServiceType($mDNSInstanceID, '_uc-remote._tcp', 'local');
        return $remotes;
    }

    public function SearchDocks(): array
    {
        $mDNSInstanceID = $this->GetDNSSD();
        $docks = ZC_QueryServiceType($mDNSInstanceID, '_uc-dock._tcp', 'local');
        return $docks;
    }


    private function GetDNSSD()
    {
        $mDNSInstanceIDs = IPS_GetInstanceListByModuleID('{780B2D48-916C-4D59-AD35-5A429B2355A5}');
        $mDNSInstanceID = $mDNSInstanceIDs[0];
        return $mDNSInstanceID;
    }

    protected function GetRemoteInfo($devices): array
    {
        $mDNSInstanceID = $this->GetDNSSD();
        $remote_info = [];
        $seen_ids = [];
        $seen_hosts = [];
        foreach ($devices as $key => $remote) {
            $mDNS_name = $remote['Name'];
            $response = ZC_QueryService($mDNSInstanceID, $mDNS_name, '_uc-remote._tcp', 'local.');
            foreach ($response as $data) {
                $this->SendDebug('GetRemoteInfo:', json_encode($data), 0);
                $name = '';
                $hostname = '';
                $ip = '';
                $port = 0;
                $model = '';
                $version = '';
                $https_port = '';

                if (isset($data['Name'])) {
                    $name = str_ireplace('._uc-remote._tcp.local.', '', $data['Name']);
                }

                if (isset($data['Host'])) {
                    $hostname = str_ireplace('.local.', '', $data['Host']);
                }

                if (isset($data['Port'])) {
                    $port = $data['Port'];
                }

                if (isset($data['TXTRecords']) && is_array($data['TXTRecords'])) {
                    foreach ($data['TXTRecords'] as $record) {
                        if (str_starts_with($record, 'ver=')) {
                            $version = substr($record, 4);
                        }
                        if (str_starts_with($record, 'ver_api=')) {
                            $ver_api = substr($record, 8);
                        }
                        if (str_starts_with($record, 'model=')) {
                            $model = substr($record, 6);
                        }
                        if (str_starts_with($record, 'https_port=')) {
                            $https_port = substr($record, 11);
                        }
                    }
                }

                // Optional: IPv4-Pflicht prüfen (IPv6 ignorieren)
                if (!isset($data['IPv4'][0])) {
                    $this->SendDebug('GetRemoteInfo:', "⚠️ Kein IPv4 – überspringe '$name'", 0);
                    continue;
                }
                $ip = $data['IPv4'][0];
                // // Falls IPv6 erlaubt sein soll, kann man das wie vorher machen
                // if (isset($data['IPv4'][0])) {
                //     $ip = $data['IPv4'][0];
                // } elseif (isset($data['IPv6'][0])) {
                //     $ip = $data['IPv6'][0];
                // }

                // Doppelte Einträge vermeiden basierend auf IP und Hostname
                $hostKey = $ip . '_' . $hostname;
                if (isset($seen_hosts[$hostKey])) {
                    $this->SendDebug('GetRemoteInfo:', "⚠️ Doppelte IP/Host-Kombination '$hostKey' – übersprungen", 0);
                    continue;
                }
                $seen_hosts[$hostKey] = true;

                $remote_info[$key] = [
                    'name' => $name,
                    'hostname' => $hostname,
                    'host' => $ip,
                    'port' => $port,
                    'id' => $name, // fallback: using 'name' as ID
                    'model' => $model,
                    'version' => $version,
                    'ver_api' => $ver_api ?? '',
                    'https_port' => $https_port
                ];
            }
        }
        return $remote_info;
    }

    protected function GetDockInfo($devices): array
    {
        $mDNSInstanceID = $this->GetDNSSD();
        $dock_info = [];

        foreach ($devices as $key => $device) {
            $mDNS_name = $device['Name'];
            $response = ZC_QueryService($mDNSInstanceID, $mDNS_name, '_uc-dock._tcp', 'local.');

            foreach ($response as $data) {
                $this->SendDebug('GetDockInfo:', json_encode($data), 0);

                $name = '';
                $hostname = '';
                $ip = '';
                $port = 0;
                $model = '';
                $version = '';
                $rev = '';
                $ws_path = '';

                if (isset($data['Name'])) {
                    $name = str_ireplace('._uc-dock._tcp.local.', '', $data['Name']);
                }

                if (isset($data['Host'])) {
                    $hostname = str_ireplace('.local.', '', $data['Host']);
                }

                if (isset($data['Port'])) {
                    $port = $data['Port'];
                }

                if (isset($data['TXTRecords']) && is_array($data['TXTRecords'])) {
                    foreach ($data['TXTRecords'] as $record) {
                        if (str_starts_with($record, 'name=')) {
                            $name = substr($record, 5);
                        }
                        if (str_starts_with($record, 'model=')) {
                            $model = substr($record, 6);
                        }
                        if (str_starts_with($record, 'ver=')) {
                            $version = substr($record, 4);
                        }
                        if (str_starts_with($record, 'rev=')) {
                            $rev = substr($record, 4);
                        }
                        if (str_starts_with($record, 'ws_path=')) {
                            $ws_path = substr($record, 8);
                        }
                    }
                }

                if (isset($data['IPv4'][0])) {
                    $ip = $data['IPv4'][0];
                } elseif (isset($data['IPv6'][0])) {
                    $ip = $data['IPv6'][0];
                }

                $dock_info[$key] = [
                    'name' => $name,
                    'hostname' => $hostname,
                    'host' => $ip,
                    'port' => $port,
                    'id' => $name, // fallback: use 'name' as ID
                    'model' => $model,
                    'version' => $version,
                    'rev' => $rev,
                    'ws_path' => $ws_path
                ];
            }
        }

        return $dock_info;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IM_CHANGESTATUS:
                if ($Data[0] === IS_ACTIVE) {
                    $this->StartDiscovery();
                }
                break;

            case IPS_KERNELMESSAGE:
                if ($Data[0] === KR_READY) {
                    $this->StartDiscovery();
                }
                break;
            case IPS_KERNELSTARTED:
                $this->StartDiscovery();
                break;

            default:
                break;
        }
    }

    public function GetDevices()
    {
        $devices = $this->ReadPropertyString('devices');

        return $devices;
    }

    public function GetDocks()
    {
        $docks = $this->ReadPropertyString('docks');

        return $docks;
    }

    public function Discover()
    {
        if (empty($this->DiscoverDevices())) {
            $devices = '';
        } else {
            $this->LogMessage($this->Translate('Background Discovery of Remote 3'), KL_NOTIFY);
            $this->WriteAttributeString('devices', json_encode($this->DiscoverDevices()));
            $devices = json_encode($this->DiscoverDevices());
        }

        if (empty($this->DiscoverDocks())) {
            $docks = '';
        } else {
            $this->LogMessage($this->Translate('Background Discovery of Remote 3'), KL_NOTIFY);
            $this->WriteAttributeString('docks', json_encode($this->DiscoverDocks()));
            $docks = json_encode($this->DiscoverDocks());
        }
        $this->UpdateKnownAndActiveDevices();
        return ['devices' => $devices, 'docks' => $docks];
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
     * Aktualisiert bekannte und aktive Geräte inkl. Status.
     */
    private function UpdateKnownAndActiveDevices(): void
    {
        // Aktuelle Discovery
        $activeRemotes = $this->DiscoverDevices();
        $activeDocks = $this->DiscoverDocks();

        // Bereits bekannte Geräte laden
        $knownRemotes = json_decode($this->ReadAttributeString('known_remotes'), true);
        $knownDocks = json_decode($this->ReadAttributeString('known_docks'), true);

        if (!is_array($knownRemotes)) {
            $knownRemotes = [];
        }
        if (!is_array($knownDocks)) {
            $knownDocks = [];
        }

        // Helper: Map bekannte Geräte nach ID
        $knownRemoteMap = [];
        foreach ($knownRemotes as $r) {
            $knownRemoteMap[$r['id']] = $r;
        }

        foreach ($activeRemotes as $remote) {
            $remote['status'] = 'Online';
            $knownRemoteMap[$remote['id']] = $remote;
        }

        $mergedRemotes = array_values($knownRemoteMap);

        // Gleiches für Docks
        $knownDockMap = [];
        foreach ($knownDocks as $d) {
            $knownDockMap[$d['id']] = $d;
        }

        foreach ($activeDocks as $dock) {
            $dock['status'] = 'Online';
            $knownDockMap[$dock['id']] = $dock;
        }

        $mergedDocks = array_values($knownDockMap);

        // Status für inaktive Remotes/Docks setzen
        foreach ($mergedRemotes as &$r) {
            if (!in_array($r['id'], array_column($activeRemotes, 'id'))) {
                $r['status'] = 'Offline';
            }
        }
        unset($r);

        foreach ($mergedDocks as &$d) {
            if (!in_array($d['id'], array_column($activeDocks, 'id'))) {
                $d['status'] = 'Offline';
            }
        }
        unset($d);

        // Schreiben
        $this->WriteAttributeString('known_remotes', json_encode($mergedRemotes));
        $this->WriteAttributeString('known_docks', json_encode($mergedDocks));
        $this->WriteAttributeString('active_remotes', json_encode($activeRemotes));
        $this->WriteAttributeString('active_docks', json_encode($activeDocks));
    }

    public function CleanupOfflineRemotes(): void
    {
        $knownRemotes = json_decode($this->ReadAttributeString('known_remotes'), true);
        if (!is_array($knownRemotes)) {
            $knownRemotes = [];
        }
        $filtered = array_values(array_filter($knownRemotes, fn($entry) => ($entry['status'] ?? '') === 'Online'));
        $this->WriteAttributeString('known_remotes', json_encode($filtered));
        $this->SendDebug(__FUNCTION__, '✅ Offline-Remotes bereinigt', 0);
    }

    public function CleanupOfflineDocks(): void
    {
        $knownDocks = json_decode($this->ReadAttributeString('known_docks'), true);
        if (!is_array($knownDocks)) {
            $knownDocks = [];
        }
        $filtered = array_values(array_filter($knownDocks, fn($entry) => ($entry['status'] ?? '') === 'Online'));
        $this->WriteAttributeString('known_docks', json_encode($filtered));
        $this->SendDebug(__FUNCTION__, '✅ Offline-Docks bereinigt', 0);
    }

    /**
     * build configuration form
     *
     * @return string
     */
    public function GetConfigurationForm()
    {
        // return current form
        $form = json_encode(
            [
                'elements' => $this->FormHead(),
                'actions' => $this->FormActions(),
                'status' => $this->FormStatus()]
        );

        $this->SendDebug('FORM', $form, 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return $form;
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
                'type' => 'Label',
                'caption' => 'Remote 3']];
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
                'type' => 'Image',
                'image' => $this->LoadImageAsBase64()
            ],
            [
                'caption' => 'Remote 3',
                'name' => 'Remote3Discovery',
                'type' => 'Configurator',
                'rowCount' => 20,
                'add' => false,
                'delete' => true,
                'sort' => [
                    'column' => 'name',
                    'direction' => 'ascending',
                ],
                'columns' => [
                    ['caption' => 'ID', 'name' => 'id', 'width' => 'auto', 'visible' => false],
                    ['caption' => 'Name', 'name' => 'name', 'width' => '200px'],
                    ['caption' => 'Hostname', 'name' => 'hostname', 'width' => '200px'],
                    ['caption' => 'Host (IP)', 'name' => 'host', 'width' => '200px'],
                    ['caption' => 'Model', 'name' => 'model', 'width' => '120px'],
                    ['caption' => 'API Version', 'name' => 'ver_api', 'width' => '120px'],
                    ['caption' => 'Firmware', 'name' => 'version', 'width' => '120px'],
                    ['caption' => 'HTTPS Port', 'name' => 'https_port', 'width' => '100px'],
                    ['caption' => 'Status', 'name' => 'status', 'width' => '100px']
                ],
                'values' => $this->Get_ListConfigurationRemotes(),
            ],
            [
                'type' => 'Button',
                'caption' => 'Offline-Remotes bereinigen',
                'onClick' => 'UCR_CleanupOfflineRemotes($id);'
            ],
            [
                'caption' => 'Remote 3 Dock',
                'name' => 'Remote3DockDiscovery',
                'type' => 'Configurator',
                'rowCount' => 20,
                'add' => false,
                'delete' => true,
                'sort' => [
                    'column' => 'name',
                    'direction' => 'ascending',
                ],
                'columns' => [
                    ['caption' => 'ID', 'name' => 'id', 'width' => 'auto', 'visible' => false],
                    ['caption' => 'Name', 'name' => 'name', 'width' => '200px'],
                    ['caption' => 'Hostname', 'name' => 'hostname', 'width' => '200px'],
                    ['caption' => 'Host (IP)', 'name' => 'host', 'width' => '200px'],
                    ['caption' => 'Model', 'name' => 'model', 'width' => '120px'],
                    ['caption' => 'Firmware', 'name' => 'version', 'width' => '120px'],
                    ['caption' => 'Hardware-Rev', 'name' => 'rev', 'width' => '120px'],
                    ['caption' => 'WebSocket Pfad', 'name' => 'ws_path', 'width' => '150px'],
                    ['caption' => 'Status', 'name' => 'status', 'width' => '100px']
                ],
                'values' => $this->Get_ListConfigurationDocks(),
            ],
            [
                'type' => 'Button',
                'caption' => 'Offline-Docks bereinigen',
                'onClick' => 'UCR_CleanupOfflineDocks($id);'
            ]
        ];
        return $form;
    }

    /**
     * Liefert alle Geräte.
     *
     * @return array configlist all devices
     */
    private function Get_ListConfigurationRemotes()
    {
        $config_list = [];
        $RemoteIDList = IPS_GetInstanceListByModuleID('{5894A8B3-7E60-981A-B3BA-6647335B57E4}'); // Remote 3 Device
        $devices = json_decode($this->ReadAttributeString('known_remotes'), true);
        // Doppelte IP-Adressen filtern (nur erster Eintrag bleibt)
        $filteredByIP = [];
        $seenIPs = [];
        foreach ($devices as $entry) {
            $ip = $entry['host'];
            if (isset($seenIPs[$ip])) {
                $this->SendDebug(__FUNCTION__, "⚠️ Duplikat ignoriert für IP $ip (Name: {$entry['name']})", 0);
                continue;
            }
            $seenIPs[$ip] = true;
            $filteredByIP[] = $entry;
        }
        $devices = $filteredByIP;
        $this->SendDebug('Discovered Remotes', json_encode($devices), 0);
        if (!empty($devices)) {
            foreach ($devices as $device) {
                $instanceID = 0;
                $name = $device['name'];
                $hostname = $device['hostname'];
                $host = $device['host'];
                $remote_id = $device['id'];
                $device_id = 0;
                foreach ($RemoteIDList as $RemoteID) {
                    if ($host == IPS_GetProperty($RemoteID, 'host')) {
                        $Remote_name = IPS_GetName($RemoteID);
                        $this->SendDebug(
                            'Remote 3 Discovery', 'Remote 3 found: ' . $Remote_name . ' (' . $RemoteID . ')', 0
                        );
                        $instanceID = $RemoteID;
                    }
                }

                $config_list[] = [
                    'instanceID' => $instanceID,
                    'id' => $device_id,
                    'name' => $name,
                    'hostname' => $hostname,
                    'host' => $host,
                    'remote_id' => $remote_id,
                    'model' => $device['model'],
                    'ver_api' => $device['ver_api'],
                    'version' => $device['version'],
                    'https_port' => $device['https_port'],
                    'status' => $device['status'] ?? '',
                    'create' => [
                        [
                            'moduleID' => '{5894A8B3-7E60-981A-B3BA-6647335B57E4}',
                            'name' => $name,
                            'configuration' => [
                                'name' => $name,
                                'hostname' => $hostname,
                                'host' => $host,
                                'remote_id' => $remote_id,
                                'model' => $device['model'],
                                'ver_api' => $device['ver_api'],
                                'version' => $device['version'],
                                'https_port' => $device['https_port']
                            ]
                        ],
                        [
                            'moduleID' => '{C810D534-2395-7C43-D0BE-6DEC069B2516}',
                            'name' => 'Remote 3 Core Manager ' . $name,
                            'configuration' => [
                                'name' => $name,
                                'hostname' => $hostname,
                                'host' => $host,
                                'remote_id' => $remote_id,
                                'model' => $device['model'],
                                'ver_api' => $device['ver_api'],
                                'version' => $device['version'],
                                'https_port' => $device['https_port']
                            ]
                        ],
                        [
                            'moduleID' => '{D68FD31F-0E90-7019-F16C-1949BD3079EF}',
                            'name' => 'Remote 3 ' . $name . ' (WS)',
                            'configuration' => [
                                'URL' => self::DEFAULT_WS_PROTOCOL . $host . ':' . self::DEFAULT_WS_PORT . self::DEFAULT_WS_PATH,
                                'VerifyCertificate' => false,
                                'Type' => 0 // Text
                            ]
                        ]
                    ]
                ];
            }
        }
        return $config_list;
    }

    private function Get_ListConfigurationDocks()
    {
        $config_list = [];
        $DockIDList = IPS_GetInstanceListByModuleID('{E502C0F2-7482-0B16-9C15-77C04C6399B3}'); // Remote 3 Device
        $docks = json_decode($this->ReadAttributeString('known_docks'), true);
        // Doppelte IP-Adressen filtern (nur erster Eintrag bleibt)
        $filteredByIP = [];
        $seenIPs = [];
        foreach ($docks as $entry) {
            $ip = $entry['host'];
            if (isset($seenIPs[$ip])) {
                $this->SendDebug(__FUNCTION__, "⚠️ Duplikat ignoriert für IP $ip (Name: {$entry['name']})", 0);
                continue;
            }
            $seenIPs[$ip] = true;
            $filteredByIP[] = $entry;
        }
        $docks = $filteredByIP;
        $this->SendDebug('Discovered Docks', json_encode($docks), 0);
        if (!empty($docks)) {
            foreach ($docks as $dock) {
                $instanceID = 0;
                $name = $dock['name'];
                $hostname = $dock['hostname'];
                $host = $dock['host'];
                $dock_id = $dock['id'];
                $device_id = 0;
                foreach ($DockIDList as $DockID) {
                    if ($host == IPS_GetProperty($DockID, 'host')) {
                        $Dock_name = IPS_GetName($DockID);
                        $this->SendDebug(
                            'Remote 3 Dock Discovery', 'Remote 3 Dock found: ' . $Dock_name . ' (' . $DockID . ')', 0
                        );
                        $instanceID = $DockID;
                    }
                }

                $config_list[] = [
                    'instanceID' => $instanceID,
                    'id' => $device_id,
                    'name' => $name,
                    'hostname' => $hostname,
                    'host' => $host,
                    'dock_id' => $dock_id,
                    'model' => $dock['model'],
                    'version' => $dock['version'],
                    'rev' => $dock['rev'],
                    'ws_path' => $dock['ws_path'],
                    'status' => $dock['status'] ?? '',
                    'create' => [
                        [
                            'moduleID' => '{E502C0F2-7482-0B16-9C15-77C04C6399B3}',
                            'configuration' => [
                                'name' => $name,
                                'hostname' => $hostname,
                                'host' => $host,
                                'dock_id' => $dock_id,
                                'model' => $dock['model'],
                                'version' => $dock['version'],
                                'rev' => $dock['rev'],
                                'ws_path' => $dock['ws_path']
                            ]
                        ]
                    ]
                ];
            }
        }
        return $config_list;
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