<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Assisted Inquiry Text Extraction (suggestion-only boundary)
    |--------------------------------------------------------------------------
    |
    | Issue #53 / 51A: smallest server-side AI inference boundary. AI output is
    | a validated suggestion only; it never becomes operational authority and
    | this boundary performs no database or business-state writes.
    |
    | Provider credentials stay server-side. Values are read from environment
    | variables only; never commit a real API key into this repository.
    |
    */

    'enabled' => env('BOATOPS_AI_INFERENCE_ENABLED', false) === true,
    'provider' => env('BOATOPS_AI_INFERENCE_PROVIDER', 'deepseek'),
    'base_url' => env('BOATOPS_AI_INFERENCE_BASE_URL', 'https://api.deepseek.com'),
    'model' => env('BOATOPS_AI_INFERENCE_MODEL', 'deepseek-chat'),
    'timeout_seconds' => max(1, (int) env('BOATOPS_AI_INFERENCE_TIMEOUT_SECONDS', 30)),

    // Secret reference only: the empty value below is replaced by the
    // BOATOPS_AI_INFERENCE_API_KEY environment variable on the server.
    'api_key' => env('BOATOPS_AI_INFERENCE_API_KEY'),

];
