<?php

namespace App\Application\InquiryAi;

use RuntimeException;

/**
 * Typed parse failure for the AI Inquiry extraction boundary.
 *
 * `kind` is the machine-stable failure discriminator. The message is generic
 * by design: it never carries raw customer text, provider response bodies, or
 * credential material, so exception paths cannot leak PII into logs or UI.
 */
final class InquiryExtractionException extends RuntimeException
{
    public const AI_DISABLED = 'AI_DISABLED';

    public const PROVIDER_TIMEOUT = 'PROVIDER_TIMEOUT';

    public const PROVIDER_RATE_LIMITED = 'PROVIDER_RATE_LIMITED';

    public const PROVIDER_TRANSPORT_ERROR = 'PROVIDER_TRANSPORT_ERROR';

    public const PROVIDER_INVALID_JSON = 'PROVIDER_INVALID_JSON';

    public const PROVIDER_SCHEMA_FAILURE = 'PROVIDER_SCHEMA_FAILURE';

    public function __construct(
        public readonly string $kind,
        string $message = '',
    ) {
        parent::__construct($message);
    }
}
