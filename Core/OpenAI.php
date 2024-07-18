<?php
namespace Core;

class OpenAI
{

    public static function generateText($prompt, $model = 'gpt-3.5-turbo', $maxTokens = 100)
    {
        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $maxTokens
        ];

        $response = self::sendRequest('chat/completions', $data);
        return $response['choices'][0]['message']['content'];
    }

    public static function generateImage($prompt, $size = '512x512', $n = 1)
    {
        $data = [
            'prompt' => $prompt,
            'size' => $size,
            'n' => $n
        ];

        $response = self::sendRequest('images/generations', $data);
        return $response['data'][0]['url'];
    }

    public static function transcribeAudio($audioFilePath, $language = 'en')
    {
        $data = [
            'file' => new \CURLFile($audioFilePath),
            'model' => 'whisper-1',
            'language' => $language
        ];

        $response = self::sendRequest('audio/transcriptions', $data, true);
        return $response['text'];
    }

    public static function translateText($text, $targetLanguage = 'en')
    {
        $prompt = "Translate the following text to $targetLanguage: " . $text;
        return self::generateText($prompt, \App\Config::OPEN_AI_DEFAULT_MODEL, 200);
    }

    public static function analyzeContent($content)
    {
        $prompt = "Analyze the following content and provide a summary of key points: " . $content;
        return self::generateText($prompt, \App\Config::OPEN_AI_DEFAULT_MODEL, 300);
    }

    public static function generateCode($language, $task)
    {
        $prompt = "Generate $language code for the following task: " . $task;
        return self::generateText($prompt, \App\Config::OPEN_AI_DEFAULT_MODEL, 500);
    }

    private static function sendRequest($data, $isMultipart = false)
    {
        $ch = curl_init(\App\Config::OPEN_AI_ENDPOINT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $headers = [
                'Authorization: Bearer ' . \App\Config::OPEN_AI_API_KEY,
                'Content-Type: multipart/form-data'
            ];
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers = [
                'Authorization: Bearer ' . \App\Config::OPEN_AI_API_KEY,
                'Content-Type: application/json'
            ];
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}