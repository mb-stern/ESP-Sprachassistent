<?php

declare(strict_types=1);

class ESPSprachassistentDiscovery extends IPSModule
{
    private const DEVICE_MODULE = '{AC4EC68E-0577-4D25-B53E-C43E9857FC74}';
    private const PORT = 37741;
    private const QUERY = 'ESPVoiceDiscover/1?';
    private const REPLY = 'ESPVoiceDiscover/1!';
    private const TIMER = 'DiscoveryScan';
    private const RUNNING_MESSAGE = 'Lautsprecher werden gesucht …';
    private const START_FAILED = 'Die Gerätesuche konnte nicht gestartet werden.';

    public function Create()
    {
        parent::Create();
        $this->RegisterTimer(self::TIMER, 0, 'ESPVOICEDISC_RunScan($_IPS["TARGET"]);');
        $this->SetBuffer('ScanPending', '');
    }

    public function Reload(): void
    {
        if ($this->QueueScan()) {
            $this->UpdateFormField('DiscoveryStatus', 'caption', self::RUNNING_MESSAGE);
        } elseif ($this->GetBuffer('ScanPending') === '') {
            $this->UpdateFormField('DiscoveryStatus', 'caption', self::START_FAILED);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 16, JSON_THROW_ON_ERROR);
        $snapshot = json_decode($this->GetBuffer('DiscoverySnapshot'), true);
        if (is_array($snapshot)) {
            $form['elements'][1]['values'] = $this->DeviceRows($snapshot['devices']);
            $form['elements'][0]['caption'] = $snapshot['message'];
        }
        if ((!is_array($snapshot) || time() - $snapshot['updated'] >= 30) &&
            !$this->QueueScan() && $this->GetBuffer('ScanPending') === '') {
            $form['elements'][0]['caption'] = self::START_FAILED;
        }
        if ($this->GetBuffer('ScanPending') !== '') {
            $form['elements'][0]['caption'] = self::RUNNING_MESSAGE;
        }
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function QueueScan(): bool
    {
        if ($this->GetBuffer('ScanPending') !== '') {
            return false;
        }
        $this->SetBuffer('ScanPending', 'queued');
        if (!$this->SetTimerInterval(self::TIMER, 100)) {
            $this->SetBuffer('ScanPending', '');
            return false;
        }
        return true;
    }

    public function RunScan(): void
    {
        $this->SetTimerInterval(self::TIMER, 0);
        if ($this->GetBuffer('ScanPending') !== 'queued') {
            return;
        }
        $this->SetBuffer('ScanPending', 'running');
        $devices = [];
        try {
            $devices = $this->Discover();
            $message = $devices === []
                ? 'Keine Lautsprecher gefunden. Firmware ab 0.40.5 und dasselbe lokale Netz prüfen.'
                : count($devices) . ' Lautsprecher gefunden. Wähle einen Eintrag zum Anlegen.';
        } catch (Throwable $error) {
            $message = 'Gerätesuche fehlgeschlagen. ' . $error->getMessage();
        } finally {
            $this->SetBuffer('DiscoverySnapshot', json_encode([
                'updated' => time(), 'devices' => $devices, 'message' => $message
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            $this->SetBuffer('ScanPending', '');
        }
        $this->UpdateFormField('Lautsprecher', 'values',
            json_encode($this->DeviceRows($devices), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('DiscoveryStatus', 'caption', $message);
    }

    private function DeviceRows(array $devices): array
    {
        if ($devices === []) {
            return [];
        }
        $ids = [];
        $addresses = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE) as $instance) {
            $id = strtolower((string) IPS_GetProperty($instance, 'DeviceID'));
            $address = (string) IPS_GetProperty($instance, 'Address');
            $address = preg_replace('~\Ahttp://|(?::80)?/?\z~i', '', trim($address));
            if ($id !== '') {
                $ids[$id] = $instance;
            }
            $addresses[$address] = $instance;
        }
        $values = [];
        foreach ($devices as $id => $device) {
            $row = $device;
            $row['id'] = count($values) + 1;
            $row['instanceID'] = $ids[$id] ?? $addresses[$device['ip']] ?? 0;
            $row['create'] = [
                'moduleID' => self::DEVICE_MODULE,
                'name' => $device['name'],
                'configuration' => ['Address' => 'http://' . $device['ip'], 'DeviceID' => $id]
            ];
            $values[] = $row;
        }
        return $values;
    }

    private function Discover(): array
    {
        if (!function_exists('socket_create')) {
            throw new RuntimeException('Die PHP-Socket-Erweiterung von Symcon ist nicht verfügbar.');
        }
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Der UDP-Socket für die Suche konnte nicht geöffnet werden.');
        }
        try {
            if (!@socket_bind($socket, '0.0.0.0', 0) || !@socket_set_nonblock($socket) ||
                !@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1)) {
                throw new RuntimeException('UDP-Broadcast steht auf diesem Symcon-Server nicht zur Verfügung.');
            }
            $nonce = random_bytes(8);
            $query = self::QUERY . $nonce;
            $sent = false;
            foreach ($this->Broadcasts() as $address) {
                if (@socket_sendto($socket, $query, strlen($query), 0, $address, self::PORT) === strlen($query)) {
                    $sent = true;
                }
            }
            if (!$sent) {
                throw new RuntimeException('Die Netzwerksuche konnte nicht gesendet werden.');
            }

            $devices = [];
            $deadline = hrtime(true) + 850000000;
            while (count($devices) < 64 && ($remaining = $deadline - hrtime(true)) > 0) {
                $read = [$socket];
                $write = $except = null;
                if (@socket_select($read, $write, $except, 0, intdiv($remaining, 1000)) !== 1) {
                    break;
                }
                $packet = $ip = '';
                $port = 0;
                if (@socket_recvfrom($socket, $packet, 193, 0, $ip, $port) === false || $port !== self::PORT) {
                    continue;
                }
                $record = $this->Decode($packet, $nonce, $ip);
                if ($record !== null) {
                    [$id, $device] = $record;
                    $devices[$id] = $device;
                }
            }
            uasort($devices, static fn (array $a, array $b) =>
                strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['ip'], $b['ip']));
            return $devices;
        } finally {
            socket_close($socket);
        }
    }

    private function Broadcasts(): array
    {
        $addresses = ['255.255.255.255'];
        $interfaces = function_exists('net_get_interfaces') ? @net_get_interfaces() : [];
        foreach ($interfaces ?: [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $entry) {
                $ip = ip2long($entry['address'] ?? '');
                $mask = ip2long($entry['netmask'] ?? '');
                if ($ip !== false && $mask !== false && $mask !== 0 && ($mask & 0xffffffff) !== 0xffffffff &&
                    ($ip & 0xff000000) !== 0x7f000000) {
                    $addresses[] = long2ip($ip | ~$mask);
                }
            }
        }
        return array_unique($addresses);
    }

    private function Decode(string $packet, string $nonce, string $ip): ?array
    {
        $prefix = self::REPLY . $nonce;
        $length = strlen($prefix);
        if (!str_starts_with($packet, $prefix) || strlen($packet) <= $length + 6 ||
            strlen($packet) > 192 || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $id = bin2hex(substr($packet, $length, 6));
        $fields = explode("\0", substr($packet, $length + 6));
        if (count($fields) !== 4 || $fields[3] !== '' || !preg_match('/\A\d{1,5}\.\d{1,5}\.\d{1,5}\z/', $fields[0])) {
            return null;
        }
        [$version, $name, $room] = $fields;
        if (strlen($name) > 48 || strlen($room) > 64 || preg_match('/[\x00-\x1f\x7f]/', $name . $room)) {
            return null;
        }
        return [$id, ['name' => $name ?: 'ESP-Sprachassistent ' . strtoupper(substr($id, -4)),
            'room' => $room, 'ip' => $ip, 'version' => $version]];
    }
}
