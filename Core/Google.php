<?php

namespace Core;

use Google_Client;
use Google_Service_Drive;
use Google_Service_Calendar;
use Google_Service_Sheets;

class Google
{
    private $client;

    public function __construct($clientConfigPath)
    {
        $this->client = new Google_Client();
        $this->client->setAuthConfig($clientConfigPath);
        $this->client->setAccessType('offline');
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

    // Google Maps API functions
    public function geocode($address)
    {
        $apiKey = $this->client->getConfig('api_key');
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . $apiKey;
        $response = file_get_contents($url);
        return json_decode($response, true);
    }

    public function getDistance($origin, $destination, $mode = 'driving')
    {
        $apiKey = $this->client->getConfig('api_key');
        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . urlencode($origin) . "&destinations=" . urlencode($destination) . "&mode=" . $mode . "&key=" . $apiKey;
        $response = file_get_contents($url);
        return json_decode($response, true);
    }

    // Google Drive API functions
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

    // Google Calendar API functions
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

    public function createCalendarEvent($calendarId = 'primary', $summary, $description, $start, $end)
    {
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

    // Google Sheets API functions
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