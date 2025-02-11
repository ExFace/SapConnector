<?php
namespace exface\SapConnector\DataConnectors;

use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use exface\SapConnector\ModelBuilders\SapAdtSqlModelBuilder;
use exface\Core\CommonLogic\DataQueries\SqlDataQuery;
use GuzzleHttp\Exception\RequestException;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Http\Message\ResponseInterface;
use exface\SapConnector\DataConnectors\Traits\CsrfTokenTrait;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\SapConnector\DataConnectors\Traits\SapHttpConnectorTrait;
use exface\UrlDataConnector\Exceptions\HttpConnectorRequestError;
use exface\Core\Interfaces\Exceptions\AuthenticationExceptionInterface;

/**
 * Data connector for the SAP ABAP Development Tools (ADT) SQL console webservice.
 * 
 * This connector works with SAP's CSRF tokens - see https://a.kabachnik.info/how-to-use-sap-web-services-with-csrf-tokens-from-third-party-web-apps.html
 * for more information about CSRF in SAP.
 * 
 * @author Andrej Kabachnik
 *
 */
class SapAdtSqlConnector extends HttpConnector implements SqlDataConnectorInterface
{
    use SapHttpConnectorTrait;
    use CsrfTokenTrait;
    
    private $lastRowNumberUrlParam = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\ODataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new SapAdtSqlModelBuilder($this);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\AbstractUrlConnector::getUrl()
     */
    public function getUrl()
    {
        return rtrim(parent::getUrl(), "/") . '/sap/bc/adt/datapreview/';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\HttpConnector::performQuery()
     */
    protected function performQuery(DataQueryInterface $query)
    {
        if (($query instanceof SqlDataQuery) === false) {
            throw new DataQueryFailedError($query, 'The SAP ADT SQL connector expects SqlDataQueries, ' . get_class($query) . ' received instead!');
        }
        /* @var $query \exface\Core\CommonLogic\DataQueries\SqlDataQuery */
        
        // Need to pass the connection back to the query (this is done by the AbstractSqlConnector in ordinary
        // SQL-connectors and would not happen here as this class extends the HttpConnector)
        $query->setConnection($this);
        
        try {
            $sql = $query->getSql();
            $urlParams = '';
            
            // Remove inline comments as they cause errors
            $sql = preg_replace('/\--.*/i', '', $sql);
            // Normalize line endings
            $sql = preg_replace('~(*BSR_ANYCRLF)\R~', "\r\n", $sql); 
            
            // Handle pagination
            // The DataPreview service seems to remove UP TO clauses from the queries
            // and replace them with the URL parameter rowNumber. We can fake pagination,
            // though, by requesting all rows (including the offset) and filtering the
            // offset away in extractDataRows(). This will make the pagination work, but
            // will surely become slower with every page - that should be acceptable,
            // however, as users are very unlikely to manually browse beyond page 10.
            $limits = [];
            $offset = 0;
            preg_match_all('/UP TO (\d+) OFFSET (\d+)/', $sql, $limits);
            if ($limits[0]) {
                $limit = $limits[1][0];
                $offset = $limits[2][0] ?? 0;
                $this->lastRowNumberUrlParam = $limit + $offset;  
                $sql = str_replace($limits[0][0], '', $sql);
            } else {
                $this->lastRowNumberUrlParam = 99999;
            }
            $urlParams .= '&rowNumber=' . $this->lastRowNumberUrlParam;
            
            // Finally, make sure the length of a single line in the body does not exceed 255 characters
            $sql = wordwrap($sql, 255, "\r\n");
            
            $response = $this->performRequest('POST', 'freestyle?' . $urlParams, $sql);
        } catch (HttpConnectorRequestError $e) {
            if (! $response) {
                $response = $e->getQuery()->getResponse();
            }
            $errorText = $response ? $this->getResponseErrorText($response, $e) : $e->getMessage();
            throw new DataQueryFailedError($query, $errorText, '6T2T2UI', $e);
        } catch (AuthenticationExceptionInterface $e) {
            $this->unsetCsrfHeaders();
            throw $e;
        }
        
        $xml = new Crawler($response->getBody()->__toString());
        $rows = $this->extractDataRows($xml, $offset);
        if (count($rows) === 99999) {
            throw new DataQueryFailedError($query, 'Query returns too many results: more than 99 999, which is the maximum for the SAP ADT SQL connetor. Please add pagination or filters!');
        }
        $query->setResultArray($rows);
        
        $cnt = $this->extractTotalRowCounter($xml);
        if ($cnt !== null && ! ($cnt === 0 && empty($rows) === false)) {
            $query->setResultRowCounter($cnt);
        }
        
        return $query;
    }

    /**
     * 
     * @param Crawler $xmlCrawler
     * @return int|NULL
     */
    protected function extractTotalRowCounter(Crawler $xmlCrawler) : ?int
    {
        $totalRows = $xmlCrawler->filterXPath('//dataPreview:totalRows')->text();
        return is_numeric($totalRows) === true ? intval($totalRows) : null;
    }
    
    protected function extractDataRows(Crawler $xmlCrawler, int $offset = 0) : array
    {
        $data = [];
        foreach($xmlCrawler->filterXPath('//dataPreview:columns') as $colNode) {
            $colCrawler = new Crawler($colNode);
            $colName = $colCrawler->filterXPath('//dataPreview:metadata')->attr('dataPreview:name');
            $r = 0;
            foreach($colCrawler->filterXPath('//dataPreview:data') as $dataNode) {
                if ($r < $offset) {
                    $r++;
                    continue;
                }
                $data[$r][$colName] = $dataNode->textContent;
                $r++;
            }
        }
        return $data;
    }
    
    public function performRequest(string $method, string $url, string $body, array $headers = []) : ResponseInterface
    {
        $headers = array_merge([
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        ], $this->getCsrfHeaders(), $headers);
        if ($fixedParams = $this->getFixedUrlParams()) {
            $url .= $fixedParams;
        }
        $request = new Request($method, $url, $headers, $body);
        try {
            $response = $this->getClient()->send($request);
        } catch (RequestException $e) {
            $response = $response ?? $e->getResponse();
            $query = new Psr7DataQuery($request);
            if ($response !== null) {
                $query->setResponse($response);
            } else {
                throw $this->createResponseException($query, null, $e);
            }
            
            if ($e->getResponse()->getHeader('X-CSRF-Token')[0] === 'Required' || $e->getResponse()->getStatusCode() == 401) {
                $this->refreshCsrfToken();
                $request = $this->addCsrfHeaders($request);
                try {
                    $response = $this->getClient()->send($request);
                } catch (RequestException $e) {
                    $response = $response ?? $e->getResponse();
                    $query = new Psr7DataQuery($request);
                    $query->setResponse($response);
                    throw $this->createResponseException($query, null, $e);
                }
            } else {
                throw $this->createResponseException($query, $response, $e);
            }
        }
        
        return $response;
    }
    
    public function getAffectedRowsCount(SqlDataQuery $query)
    {
        return $this->lastRowNumberUrlParam;
    }

    public function getInsertId(SqlDataQuery $query)
    {}

    public function makeArray(SqlDataQuery $query)
    {}

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\SqlDataConnectorInterface::runSql()
     */
    public function runSql($string, bool $multiquery = null)
    {
        $query = new SqlDataQuery();
        $query->setSql($string);
        return $this->query($query);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\TextualQueryConnectorInterface::runCustomQuery()
     */
    public function runCustomQuery(string $string) : DataQueryInterface
    {
        return $this->runSql($string);
    }

    public function freeResult(SqlDataQuery $query)
    {
        return;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\SqlDataConnectorInterface::escapeString()
     */
    public function escapeString(string $string) : string
    {
        if (function_exists('mb_ereg_replace')) {
            return mb_ereg_replace('[\x00\x0A\x0D\x1A\x22\x27\x5C]', '\\\0', $string);
        } else {
            return preg_replace('~[\x00\x0A\x0D\x1A\x22\x27\x5C]~u', '\\\$0', $string);
        }
    }

    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\SqlDataConnectorInterface::canJoin()
     */
    public function canJoin(DataConnectionInterface $otherConnection) : bool
    {
        if (! $otherConnection instanceof $this) {
            return false;
        }
        return $this->getUrl() === $otherConnection->getUrl();
    }
}