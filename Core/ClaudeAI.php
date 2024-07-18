<?php

namespace Core;

class ClaudAI
{
    public static function generateResponse($prompt, $maxTokens = 100)
    {
        $data = [
            'model' => 'claude-3-opus-20240229',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $maxTokens
        ];

        $response = self::sendRequest($data);
        return $response['content'][0]['text'];
    }

    public static function analyzeText($text)
    {
        $prompt = "Analyze the following text and provide a summary of key points:\n\n" . $text;
        return self::generateResponse($prompt, 200);
    }

    public static function translateText($text, $targetLanguage)
    {
        $prompt = "Translate the following text to $targetLanguage:\n\n" . $text;
        return self::generateResponse($prompt, 150);
    }

    public static function generateCode($language, $task)
    {
        $prompt = "Generate $language code for the following task:\n\n" . $task;
        return self::generateResponse($prompt, 300);
    }

    private static function sendRequest($data)
    {
        $ch = curl_init(\APP\Config::CLAUD_AI_ENDPOINT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . \App\Config::CLAUDE_AI_API_KEY,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}