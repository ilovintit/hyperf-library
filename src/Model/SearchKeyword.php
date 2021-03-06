<?php
declare(strict_types=1);

namespace Iit\HyLib\Model;

use Carbon\Carbon;
use Closure;
use Exception;
use Hyperf\Database\Model\Builder;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use InvalidArgumentException;

/**
 * Class SearchKeyword
 * @package Iit\HyLib\Model
 */
class SearchKeyword
{
    const SEARCH_KEYWORD_TYPE_LIKE = 'like';//模糊搜索
    const SEARCH_KEYWORD_TYPE_MATCH = 'match';//等于
    const SEARCH_KEYWORD_TYPE_IN = 'in';
    const SEARCH_KEYWORD_TYPE_BETWEEN = 'between';//区间
    const SEARCH_KEYWORD_TYPE_DATE_BETWEEN = 'date_between';//时间间隔
    const SEARCH_KEYWORD_TYPE_MORE = 'more';//大于
    const SEARCH_KEYWORD_TYPE_LESS = 'less';//小于
    const SEARCH_KEYWORD_TYPE_MORE_OR_EQUAL = 'more_or_equal';//大于等于
    const SEARCH_KEYWORD_TYPE_LESS_OR_EQUAL = 'less_or_equal';//小于等于
    const SEARCH_KEYWORD_TYPE_NOT_EQUAL = 'not_equal';//不等于
    const SEARCH_KEYWORD_TYPE_IS_NULL = 'is_null';//是否为null
    const SEARCH_KEYWORD_TYPE_IS_NOT_NULL = 'is_not_null';//是否不为null
    const SEARCH_KEYWORD_TYPE_IS_OR_NOT_NULL = 'is_or_not_null';//是否不为null
    const SEARCH_KEYWORD_TYPE_SUB_IN = 'sub_in';//子查询in
    const SEARCH_KEYWORD_TYPE_SUB = 'sub';//子查询

    /**
     * @param $searchKeywords
     * @param $query
     * @param array $rules
     * @param Closure|null $customQuery
     * @return Builder
     */
    public static function query($searchKeywords, $query, array $rules, Closure $customQuery = null): Builder
    {
        /**
         * @var Builder $query
         */
        return $query->where(function (Builder $subQuery) use ($searchKeywords, $rules, $customQuery) {
            foreach ($rules as $rule) {
                $subQuery = SearchKeyword::getQueryByRuleFromRequest($searchKeywords, $rule, $subQuery);
            }
            if ($customQuery === null) {
                return $subQuery;
            }
            return $customQuery($subQuery);
        });
    }

    /**
     * 根据规则调用对应的方法处理搜索条件
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     */
    public static function getQueryByRuleFromRequest($searchKeywords, $rule, Builder $subQuery): Builder
    {
        if (!isset($rule['type'])) {
            throw new InvalidArgumentException('search keyword rule type required');
        }
        $ruleType = $rule['type'] instanceof Closure ? $rule['type']($searchKeywords) : $rule['type'];
        if ($ruleType === null) {
            return $subQuery;
        }
        if (isset($rule['required']) && !empty($rule['required']) && self::checkValueIssetAndEmpty($searchKeywords, $rule['required']) === null) {
            return $subQuery;
        }
        $method = 'getQueryBy' . Str::studly($ruleType) . 'Rule';
        if (self::isSpecialType($ruleType)) {
            return self::$method($searchKeywords, $rule, $subQuery);
        }
        list($queryKey, $convertValue) = self::convertAndFilterValue($rule, $searchKeywords);
        return $convertValue === null ? $subQuery : self::$method($queryKey, $convertValue, $subQuery);
    }

    /**
     * @param $rule
     * @param $searchKeywords
     * @return array
     */
    protected static function convertAndFilterValue($rule, $searchKeywords): array
    {
        if (isset($rule['value']) && is_array($rule['key'])) {
            return [null, null];
        }
        if (isset($rule['value']) && isset($rule['key'])) {
            $value = $rule['value'] instanceof Closure ? $rule['value']($searchKeywords, $rule) : $rule['value'];
            return $value === null ? [null, null] : [$rule['key'], $value];
        }
        list($readKey, $queryKey) = self::checkValue($searchKeywords, self::convertRuleKeyToKey($rule));
        if ($readKey === null || $queryKey === null) {
            return [null, null];
        }
        $value = self::filterValue($searchKeywords, $readKey, $rule);
        if ($value === null) {
            return [null, null];
        }
        $value = self::convertValue($searchKeywords, $rule, $readKey, $value);
        return empty($value) && $value !== 0 ? [null, null] : [self::convertQueryKey($queryKey, $rule), $value];
    }

    /**
     * @param $queryKey
     * @param $rule
     * @return string
     */
    protected static function convertQueryKey($queryKey, $rule): string
    {
        return isset($rule['table']) && !empty($rule['table']) ? $rule['table'] . '.' . $queryKey : $queryKey;
    }

    /**
     * @param $type
     * @return bool
     */
    protected static function isSpecialType($type): bool
    {
        $specialType = [
            self::SEARCH_KEYWORD_TYPE_BETWEEN,
            self::SEARCH_KEYWORD_TYPE_DATE_BETWEEN,
            self::SEARCH_KEYWORD_TYPE_SUB_IN,
            self::SEARCH_KEYWORD_TYPE_SUB,
            self::SEARCH_KEYWORD_TYPE_IS_NULL,
        ];
        return in_array($type, $specialType);
    }

    /**
     * 转换规则的key为实际的key
     *
     * @param $rule
     * @return array|null
     */
    protected static function convertRuleKeyToKey($rule): ?array
    {
        if (!isset($rule['key'])) {
            throw new InvalidArgumentException('search keyword rule key required');
        }
        $arrayKey = is_string($rule['key']) ? [$rule['key']] : (is_array($rule['key']) ? $rule['key'] : null);
        if ($arrayKey === null) {
            throw new InvalidArgumentException('search keyword rule key invalid');
        }
        return $arrayKey;
    }

    /**
     * 检查值是否符合规范
     *
     * @param $searchKeywords
     * @param $arrayKey
     * @return array
     */
    protected static function checkValue($searchKeywords, $arrayKey): array
    {
        if (count($arrayKey) > 1) {
            $arrayKey = collect($arrayKey)->filter(function ($value, $key) use ($searchKeywords) {
                $readKey = is_numeric($key) ? $value : $key;
                return isset($searchKeywords[$readKey]) && !empty($searchKeywords[$readKey]);
            })->values()->toArray();
        }
        if (count($arrayKey) === 1 && isset($arrayKey[0])) {
            return [$arrayKey[0], $arrayKey[0]];
        } else if (count($arrayKey) === 1 && !isset($arrayKey[0])) {
            $key = array_keys($arrayKey)[0];
            return [$key, $arrayKey[$key]];
        } else {
            return [null, null];
        }
    }

    /**
     * 检查区间值是否符合规范
     *
     * @param $searchKeyword
     * @param $key
     * @return array
     */
    protected static function checkBetweenValue($searchKeyword, $key): array
    {
        if (self::checkValueIssetAndEmpty($searchKeyword, $key) === null) {
            return [null, null];
        }
        list($beginValue, $endValue) = $searchKeyword[$key];
        if (!empty($beginValue) && !empty($endValue)) {
            return [$beginValue, $endValue];
        }
        return [null, null];
    }

    /**
     * 过滤之后的值
     *
     * @param $searchKeywords
     * @param $filterKey
     * @param $rule
     * @return null|string|int
     */
    protected static function filterValue($searchKeywords, $filterKey, $rule)
    {
        if (self::checkValueIssetAndEmpty($searchKeywords, $filterKey) === null) {
            return null;
        }
        if (!isset($rule['filter']) || empty($rule['filter'])) {
            return isset($searchKeywords[$filterKey]) ? self::checkValueEmpty($searchKeywords[$filterKey]) : null;
        }
        if (in_array($rule['type'], [
                self::SEARCH_KEYWORD_TYPE_IN,
                self::SEARCH_KEYWORD_TYPE_DATE_BETWEEN,
                self::SEARCH_KEYWORD_TYPE_BETWEEN
            ]) && is_array($searchKeywords[$filterKey])) {
            $filterNewKey = $filterKey . '.*';
        } else {
            $filterNewKey = $filterKey;
        }
        if (isset($rule['filter'][$filterKey])) {
            $rules = [$filterNewKey => $rule['filter'][$filterKey]];
        } else {
            $rules = [$filterNewKey => $rule['filter']];
        }
        $validator = ApplicationContext::getContainer()
            ->get(ValidatorFactoryInterface::class)
            ->make($searchKeywords, $rules);
        if ($validator->errors()->isEmpty()) {
            return self::checkValueEmpty($searchKeywords[$filterKey]);
        }
        return null;
    }

    /**
     * 检测值是否为空
     *
     * @param $value
     * @return null|string|int
     */
    protected static function checkValueEmpty($value)
    {
        return (empty($value) && $value !== 0) ? null : $value;
    }

    /**
     * @param $dataList
     * @param $key
     * @return bool|null
     */
    public static function checkValueIssetAndEmpty($dataList, $key): ?bool
    {
        return isset($dataList[$key]) ? (empty($dataList[$key]) ? null : true) : null;
    }

    /**
     * @param $searchKeywords
     * @param $rule
     * @param $key
     * @param $value
     * @return mixed
     */
    protected static function convertValue($searchKeywords, $rule, $key, $value)
    {
        if (!isset($rule['convert']) || empty($rule['convert'])) {
            return $value;
        }
        if (isset($rule['convert'][$key]) && $rule['convert'][$key] instanceof Closure) {
            return $rule['convert'][$key]($value, $key, $searchKeywords);
        }
        return $value;
    }

    /**
     * 模糊搜索
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByLikeRule($queryKey, $value, Builder $subQuery): Builder
    {
        return $subQuery->where($queryKey, 'like', '%' . $value . '%');
    }

    /**
     * 精准匹配
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByMatchRule($queryKey, $value, Builder $subQuery): Builder
    {
        return $subQuery->where($queryKey, '=', $value);
    }

    /**
     * 存在多个条件
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByInRule($queryKey, $value, Builder $subQuery): Builder
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return $subQuery;
        }
        return $subQuery->whereIn($queryKey, $value);
    }

    /**
     * 大于条件
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByMoreRule($queryKey, $value, Builder $subQuery): Builder
    {
        return $subQuery->where($queryKey, '>', $value);
    }

    /**
     * 小于条件
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByLessRule($queryKey, $value, Builder $subQuery): Builder
    {
        return $subQuery->where($queryKey, '<', $value);
    }

    /**
     * 大于等于条件
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByMoreOrEqualRule($queryKey, $value, Builder $subQuery): Builder
    {
        return $subQuery->where($queryKey, '>=', $value);
    }

    /**
     * 小于等于条件
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByLessOrEqualRule($queryKey, $value, Builder $subQuery): Builder
    {
        return $subQuery->where($queryKey, '<=', $value);
    }

    /**
     * 不等于条件
     *
     * @param $queryKey
     * @param $value
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByNotEqualRule($queryKey, $value, Builder $subQuery): Builder
    {
        return $subQuery->where($queryKey, '<>', $value);
    }

    /**
     * @param $searchKeywords
     * @param $rule
     * @return array|null
     */
    protected static function getBetweenValue($searchKeywords, $rule): ?array
    {
        $keys = self::convertRuleKeyToKey($rule);//找出搜索关键词
        if (isset($rule['value'])) {
            $value = $rule['value'] instanceof Closure ? $rule['value']($searchKeywords, $rule) : $rule['value'];
            if ($value === null || !is_array($value) || count($value) !== 2) {
                return null;
            }
            list($beginValue, $endValue) = $value;
            list($readKey, $queryKey) = self::checkValue($searchKeywords, is_numeric(array_keys($keys)[0]) ? array_values($keys) : $keys);
            return [self::convertQueryKey($queryKey, $rule), $beginValue, $endValue];
        }
        $filterKeys = collect($keys)->filter(function ($value, $key) use ($searchKeywords) {
            list($beginValue, $endValue) = self::checkBetweenValue($searchKeywords, is_numeric($key) ? $value : $key);
            return !empty($beginValue) && !empty($endValue);
        });
        //找出搜索内容不为空的搜索条件
        if ($filterKeys->count() !== 1) {
            return null;
        }
        //如果数组key为数字，只传数组的value
        list($readKey, $queryKey) = self::checkValue($searchKeywords, is_numeric(array_keys($filterKeys->toArray())[0]) ? array_values($filterKeys->toArray()) : $filterKeys->toArray());
        list($beginValue, $endValue) = self::filterValue($searchKeywords, $readKey, $rule);
        if ($beginValue === null || $endValue === null) {
            return null;
        }
        $beginValue = self::convertValue($searchKeywords, $rule, $readKey, $beginValue);
        $endValue = self::convertValue($searchKeywords, $rule, $readKey, $endValue);
        return [self::convertQueryKey($queryKey, $rule), $beginValue, $endValue];
    }

    /**
     * 区间条件
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByBetweenRule($searchKeywords, $rule, Builder $subQuery): Builder
    {
        if (!$betweenValue = self::getBetweenValue($searchKeywords, $rule)) {
            return $subQuery;
        }
        list($queryKey, $beginValue, $endValue) = $betweenValue;
        return $subQuery->whereBetween($queryKey, [$beginValue, $endValue]);
    }

    /**
     * 时间区间条件
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     * @throws Exception
     */
    protected static function getQueryByDateBetweenRule($searchKeywords, $rule, Builder $subQuery): Builder
    {
        $rule['filter'] = isset($rule['filter']) ? $rule['filter'] : 'date_format:Y-m-d';
        if (!$betweenValue = self::getBetweenValue($searchKeywords, $rule)) {
            return $subQuery;
        }
        list($queryKey, $beginValue, $endValue) = $betweenValue;
        $format = isset($rule['format']) ? $rule['format'] : 'Y-m-d H:i:s';
        $beginValue = (new Carbon($beginValue))->setTime(0, 0)->format($format);
        $endValue = (new Carbon($endValue))->setTime(23, 59, 59)->format($format);
        return $subQuery->where($queryKey, '>=', $beginValue)->where($queryKey, '<=', $endValue);
    }

    /**
     * in子查询
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryBySubInRule($searchKeywords, $rule, Builder $subQuery): Builder
    {
        $subQueryFunction = isset($rule['sub']) ? $rule['sub'] : null;
        if (empty($subQueryFunction)) {
            return $subQuery;
        }
        list($queryKey, $convertValue) = self::convertAndFilterValue($rule, $searchKeywords);
        return empty($convertValue) ? $subQuery : $subQuery->whereIn($queryKey, function ($query) use ($subQueryFunction, $convertValue, $searchKeywords, $rule) {
            $subQueryFunction($query, $convertValue, $searchKeywords, $rule);
        });
    }

    /**
     * 子查询
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryBySubRule($searchKeywords, $rule, Builder $subQuery): Builder
    {
        $subQueryFunction = isset($rule['sub']) ? $rule['sub'] : null;
        if (empty($subQueryFunction)) {
            return $subQuery;
        }
        list($queryKey, $convertValue) = self::convertAndFilterValue($rule, $searchKeywords);
        return empty($convertValue) ? $subQuery : $subQuery->where(function ($query) use ($subQueryFunction, $convertValue, $queryKey, $searchKeywords, $rule) {
            $subQueryFunction($query, $convertValue, $queryKey, $searchKeywords, $rule);
        });
    }

    /**
     * @param $value
     * @param $trueValue
     * @return bool
     */
    protected static function checkValueIsTrue($value, $trueValue): bool
    {
        $isNull = false;
        if (is_array($trueValue)) {
            $isNull = in_array($value, $trueValue);
        }
        if ($trueValue !== null) {
            $isNull = $value === $trueValue;
        }
        return $isNull;
    }

    /**
     * 是否为空
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByIsNullRule($searchKeywords, $rule, Builder $subQuery): Builder
    {
        $trueValue = isset($rule['trueValue']) ? $rule['trueValue'] : null;
        list($queryKey, $convertValue) = self::convertAndFilterValue($rule, $searchKeywords);
        return self::checkValueIsTrue($convertValue, $trueValue) === true ? $subQuery->whereNull($queryKey) : $subQuery;
    }

    /**
     * 是否不为空
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByIsNotNullRule($searchKeywords, $rule, Builder $subQuery): Builder
    {
        $trueValue = isset($rule['trueValue']) ? $rule['trueValue'] : null;
        list($queryKey, $convertValue) = self::convertAndFilterValue($rule, $searchKeywords);
        return self::checkValueIsTrue($convertValue, $trueValue) === true ? $subQuery->whereNotNull($queryKey) : $subQuery;
    }

    /**
     * 是或否为空
     *
     * @param $searchKeywords
     * @param $rule
     * @param Builder $subQuery
     * @return Builder
     */
    protected static function getQueryByIsOrNotNullRule($searchKeywords, $rule, Builder $subQuery): Builder
    {
        $trueValue = isset($rule['trueValue']) ? $rule['trueValue'] : null;
        list($queryKey, $convertValue) = self::convertAndFilterValue($rule, $searchKeywords);
        return self::checkValueIsTrue($convertValue, $trueValue) === true ? $subQuery->whereNull($queryKey) : $subQuery->whereNotNull($queryKey);
    }

}
