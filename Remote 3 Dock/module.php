<?php

declare(strict_types=1);

class Remote3Dock extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('name', '');
        $this->RegisterPropertyString('hostname', '');
        $this->RegisterPropertyString('host', '');
        $this->RegisterPropertyString('dock_id', '');
        $this->RegisterPropertyString('model', '');
        $this->RegisterPropertyString('version', '');
        $this->RegisterPropertyString('rev', '');
        $this->RegisterPropertyString('ws_path', '');

        $this->RequireParent('{D68FD31F-0E90-7019-F16C-1949BD3079EF}'); // Websocket Client
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

    private function Send()
    {
        $this->SendDataToParent(json_encode(['DataID' => '{AC2A1323-0258-76DC-5AA8-9B0C092820A5}']));
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        IPS_LogMessage('Device RECV', utf8_decode($data->Buffer));
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
        $form = [];
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
                'caption' => 'Remote 3 Core Manager created.'],
            [
                'code' => IS_INACTIVE,
                'icon' => 'inactive',
                'caption' => 'interface closed.']];

        return $form;
    }
}