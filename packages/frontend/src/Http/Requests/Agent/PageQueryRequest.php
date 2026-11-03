<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Requests\Agent;

use Capell\Core\Data\Properties\AgentPageQueryData;
use Illuminate\Foundation\Http\FormRequest;

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
            'page.cursor' => ['sometimes', 'string', 'max:2048'],
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

    /**
     * The sealed cursor, opened (and, when it does not open, ignored) by the
     * query for its own context.
     */
    private function cursor(): ?string
    {
        return $this->has('page.cursor') ? $this->string('page.cursor')->toString() : null;
    }
}
