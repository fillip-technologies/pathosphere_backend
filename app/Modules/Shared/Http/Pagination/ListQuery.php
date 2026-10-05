<?php

namespace App\Modules\Shared\Http\Pagination;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Applies the API's list conventions (spec §8.3–8.4, decision D3):
 * `filter[field]=value`, `sort=-created_at,name`, `q=text`.
 *
 * Every endpoint declares which filters and sorts it allows; anything else is
 * a 422, so clients never get silently ignored parameters.
 */
final class ListQuery
{
    /** @var array<string, string|Closure(Builder, string): void> */
    private array $filters = [];

    /** @var array<string, string> */
    private array $sorts = [];

    private ?Closure $search = null;

    private function __construct(private readonly Request $request) {}

    public static function from(Request $request): self
    {
        return new self($request);
    }

    /**
     * @param  array<string, string|Closure(Builder, string): void>  $filters  API key => column, or a closure for custom logic
     */
    public function allowFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * @param  array<string, string>|list<string>  $sorts  API key => column; a plain list uses the same name for both
     */
    public function allowSorts(array $sorts): self
    {
        $this->sorts = array_is_list($sorts) ? array_combine($sorts, $sorts) : $sorts;

        return $this;
    }

    /** @param  Closure(Builder, string): void  $search */
    public function allowSearch(Closure $search): self
    {
        $this->search = $search;

        return $this;
    }

    public function apply(Builder $query): Builder
    {
        $this->applyFilters($query);
        $this->applySearch($query);
        $this->applySorts($query);

        return $query;
    }

    private function applyFilters(Builder $query): void
    {
        $requested = $this->request->query('filter', []);

        if (! is_array($requested)) {
            throw ValidationException::withMessages(['filter' => 'Use filter[field]=value.']);
        }

        $unknown = array_diff(array_keys($requested), array_keys($this->filters));

        if ($unknown !== []) {
            throw ValidationException::withMessages(
                array_fill_keys(array_map(fn ($key) => "filter.{$key}", $unknown), 'This filter is not supported.'),
            );
        }

        foreach ($requested as $key => $value) {
            $target = $this->filters[$key];
            $value = (string) $value;

            if ($target instanceof Closure) {
                $target($query, $value);

                continue;
            }

            $query->where($target, $value);
        }
    }

    private function applySearch(Builder $query): void
    {
        $text = trim((string) $this->request->query('q', ''));

        if ($text === '' || $this->search === null) {
            return;
        }

        ($this->search)($query, $text);
    }

    private function applySorts(Builder $query): void
    {
        $sortParam = trim((string) $this->request->query('sort', ''));

        if ($sortParam === '') {
            return;
        }

        foreach (explode(',', $sortParam) as $sortKey) {
            $direction = str_starts_with($sortKey, '-') ? 'desc' : 'asc';
            $key = ltrim($sortKey, '-');

            if (! isset($this->sorts[$key])) {
                throw ValidationException::withMessages(['sort' => "Sorting by '{$key}' is not supported."]);
            }

            $query->orderBy($this->sorts[$key], $direction);
        }
    }
}
