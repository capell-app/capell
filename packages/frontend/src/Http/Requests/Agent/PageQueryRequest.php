<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Requests\Agent;

use Capell\Core\Data\Properties\AgentPageQueryData;
use Capell\Core\Support\PublicRead\PublicReadCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class PageQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'set' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'filter' => ['sometimes', 'array', 'max:10'],
            'filter.*' => ['array', 'max:8'],
            'sort' => ['sometimes', 'string', 'max:100', 'regex:/^-?[a-zA-Z0-9_-]+$/'],
            'page' => ['sometimes', 'array:size,number,cursor'],
            'page.cursor' => ['sometimes', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function queryData(?int $languageId = null): AgentPageQueryData
    {
        return new AgentPageQueryData(
            set: $this->string('set')->toString(),
            filters: $this->validated('filter', []),
            sort: $this->has('sort') ? $this->string('sort')->toString() : null,
            size: $this->integer('page.size', 20),
            page: $this->integer('page.number', 1),
            languageId: $languageId,
            publicUrlRequired: true,
            cursor: $this->cursor(),
        );
    }

    private function cursor(): ?PublicReadCursor
    {
        if (! $this->has('page.cursor')) {
            return null;
        }

        $cursor = PublicReadCursor::decode($this->string('page.cursor')->toString());

        throw_unless($cursor instanceof PublicReadCursor, ValidationException::withMessages(['page.cursor' => __('validation.regex', ['attribute' => 'page.cursor'])]));

        return $cursor;
    }
}
