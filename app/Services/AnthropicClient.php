<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reusable HTTP client for the Anthropic Messages API.
 *
 * Three call styles:
 *  - message()  — standard request, returns full response text
 *  - triage()   — lightweight call (tiny max_tokens) for RESPOND/LISTEN decisions
 *  - stream()   — SSE streaming, calls $onToken per chunk, returns full text
 */
class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    private const API_VERSION = '2023-06-01';

    /**
     * Standard (non-streaming) message call.
     *
     * @param  string  $system    System prompt (top-level param in Anthropic API)
     * @param  array   $messages  Alternating user/assistant message turns
     */
    public function message(
        string $system,
        array $messages,
        int $maxTokens = 1024,
        float $temperature = 0.7,
        string $model = self::DEFAULT_MODEL,
    ): string {
        $response = Http::timeout(60)->withHeaders($this->headers())->post(self::API_URL, [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'system' => $system,
            'messages' => $messages,
        ]);

        if (! $response->successful()) {
            Log::error('[AnthropicClient] API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Anthropic API error: '.$response->status());
        }

        return $response->json('content.0.text', '');
    }

    /**
     * Lightweight triage call — tiny max_tokens, low temperature.
     * Used for RESPOND/LISTEN decisions (~$0.001 per call).
     */
    public function triage(string $system, array $messages): string
    {
        return $this->message($system, $messages, maxTokens: 30, temperature: 0.2);
    }

    /**
     * Streaming call — parses Anthropic SSE events and calls $onToken per text chunk.
     * Returns the full accumulated response text.
     *
     * Anthropic streams:
     *   event: content_block_delta
     *   data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}
     */
    public function stream(
        string $system,
        array $messages,
        int $maxTokens = 1024,
        float $temperature = 0.7,
        \Closure $onToken = null,
    ): string {
        $response = Http::timeout(120)
            ->withHeaders($this->headers())
            ->withOptions(['stream' => true])
            ->post(self::API_URL, [
                'model' => self::DEFAULT_MODEL,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'system' => $system,
                'messages' => $messages,
                'stream' => true,
            ]);

        if (! $response->successful()) {
            Log::error('[AnthropicClient] Stream API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Anthropic streaming API error: '.$response->status());
        }

        $fullContent = '';
        $buffer = '';

        $body = $response->toPsrResponse()->getBody();

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            $buffer .= $chunk;

            // Process complete SSE lines
            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $nlPos);
                $buffer = substr($buffer, $nlPos + 1);
                $line = trim($line);

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    break 2;
                }

                $data = json_decode($json, true);
                if (! $data) {
                    continue;
                }

                // content_block_delta events carry the text tokens
                if (($data['type'] ?? '') === 'content_block_delta'
                    && ($data['delta']['type'] ?? '') === 'text_delta') {
                    $text = $data['delta']['text'] ?? '';
                    if ($text !== '') {
                        $fullContent .= $text;
                        if ($onToken) {
                            $onToken($text);
                        }
                    }
                }
            }
        }

        return $fullContent;
    }

    private function headers(): array
    {
        return [
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
    }
}
