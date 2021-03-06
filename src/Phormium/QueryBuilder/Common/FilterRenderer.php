<?php

namespace Phormium\QueryBuilder\Common;

use Phormium\Exception\OrmException;
use Phormium\Filter\ColumnFilter;
use Phormium\Filter\CompositeFilter;
use Phormium\Filter\Filter;
use Phormium\Filter\RawFilter;
use Phormium\Query\QuerySegment;

class FilterRenderer
{
    /**
     * @var Quoter
     */
    private $quoter;

    public function __construct(Quoter $quoter)
    {
        $this->quoter = $quoter;
    }

    public function renderFilter(Filter $filter)
    {
        if ($filter instanceof ColumnFilter) {
            return $this->renderColumnFilter($filter);
        }

        if ($filter instanceof CompositeFilter) {
            return $this->renderCompositeFilter($filter);
        }

        if ($filter instanceof RawFilter) {
            return $this->renderRawFilter($filter);
        }

        throw new OrmException("Unknown filter class: " . get_class($filter));
    }

    public function renderRawFilter(RawFilter $filter)
    {
        return new QuerySegment($filter->condition(), $filter->arguments());
    }

    public function renderCompositeFilter(CompositeFilter $filter)
    {
        $subFilters = $filter->filters();

        if (empty($subFilters)) {
            throw new OrmException("Canot render composite filter. No filters defined.");
        }

        if (count($subFilters) === 1) {
            return $this->renderFilter($subFilters[0]);
        }

        $segments = array_map([$this, "renderFilter"], $subFilters);

        $separator = new QuerySegment($filter->operation());
        $imploded = QuerySegment::implode($separator, $segments);

        return QuerySegment::embrace($imploded);
    }

    /**
     * Renders a WHERE condition for the given filter.
     */
    public function renderColumnFilter(ColumnFilter $filter)
    {
        $column = $this->quoter->quote($filter->column());
        $operation = $filter->operation();
        $value = $filter->value();

        switch ($operation) {
            case ColumnFilter::OP_EQUALS:
                return is_null($value) ?
                    $this->renderIsNull($column) :
                    $this->renderSimple($column, $operation, $value);

            case ColumnFilter::OP_NOT_EQUALS:
            case ColumnFilter::OP_NOT_EQUALS_ALT:
                return is_null($value) ?
                    $this->renderNotNull($column) :
                    $this->renderSimple($column, $operation, $value);

            case ColumnFilter::OP_LIKE:
            case ColumnFilter::OP_NOT_LIKE:
            case ColumnFilter::OP_GREATER:
            case ColumnFilter::OP_GREATER_OR_EQUAL:
            case ColumnFilter::OP_LESSER:
            case ColumnFilter::OP_LESSER_OR_EQUAL:
                return $this->renderSimple($column, $operation, $value);

            case ColumnFilter::OP_LIKE_CASE_INSENSITIVE:
                return $this->renderLikeCaseInsensitive($column, $operation, $value);

            case ColumnFilter::OP_IN:
                return $this->renderIn($column, $operation, $value);

            case ColumnFilter::OP_NOT_IN:
                return $this->renderNotIn($column, $operation, $value);

            case ColumnFilter::OP_IS_NULL:
                return $this->renderIsNull($column);

            case ColumnFilter::OP_NOT_NULL:
            case ColumnFilter::OP_NOT_NULL_ALT:
                return $this->renderNotNull($column);

            case ColumnFilter::OP_BETWEEN:
                return $this->renderBetween($column, $operation, $value);

            default:
                throw new OrmException("Unknown filter operation [{$operation}].");
        }
    }

    /**
     * Renders a simple condition which can be expressed as:
     *      <column> <operator> <value>
     */
    private function renderSimple($column, $operation, $value)
    {
        $where = "{$column} {$operation} ?";

        return new QuerySegment($where, [$value]);
    }

    private function renderBetween($column, $operation, $values)
    {
        $where = "$column BETWEEN ? AND ?";

        return new QuerySegment($where, $values);
    }

    private function renderIn($column, $operation, $values)
    {
        $placeholders = array_fill(0, count($values), '?');
        $where = "$column IN (" . implode(', ', $placeholders) . ")";

        return new QuerySegment($where, $values);
    }

    private function renderLikeCaseInsensitive($column, $operation, $value)
    {
        $where = "lower($column) LIKE lower(?)";

        return new QuerySegment($where, [$value]);
    }

    private function renderNotIn($column, $operation, $values)
    {
        $placeholders = array_fill(0, count($values), '?');
        $where = "$column NOT IN (" . implode(', ', $placeholders) . ")";

        return new QuerySegment($where, $values);
    }

    private function renderIsNull($column)
    {
        return new QuerySegment("$column IS NULL");
    }

    private function renderNotNull($column)
    {
        return new QuerySegment("$column IS NOT NULL");
    }
}
