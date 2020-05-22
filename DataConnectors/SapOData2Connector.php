<?php
namespace exface\SapConnector\DataConnectors;

use exface\UrlDataConnector\DataConnectors\OData2Connector;
use exface\SapConnector\ModelBuilders\SapOData2ModelBuilder;
use exface\SapConnector\DataConnectors\Traits\CsrfTokenTrait;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Exceptions\DataSources\DataConnectionQueryTypeError;
use exface\SapConnector\DataConnectors\Traits\SapHttpConnectorTrait;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\CommonLogic\UxonObject;
use exface\UrlDataConnector\DataConnectors\Authentication\HttpBasicAuth;

/**
 * HTTP data connector for SAP oData 2.0 services.
 * 
 * This connector uses HTTP basic authentication by default. If you need another
 * authentication type, use the `authentication` configuration property as described
 * in the `HttpConnector`.
 * 
 * @author Andrej Kabachnik
 *
 */
class SapOData2Connector extends OData2Connector
{
    use CsrfTokenTrait;
    use SapHttpConnectorTrait;
    
    private $csrfRetryCount = 0;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\ODataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new SapOData2ModelBuilder($this);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\HttpConnector::performQuery()
     */
    protected function performQuery(DataQueryInterface $query)
    {
        if (! ($query instanceof Psr7DataQuery)) {
            throw new DataConnectionQueryTypeError($this, 'Connector "' . $this->getAliasWithNamespace() . '" expects a Psr7DataQuery as input, "' . get_class($query) . '" given instead!');
        }
        /* @var $query \exface\UrlDataConnector\Psr7DataQuery */
            
        $request = $query->getRequest();
        if ($request->getMethod() !== 'GET' && $request->getMethod() !== 'OPTIONS') {
            $query->setRequest($this->addCsrfHeaders($request));
        }
        
        try {
            $result = parent::performQuery($query);
            $this->csrfRetryCount = 0;
            return $result;
        } catch (DataQueryFailedError $e) {
            if ($response = $e->getQuery()->getResponse()) {
                /* var $response \Psr\Http\Message\ResponseInterface */
                if ($this->csrfRetryCount === 0 && $response->getStatusCode() == 403 && $response->getHeader('x-csrf-token')[0] === 'Required') {
                    $this->csrfRetryCount++;
                    $this->refreshCsrfToken();
                    return $this->performQuery($query);
                }
            }
            throw $e;
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\HttpConnector::hasAuthentication()
     */
    protected function hasAuthentication() : bool
    {
        return true;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\OData2Connector::getAuthProviderConfig()
     */
    protected function getAuthProviderConfig() : ?UxonObject
    {
        return parent::getAuthProviderConfig() ?? new UxonObject([
            'class' => '\\' . HttpBasicAuth::class
        ]);
    }
}