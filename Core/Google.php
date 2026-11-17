<?php

namespace Core;

use Google_Client;
use Google_Service_Drive;
use Google_Service_Calendar;
use Google_Service_Sheets;

class Google
{
    private $client;

    public function __construct($clientConfigPath = null, ?string $apiKey = null)
    {
        if (!class_exists(Google_Client::class)) {
            throw new \LogicException('Install google/apiclient to use the Google integration.');
        }
        $this->client = new Google_Client();
        if ($clientConfigPath !== null) {
            $this->client->setAuthConfig($clientConfigPath);
        }
        $this->client->setAccessType('offline');
        if ($apiKey !== null) {
            $this->setApiKey($apiKey);
        }
    }

    public function setApiKey(string $apiKey): void
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('A non-empty Google Maps API key is required.');
        }
        $this->client->setDeveloperKey($apiKey);
    }

    public function setAccessToken($accessToken)
    {
        $this->client->setAccessToken($accessToken);
    }

    public function getAuthUrl($scopes)
    {
        $this->client->setScopes($scopes);
        return $this->client->createAuthUrl();
    }

    public function fetchAccessTokenWithAuthCode($authCode)
    {
        return $this->client->fetchAccessTokenWithAuthCode($authCode);
    }

    public function geocode(string $address): array
    {
        return $this->mapsRequest('geocode', ['address' => $address]);
    }

    public function getDistance(string $origin, string $destination, string $mode = 'driving'): array
    {
        return $this->mapsRequest('distancematrix', ['origins' => $origin, 'destinations' => $destination, 'mode' => $mode]);
    }

    private function mapsRequest(string $endpoint, array $parameters): array
    {
        $apiKey = $this->client->getConfig('developer_key');
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \LogicException('Configure a Google Maps API key before making Maps requests.');
        }
        $parameters['key'] = $apiKey;
        try {
            $response = $this->client->getHttpClient()->request('GET', 'https://maps.googleapis.com/maps/api/' . $endpoint . '/json', [
                'query' => $parameters,
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            throw new \RuntimeException('The Google Maps request failed.');
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('The Google Maps response is not valid JSON.');
        }
        if (!is_array($data)) {
            throw new \UnexpectedValueException('The Google Maps response must contain an object.');
        }
        return $data;
    }

    public function listDriveFiles($pageSize = 10)
    {
        $driveService = new Google_Service_Drive($this->client);
        $optParams = array(
            'pageSize' => $pageSize,
            'fields' => 'nextPageToken, files(id, name)'
        );
        $results = $driveService->files->listFiles($optParams);
        return $results->getFiles();
    }

    public function uploadFileToDrive($filePath, $name, $mimeType)
    {
        $driveService = new Google_Service_Drive($this->client);
        $fileMetadata = new \Google_Service_Drive_DriveFile(array(
            'name' => $name
        ));
        $content = file_get_contents($filePath);
        $file = $driveService->files->create($fileMetadata, array(
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id'
        ));
        return $file->id;
    }

    public function listCalendarEvents($calendarId = 'primary', $maxResults = 10)
    {
        $calendarService = new Google_Service_Calendar($this->client);
        $optParams = array(
            'maxResults' => $maxResults,
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => date('c')
        );
        $results = $calendarService->events->listEvents($calendarId, $optParams);
        return $results->getItems();
    }

    public function createCalendarEvent($calendarId = 'primary', $summary = null, $description = '', $start = null, $end = null)
    {
        foreach ([$calendarId, $summary, $start, $end] as $required) {
            if (!is_string($required) || trim($required) === '') {
                throw new \InvalidArgumentException('Calendar ID, summary, start and end are required strings.');
            }
        }
        $calendarService = new Google_Service_Calendar($this->client);
        $event = new \Google_Service_Calendar_Event(array(
            'summary' => $summary,
            'description' => $description,
            'start' => array('dateTime' => $start),
            'end' => array('dateTime' => $end)
        ));
        $event = $calendarService->events->insert($calendarId, $event);
        return $event->id;
    }

    public function readSheetData($spreadsheetId, $range)
    {
        $sheetsService = new Google_Service_Sheets($this->client);
        $response = $sheetsService->spreadsheets_values->get($spreadsheetId, $range);
        return $response->getValues();
    }

    public function writeSheetData($spreadsheetId, $range, $values)
    {
        $sheetsService = new Google_Service_Sheets($this->client);
        $body = new \Google_Service_Sheets_ValueRange([
            'values' => $values
        ]);
        $params = [
            'valueInputOption' => 'RAW'
        ];
        $result = $sheetsService->spreadsheets_values->update($spreadsheetId, $range, $body, $params);
        return $result->getUpdatedCells();
    }
}
