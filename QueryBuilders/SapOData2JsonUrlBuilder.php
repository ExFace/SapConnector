<?php
namespace exface\SapConnector\QueryBuilders;

use exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\StringDataType;
use exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder;
use exface\Core\CommonLogic\QueryBuilder\QueryPartAttribute;
use exface\Core\Exceptions\QueryBuilderException;
use Psr\Http\Message\RequestInterface;

/**
 * Query builder for SAP oData services in JSON format.
 * 
 * See the AbstractUrlBuilder for information about available data address properties.
 * 
 * @author Andrej Kabachnik
 *
 */
class SapOData2JsonUrlBuilder extends OData2JsonUrlBuilder
{
    /**
     * 
     * {@inheritdoc}
     * @see OData2JsonUrlBuilder::buildUrlFilterPredicate
     */
    protected function buildUrlFilterPredicate(QueryPartFilter $qpart, string $property, string $preformattedValue = null) : string
    {
        $comp = $qpart->getComparator();
        $type = $qpart->getDataType();
        switch ($comp) {
            case EXF_COMPARATOR_IS:
                // SAP NetWeaver produces a 500-error on substringof() eq true - need to remove the "eq true".
                switch (true) {
                    case $type instanceof StringDataType:
                        $escapedValue = $preformattedValue ?? $this->buildUrlFilterValue($qpart);
                        return "substringof({$escapedValue}, {$property})";
                    default:
                        return parent::buildUrlFilterPredicate($qpart, $property, $preformattedValue);
                } 
            default:
                return parent::buildUrlFilterPredicate($qpart, $property, $preformattedValue);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::buildUrlFilterValue($qpart, $preformattedValue)
     */
    protected function buildUrlFilterValue(QueryPartFilter $qpart, string $preformattedValue = null)
    {
        try {
            return parent::buildUrlFilterValue($qpart, $preformattedValue);
        } catch (QueryBuilderException $e) {
            // There are cases, when SAP OData services accept special values, that are not
            // valid for their data types, but are still meaningfull for SAP
            $type = $qpart->getDataType();
            $val = $preformattedValue ?? trim($qpart->getCompareValue());
            switch (true) {
                case $type instanceof DateDataType:
                    // In ABAP and empty date is something like '0000000'. To make it possible to enter
                    // this type of value in an OData date filter, we need to transform it to the below
                    // value. Same goes for the ExFace-internal zero-date value "0000-00-00".
                    if ((is_numeric($val) && intval($val) === 0) || StringDataType::startsWith($val, '0000-00-00') === true) {
                        return "datetime'0000-00-00T00:00'";
                    }
                // IDEA other types may also use '0000000' in various length as empty value,
                // add them here if so.
                default:
                    // Just rethrow the error if no special SAP-handling helped!
                    throw $e;
            }
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder::buildResultRows()
     */
    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        $rows = parent::buildResultRows($parsed_data, $query);
        
        foreach ($this->getAttributes() as $qpart) {
            $dataType = $qpart->getDataType();
            switch (true) {
                case $dataType instanceof DateDataType:
                    $colKey = $qpart->getDataAddress();
                    foreach ($rows as $rowNr => $row) {
                        $val = $row[$colKey];
                        switch (true) {
                            case StringDataType::startsWith($val, '/Date('):
                                $mil = substr($val, 6, -2);
                                // FIXME should not round here. Otherwise real date values allways change
                                // when an object is saved the first time after being created.
                                $seconds = round($mil / 1000);
                                $newVal = $dataType->parse($seconds);
                                $rows[$rowNr][$colKey] = $newVal;
                                break;
                            case StringDataType::startsWith($val, '@'):
                                $rows[$rowNr][$colKey] = $dataType->parse($val);
                                break;
                        }
                        
                    }
                    break;
                // Add more custom data type handling here
            }
        }
        
        return $rows;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildRequestToRead()
     */
    public function buildRequestToRead(bool $doSelect = true, bool $doFilter = true, bool $doSort = true, bool $doPaginate = true, bool $doAggregate = true) : RequestInterface
    {
        $request = parent::buildRequestToRead($doSelect, $doFilter, $doSort, $doPaginate, $doAggregate);
        
        $method = $request->getMethod();
        if ($method !== 'POST' || $method !== 'PUT' || $method !== 'DELETE') {
            $qPramsString = $request->getUri()->getQuery();
            if (mb_stripos($qPramsString, '&$format') === false) {
                $qPramsString .= '&$format=json';
                $request = $request->withUri($request->getUri()->withQuery($qPramsString));
            }
        }
        
        return $request;
    }
    
    /**
     * We need a custom JSON stringifier for SAP because some data types are handled in
     * a very (VERY) strange way.
     * 
     * - Edm.Decimal is a number, but MUST be enclosed in quotes
     * 
     * @see JsonUrlBuilder::encodeBody()
     */
    protected function encodeBody($serializableData) : string
    {
        $forceQuoteVals = [];
        
        foreach ($this->getValues() as $qpart) {
            if ($qpart->getDataAddress() && $this->needsQuotes($qpart) === true) {
                $forceQuoteVals[] = $qpart->getDataAddress();
            }
        }
        
        if (is_array($serializableData)) {
            $content = '';
            foreach ($serializableData as $val) {
                $content .= ($content ? ',' : '') . $this->encodeBody($val);
            }
            return '[' . $content . ']';
        } elseif ($serializableData instanceof \stdClass) {
            $pairs = [];
            $arr = (array) $serializableData;
            foreach ($arr as $p => $v) {
                switch (true) {
                    case in_array($p, $forceQuoteVals):
                    case (false === is_numeric($v) || (substr($v, 0, 1) === '0' && strlen($v) > 1)) && (is_bool($v) === false && strcasecmp($v, 'false') !== 0 && strcasecmp($v, 'true') !== 0):
                        $escapedValue = json_encode($v);
                        break;
                    default:
                        $escapedValue = $v;
                }
                $pairs[] = json_encode($p) . ':' . $escapedValue;
            }
            return '{' . implode(',', $pairs) . '}';
        } else {
            return '"' . str_replace('"', '\"', $serializableData) . '"';
        }
        
        return parent::encodeBody($serializableData);
    }
    
    protected function needsQuotes(QueryPartAttribute $qpart) : bool
    {
        $modelType = $qpart->getDataType();
        $odataType = $qpart->getDataAddressProperty(OData2JsonUrlBuilder::DAP_ODATA_TYPE);
        switch (true) {
            case $odataType === 'Edm.Decimal': return true;
            case $modelType instanceof StringDataType: return true;
        }
        return false;
    }
}