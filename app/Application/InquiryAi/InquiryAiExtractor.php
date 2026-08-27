<?php

namespace App\Application\InquiryAi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Smallest server-side AI inference boundary for raw Inquiry text (#53 / 51A).
 *
 * raw text -> provider HTTP request -> strict schema validation -> normalized
 * extraction DTO, or a typed InquiryExtractionException.
 *
 * Guarantees:
 * - credentials come from server-side config only; nothing is exposed to browser code;
 * - this boundary never writes operational data and performs no business decision;
 * - unknown/unsupported facts normalize to null and extra provider fields are
 *   discarded deterministically by InquiryExtractionSchema;
 * - provider timeout / 429 / transport error / invalid JSON / schema failure all
 *   return a typed InquiryExtractionException;
 * - no raw customer text and no full prompt/response is written to routine logs.
 */
final class InquiryAiExtractor
{
    private const PROVIDER_PATH = '/chat/completions';

    public function extract(string $rawText): ExtractedInquiry
    {
        $this->assertConfigured();

        $content = $this->responseContent($this->request($rawText));
        $providerData = $this->decodeObject($content);

        return ExtractedInquiry::fromNormalized(InquiryExtractionSchema::normalize($providerData));
    }

    private function assertConfigured(): void
    {
        if (config('ai_inference.enabled') !== true || (string) config('ai_inference.api_key') === '') {
            throw new InquiryExtractionException(
                InquiryExtractionException::AI_DISABLED,
                'AI Inference is disabled or not configured; manual entry remains available.',
            );
        }
    }

    private function request(string $rawText): Response
    {
        $baseUrl = rtrim((string) config('ai_inference.base_url', 'https://api.deepseek.com'), '/');
        $timeout = max(1, (int) config('ai_inference.timeout_seconds', 30));

        try {
            $response = Http::withOptions(['timeout' => $timeout])
                ->withHeaders(['Accept' => 'application/json'])
                ->withToken((string) config('ai_inference.api_key'))
                ->post($baseUrl.self::PROVIDER_PATH, [
                    'model' => (string) config('ai_inference.model', 'deepseek-chat'),
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => self::instructions()],
                        ['role' => 'user', 'content' => $rawText],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($e);
        }

        if ($response->status() === 429) {
            throw new InquiryExtractionException(
                InquiryExtractionException::PROVIDER_RATE_LIMITED,
                'AI provider rate limit exceeded.',
            );
        }

        if (! $response->successful()) {
            throw new InquiryExtractionException(
                InquiryExtractionException::PROVIDER_TRANSPORT_ERROR,
                'AI provider request failed.',
            );
        }

        return $response;
    }

    private function connectionFailure(ConnectionException $e): InquiryExtractionException
    {
        $message = strtolower($e->getMessage());
        $kind = (str_contains($message, 'timed out') || str_contains($message, 'cURL error 28'))
            ? InquiryExtractionException::PROVIDER_TIMEOUT
            : InquiryExtractionException::PROVIDER_TRANSPORT_ERROR;

        return new InquiryExtractionException($kind, 'AI provider connection failed.');
    }

    private function responseContent(Response $response): string
    {
        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InquiryExtractionException(
                InquiryExtractionException::PROVIDER_INVALID_JSON,
                'AI provider returned invalid JSON.',
            );
        }

        $content = null;
        if (
            is_array($payload)
            && isset($payload['choices'])
            && is_array($payload['choices'])
            && isset($payload['choices'][0])
            && is_array($payload['choices'][0])
            && isset($payload['choices'][0]['message'])
            && is_array($payload['choices'][0]['message'])
        ) {
            $content = $payload['choices'][0]['message']['content'] ?? null;
        }

        if (! is_string($content)) {
            throw new InquiryExtractionException(
                InquiryExtractionException::PROVIDER_SCHEMA_FAILURE,
                'AI provider response did not match the chat completion shape.',
            );
        }

        return $content;
    }

    /**
     * Decode the model content string as a JSON object.
     *
     * @return array<string, mixed>
     */
    private function decodeObject(string $content): array
    {
        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InquiryExtractionException(
                InquiryExtractionException::PROVIDER_INVALID_JSON,
                'AI extraction content was not valid JSON.',
            );
        }

        if (! is_object($decoded)) {
            throw new InquiryExtractionException(
                InquiryExtractionException::PROVIDER_SCHEMA_FAILURE,
                'AI extraction content was not a JSON object.',
            );
        }

        return get_object_vars($decoded);
    }

    private static function instructions(): string
    {
        return 'You extract structured facts from a boat-trip inquiry message for an operator '
            .'system. Reply with ONLY a JSON object: no markdown and no commentary. '
            .'Allowed keys and rules: service_date (ISO date YYYY-MM-DD), '
            .'boat_name (text, at most 255 characters), '
            .'route_summary (text, at most 2000 characters), '
            .'contact_name (text, at most 255 characters), '
            .'contact_method (one of PHONE, WHATSAPP, WECHAT, LINE, EMAIL, OTHER), '
            .'contact_value (text, at most 255 characters), '
            .'party_size (integer from 1 to 999), '
            .'pickup_required (true or false), '
            .'hotel_name (text, at most 255 characters), '
            .'room_number (text, at most 255 characters), '
            .'pickup_time (24-hour HH:MM), '
            .'meeting_point (text, at most 2000 characters), '
            .'service_location (text, at most 2000 characters). '
            .'Use null for anything not found in the message. Never invent facts. '
            .'Never include keys outside this list.';
    }
}
