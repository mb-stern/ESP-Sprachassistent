<?php

declare(strict_types=1);

class ESPSprachassistent extends IPSModule
{
    private const KINDS = ['artist', 'album', 'track', 'station'];
    private const CONTROLS = ['pause', 'resume', 'stop', 'previous', 'next'];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Address', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('WebPassword', '');
        $this->SetVisualizationType(1);
    }

    public function GetVisualizationTile(): string
    {
        return file_get_contents(__DIR__ . '/tile.html');
    }

    public function TestConnection(): string
    {
        try {
            $state = $this->ReadMusicState();
            return sprintf('Verbindung erfolgreich. %d Quellen und %d freigegebene Wiedergabeziele.',
                count($state['sources']), count($state['targets']));
        } catch (RuntimeException $error) {
            return $error->getMessage();
        }
    }

    public function PlayRadio(): void
    {
        $this->Api('/api/music/radio-resume', ['target_ids' => []]);
    }

    public function ControlPlayback(string $action): void
    {
        if (!in_array($action, self::CONTROLS, true)) {
            throw new RuntimeException('Unbekannter Wiedergabebefehl.');
        }
        $this->Api('/api/music/control', ['action' => $action, 'target_ids' => ['active']]);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'Music' || !is_string($Value) || strlen($Value) > 4096) {
            throw new RuntimeException('Ungültige Musikanfrage.');
        }
        $request = json_decode($Value, true, 12);
        if (!is_array($request)) {
            throw new RuntimeException('Ungültige Musikanfrage.');
        }
        $id = $request['request_id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-z0-9-]{1,80}\z/', $id)) {
            throw new RuntimeException('Ungültige Anfrage-ID.');
        }

        try {
            $result = $this->MusicRequest($request);
            $this->UpdateVisualizationValue(['request_id' => $id, 'result' => $result]);
        } catch (RuntimeException $error) {
            $this->UpdateVisualizationValue(['request_id' => $id, 'error' => $error->getMessage()]);
        }
    }

    private function MusicRequest(array $request): array
    {
        switch ($request['action'] ?? '') {
            case 'state':
                return $this->ReadMusicState();
            case 'refresh':
                $status = $this->Api('/api/music/targets', ['action' => 'status', 'target_ids' => []]);
                return ['outputs' => $this->PublicOutputs($status['outputs'] ?? []),
                    'complete' => ($status['complete'] ?? false) === true];
            case 'sources':
                $this->Api('/api/music/sources', [
                    'action' => 'default',
                    'source_ids' => $this->Ids($request['source_ids'] ?? null, 4),
                    'expected_sources' => $this->Ids($request['expected_sources'] ?? [], 4, true),
                    'remember_last' => true
                ]);
                return $this->ReadMusicState();
            case 'targets':
                $this->Api('/api/music/targets', [
                    'action' => 'select', 'target_ids' => $this->Ids($request['target_ids'] ?? null, 16)
                ]);
                return $this->ReadMusicState();
            case 'search':
                $query = $this->Text($request['query'] ?? null);
                $kind = $request['kind'] ?? 'all';
                if (!in_array($kind, [...self::KINDS, 'all'], true)) {
                    throw new RuntimeException('Unbekannter Suchfilter.');
                }
                return $this->PublicResults($this->Api('/api/music/search', [
                    'query' => $query, 'kind' => $kind, 'offset' => $this->Offset($request),
                    'source_ids' => $this->Ids($request['source_ids'] ?? null, 4),
                    'remember_source' => false
                ]));
            case 'browse':
                $kind = $request['kind'] ?? '';
                if (!in_array($kind, ['artist', 'album'], true)) {
                    throw new RuntimeException('Bitte einen Interpreten oder ein Album öffnen.');
                }
                return $this->PublicResults($this->Api('/api/music/browse', [
                    'id' => $this->Text($request['id'] ?? null), 'kind' => $kind,
                    'view' => $kind === 'artist' ? 'album' : 'track',
                    'source_id' => $this->Text($request['source_id'] ?? null),
                    'offset' => $this->Offset($request)
                ]));
            case 'play':
                $kind = $request['kind'] ?? '';
                if (!in_array($kind, self::KINDS, true)) {
                    throw new RuntimeException('Bitte einen Musiktitel oder Radiosender auswählen.');
                }
                $result = $this->Api('/api/music/control', [
                    'id' => $this->Text($request['id'] ?? null), 'kind' => $kind,
                    'source_id' => $this->Text($request['source_id'] ?? null),
                    'target_ids' => $this->Ids($request['target_ids'] ?? null, 16),
                    'shuffle' => in_array($kind, ['artist', 'album'], true) && ($request['shuffle'] ?? false) === true
                ]);
                return ['message' => !empty($result['partial']) ? 'Wiedergabe nur auf einem Teil der Ziele gestartet.'
                    : ($result['notice'] ?? $result['queue_notice'] ?? 'Wiedergabe angefordert.')];
            case 'control':
                $this->ControlPlayback($this->Text($request['command'] ?? null));
                return ['message' => 'Wiedergabebefehl gesendet.'];
            case 'radio':
                $this->PlayRadio();
                return ['message' => 'Letztes Radio angefordert.'];
            default:
                throw new RuntimeException('Unbekannte Musikanfrage.');
        }
    }

    private function ReadMusicState(): array
    {
        $status = $this->Api('/api/status');
        $sources = $status['music_settings']['sources'] ?? null;
        $targets = $status['music_targets'] ?? null;
        if (!is_array($sources['servers'] ?? null) || !is_array($targets['targets'] ?? null)) {
            throw new RuntimeException('Die Firmware liefert keine kompatible Musik-Schnittstelle.');
        }
        return [
            'enabled' => ($status['music_settings']['enabled'] ?? false) === true,
            'sources' => $this->Pick($sources['servers'], ['id', 'name', 'provider', 'url']),
            'source_ids' => $sources['default_sources'] ?? [],
            'targets' => $this->Pick(array_filter($targets['targets'], static fn ($target) =>
                is_array($target) && ($target['allowed'] ?? false) === true),
                ['id', 'name', 'provider', 'available', 'server_url']),
            'target_ids' => $targets['last_targets'] ?? [],
            'outputs' => $this->PublicOutputs($status['music']['outputs'] ?? [])
        ];
    }

    private function PublicResults(array $result): array
    {
        return [
            'results' => $this->Pick($result['results'] ?? [], ['id', 'kind', 'title', 'artist', 'album', 'source_id', 'source_name']),
            'source_errors' => $this->Pick($result['source_errors'] ?? [], ['source_id', 'source_name', 'error']),
            'has_more' => ($result['has_more'] ?? false) === true,
            'page_size' => max(1, (int) ($result['page_size'] ?? 50))
        ];
    }

    private function PublicOutputs(array $outputs): array
    {
        return $this->Pick($outputs, ['target_name', 'state', 'title', 'artist', 'album',
            'station', 'radio_text', 'status_known', 'stale']);
    }

    private function Pick(array $rows, array $keys): array
    {
        $allowed = array_fill_keys($keys, true);
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = array_intersect_key($row, $allowed);
            }
        }
        return $result;
    }

    private function Offset(array $request): int
    {
        $offset = $request['offset'] ?? 0;
        if (!is_int($offset) || $offset < 0 || $offset > 10000) {
            throw new RuntimeException('Ungültige Ergebnisseite.');
        }
        return $offset;
    }

    private function Text($value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 384) {
            throw new RuntimeException('Ein Suchbegriff oder eine Auswahl fehlt oder ist zu lang.');
        }
        return trim($value);
    }

    private function Ids($value, int $limit, bool $allowEmpty = false): array
    {
        if (!is_array($value) || count($value) > $limit || (!$allowEmpty && $value === [])) {
            throw new RuntimeException('Bitte eine gültige Quelle oder Ausgabe wählen.');
        }
        return array_values(array_unique(array_map(fn ($id) => $this->Text($id), $value)));
    }

    private function Api(string $path, ?array $payload = null): array
    {
        $address = preg_replace('~\Ahttp://~i', '', trim($this->ReadPropertyString('Address')));
        $ip = preg_replace('~(?::80)?/?\z~', '', $address);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Bitte die lokale IPv4-Adresse des ESP speichern, zum Beispiel http://192.168.1.50.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-cURL-Erweiterung von Symcon ist nicht verfügbar.');
        }
        $password = $this->ReadPropertyString('WebPassword');
        $client = curl_init('http://' . $ip . $path);
        if ($client === false) {
            throw new RuntimeException('Verbindung zum ESP konnte nicht vorbereitet werden.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 22,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-Home-Voice: 1']
        ];
        if ($password !== '') {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = 'admin:' . $password;
        }
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($client, $options);
        $body = curl_exec($client);
        $code = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
        curl_close($client);
        if ($code === 401 || $code === 403) {
            throw new RuntimeException('Der ESP hat den Zugriff verweigert. Bitte Adresse und Web-Passwort prüfen.');
        }
        if (!is_string($body) || $code === 0) {
            throw new RuntimeException('Der ESP-Lautsprecher hat nicht rechtzeitig geantwortet.');
        }
        $response = json_decode($body, true);
        if (!is_array($response)) {
            throw new RuntimeException('Die Adresse liefert keine ESP-API-Antwort.');
        }
        if ($code !== 200 || isset($response['error']) || ($response['ok'] ?? true) === false) {
            $detail = $response['error'] ?? $response['message'] ?? null;
            throw new RuntimeException(is_string($detail) && $detail !== '' ? $detail : 'Die Musikanfrage ist fehlgeschlagen.');
        }
        return $response;
    }
}
