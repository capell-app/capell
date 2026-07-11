<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

final class PublicOutputLeakPolicy
{
    /** @return list<non-empty-string> */
    public function blockedPublicKeys(): array
    {
        return [
            'access_token', 'admin_path', 'admin_url', 'api_key', 'api_secret', 'authorization',
            'bearer', 'bearer_token', 'client_secret', 'credential', 'credentials', 'edit_url',
            'editable_regions', 'expires', 'field_path', 'filament_url', 'internal_id',
            'internal_model_id', 'model_id', 'model_type', 'page_id', 'password', 'permission',
            'permissions', 'private_key', 'prompt', 'record_key', 'recordkey', 'refresh_token',
            'secret', 'secret_prompt', 'selector', 'signature', 'signed_editor_url', 'signed_url',
            'system_prompt', 'token', 'webhook_secret',
        ];
    }

    /** @return list<non-empty-string> */
    public function authoringAttributes(): array
    {
        return [
            'data-capell-authoring', 'data-capell-editable', 'data-capell-editor',
            'data-capell-editor-url', 'data-field-path', 'data-model-id', 'data-permission',
            'data-capell-package',
        ];
    }

    /** @return list<non-empty-string> */
    public function authoringJsonKeys(): array
    {
        return [
            'field_path', 'fieldpath', 'model_id', 'modelid', 'editor_url', 'editorurl',
            'signed_editor_url', 'signededitorurl', 'signed_admin_url', 'signedadminurl',
        ];
    }

    /** @return list<non-empty-string> */
    public function authoringSignedUrlJsonKeys(): array
    {
        return ['signed_url', 'signedurl'];
    }

    /** @return list<non-empty-string> */
    public function authoringClassOrIdMarkers(): array
    {
        return ['capell-authoring', 'capell-editor'];
    }

    /** @return list<non-empty-string> */
    public function allowedCapellRuntimeAttributePrefixes(): array
    {
        return [
            'data-capell-widget-', 'data-capell-interaction-', 'data-capell-theme-',
            'data-capell-insights-',
        ];
    }

    /** @return list<non-empty-string> */
    public function allowedCapellRuntimeAttributes(): array
    {
        return ['data-capell-interaction'];
    }

    /** @return list<non-empty-string> */
    public function blockedPublicValuePatterns(): array
    {
        return [
            '/\bdata-(?:capell-authoring|capell-editor|field-path|model-id|page-id)\b/i',
            '/\b(?:fieldPath|field[_-]?path|modelId|model[_-]?id|pageId|page[_-]?id)\b\s*(?:=|:)/i',
            '/\b(?:CapellFrontendAuthoring|frontend-authoring|signed-editor|signed_editor|editable_regions)\b/i',
            '~(?<![A-Za-z0-9_-])/(?:admin|authoring/regions|filament|filament-peek|livewire)(?:[/?#)"\'\s]|$)~i',
            '/\b(?:Authorization\s*[:=]\s*)?Bearer\s+(?!\[redacted\])[A-Za-z0-9._~+\/=-]{8,}\b/i',
            '/\b(?:secret|token|password|passwd|pwd|credential|private_key|api_key|access_key|client_secret|webhook_secret|signing_secret)\s*[:=]\s*(?!\[redacted\])[^"\'\s,;}{]+/i',
            '/[?&](?:expires|signature|token|access_token|refresh_token)=(?!\[redacted\])[^&\s<>"\']+/i',
        ];
    }
}
