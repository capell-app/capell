<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

/**
 * The policy lists are class constants so hot callers (the sanitizer and the
 * public HTML safety inspector call these inside per-node / per-marker loops)
 * get a copy-on-write reference instead of a freshly allocated array per call.
 */
final class PublicOutputLeakPolicy
{
    private const array BLOCKED_PUBLIC_KEYS = [
        'access_token', 'admin_path', 'admin_url', 'api_key', 'api_secret', 'authorization',
        'bearer', 'bearer_token', 'client_secret', 'credential', 'credentials', 'edit_url',
        'editable_regions', 'expires', 'field_path', 'filament_url', 'internal_id',
        'internal_model_id', 'model_id', 'model_type', 'page_id', 'password', 'permission',
        'permissions', 'private_key', 'prompt', 'record_key', 'recordkey', 'refresh_token',
        'secret', 'secret_prompt', 'selector', 'signature', 'signed_editor_url', 'signed_url',
        'system_prompt', 'token', 'webhook_secret',
    ];

    private const array AUTHORING_ATTRIBUTES = [
        'data-capell-authoring', 'data-capell-editable', 'data-capell-editor',
        'data-capell-editor-url', 'data-field-path', 'data-model-id', 'data-permission',
        'data-capell-package',
    ];

    private const array AUTHORING_JSON_KEYS = [
        'field_path', 'fieldpath', 'model_id', 'modelid', 'editor_url', 'editorurl',
        'signed_editor_url', 'signededitorurl', 'signed_admin_url', 'signedadminurl',
    ];

    private const array AUTHORING_SIGNED_URL_JSON_KEYS = ['signed_url', 'signedurl'];

    private const array AUTHORING_CLASS_OR_ID_MARKERS = ['capell-authoring', 'capell-editor'];

    private const array ALLOWED_CAPELL_RUNTIME_ATTRIBUTE_PREFIXES = [
        'data-capell-widget-', 'data-capell-interaction-', 'data-capell-theme-',
        'data-capell-insights-', 'data-capell-language-',
    ];

    private const array ALLOWED_CAPELL_RUNTIME_ATTRIBUTES = [
        'data-capell-interaction',
        'data-capell-cookie',
        'data-capell-origin-cookie',
        'data-capell-page-language',
    ];

    private const array BLOCKED_PUBLIC_VALUE_PATTERNS = [
        '/\bdata-(?:capell-authoring|capell-editor|field-path|model-id|page-id)\b/i',
        '/\b(?:fieldPath|field[_-]?path|modelId|model[_-]?id|pageId|page[_-]?id)\b\s*(?:=|:)/i',
        '/\b(?:CapellFrontendAuthoring|frontend-authoring|signed-editor|signed_editor|editable_regions)\b/i',
        '~(?<![A-Za-z0-9_-])/(?:admin|authoring/regions|filament|filament-peek|livewire)(?:[/?#)"\'\s]|$)~i',
        '/\b(?:Authorization\s*[:=]\s*)?Bearer\s+(?!\[redacted\])[A-Za-z0-9._~+\/=-]{8,}\b/i',
        '/\b(?:secret|token|password|passwd|pwd|credential|private_key|api_key|access_key|client_secret|webhook_secret|signing_secret)\s*[:=]\s*(?!\[redacted\])[^"\'\s,;}{]+/i',
        '/[?&](?:expires|signature|token|access_token|refresh_token)=(?!\[redacted\])[^&\s<>"\']+/i',
    ];

    /** @return list<non-empty-string> */
    public function blockedPublicKeys(): array
    {
        return self::BLOCKED_PUBLIC_KEYS;
    }

    /** @return list<non-empty-string> */
    public function authoringAttributes(): array
    {
        return self::AUTHORING_ATTRIBUTES;
    }

    /** @return list<non-empty-string> */
    public function authoringJsonKeys(): array
    {
        return self::AUTHORING_JSON_KEYS;
    }

    /** @return list<non-empty-string> */
    public function authoringSignedUrlJsonKeys(): array
    {
        return self::AUTHORING_SIGNED_URL_JSON_KEYS;
    }

    /** @return list<non-empty-string> */
    public function authoringClassOrIdMarkers(): array
    {
        return self::AUTHORING_CLASS_OR_ID_MARKERS;
    }

    /** @return list<non-empty-string> */
    public function allowedCapellRuntimeAttributePrefixes(): array
    {
        return self::ALLOWED_CAPELL_RUNTIME_ATTRIBUTE_PREFIXES;
    }

    /** @return list<non-empty-string> */
    public function allowedCapellRuntimeAttributes(): array
    {
        return self::ALLOWED_CAPELL_RUNTIME_ATTRIBUTES;
    }

    /** @return list<non-empty-string> */
    public function blockedPublicValuePatterns(): array
    {
        return self::BLOCKED_PUBLIC_VALUE_PATTERNS;
    }
}
