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
use Psr\Http\Message\RequestInterface;
use exface\Core\Interfaces\Exceptions\AuthenticationExceptionInterface;
use exface\UrlDataConnector\DataConnectors\Authentication\HttpBasicAuth;
use exface\Core\CommonLogic\UxonObject;

/**
 * HTTP data connector for SAP oData 2.0 services.
 * 
 * ## Handling SAP errors
 * 
 * SAP webservices provide errors in different formats depending on where they occur and
 * how the inner logic of an OData service is built. This connector automatically handles
 * the following cases depending on the HTTP response header `Content-Type`:
 * 
 * - JSON responses - typically received from logic inside an JSON OData services - the error text
 * is retreived from `$.error.message.value`. See the subsection below for other options.
 * - XML responses - in older HTTP endpoints like SOAP services or XML OData services - the value in
 * the `<message>` tag is used for error text. See the subsection below for other options.
 * - HTML responses - e.g. authentication errors - the top-most heading is used as error text (`<h1>`)
 * 
 * ### Exceptions in OData services
 * 
 * Exceptions thrown within the OData service are typically represented by an XML like the example
 * below. In case of JSON OData the same structure is expressed in JSON. See 
 * https://help.sap.com/doc/saphelp_nw75/7.5.5/DE-DE/95/fd2651c294256ee10000000a445394/frameset.htm
 * for details.
 * 
 * ```
 * <error xmlns="http://schemas.microsoft.com/ado/2007/08/dataservices/metadata">
 *       <code>/IWBEP/CM_TEA/004</code>
 *       <message xml:lang="en">'TEAM_012345678' is not in the defined range.</message>
 *       <innererror>
 *            <transactionid>4FE1D144B9AD22F2E10000000A420C41</transactionid>
 *            <errordetails>
 *                 <errordetail>
 *                      <code>/IWBEP/CM_TEA/002</code>
 *                      <message>'TEAM_012345678' is not a valid ID.</message>
 *                      <propertyref>Team/Team_Identifier</propertyref>
 *                      <severity>error</severity>
 *                 </errordetail>
 *                 <errordetail>
 *                      <code>/IWBEP/CM_TEA/004</code>
 *                      <message>Team ID 'TEAM_012345678' is not in the defined range.</message>
 *                      <propertyref>Team/Team_Identifier</propertyref>
 *                      <severity>error</severity>
 *                 </errordetail>
 *                 <errordetail>
 *                      <code>/IWBEP/CX_TEA_BUSINESS</code>
 *                      <message>'TEAM_012345678' is not a valid ID.</message>
 *                      <propertyref/>
 *                      <severity>error</severity>
 *                 </errordetail>
 *           </errordetails>
 *      </innererror>
 *  </error>
 *  
 * ```
 * 
 * This connector will use the values from `<code>` and `<message>` by default. However, you can also
 * get the code and message from one of the `<errordetail>` sections. For example, for JSON repsponse 
 * you can get the first message from `<errordetail>` by adding this line to the connection config:
 * 
 * ```
 *  "error_text_pattern": "/\"message\"\\s?:\\s?\"(?<message>[^\"]*)\"/"
 *  
 * ```
 * 
 * ## Authentication
 * 
 * This connector uses HTTP basic authentication by default. If you need another
 * authentication type, use the `authentication` configuration property as described
 * in the `HttpConnector`.
 * 
 * This connector works with SAP's CSRF tokens - see https://a.kabachnik.info/how-to-use-sap-web-services-with-csrf-tokens-from-third-party-web-apps.html
 * for more information about CSRF in SAP.
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
     * @see CsrfTokenTrait::isCsrfRequired()
     */
    protected function isCsrfRequired(RequestInterface $request) : bool
    {
        return $request->getMethod() !== 'GET' && $request->getMethod() !== 'OPTIONS';
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
        
        try {
            $result = parent::performQuery($query);
            $this->csrfRetryCount = 0;
            return $result;
        } catch (AuthenticationExceptionInterface $e) {
            $this->unsetCsrfHeaders();
            throw $e;
        } catch (DataQueryFailedError $e) {
            if ($response = $e->getQuery()->getResponse()) {
                /* var $response \Psr\Http\Message\ResponseInterface */
                if ($this->isCsrfRequired($query->getRequest()) && $this->csrfRetryCount === 0 && $response->getStatusCode() == 403 && $response->getHeader('x-csrf-token')[0] === 'Required') {
                    $this->csrfRetryCount++;
                    $this->refreshCsrfToken();
                    return $this->performQuery($query);
                }
            }
            throw $e;
        }
    }
    
    /**
     * @return string
     */
    public function getMetadataUrl()
    {
        $url = parent::getMetadataUrl();
        if (($sapClient = $this->getSapClient()) && stripos($url, 'sap-client=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '') . '&sap-client=' . $sapClient;
        }
        return $url;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\OData2Connector::getAuthProviderConfig()
     */
    protected function getAuthProviderConfig() : ?UxonObject
    {
        $uxon = parent::getAuthProviderConfig();
        
        // Make sure to include &sap-client in the authentication URL if a client is specified!
        if ($uxon !== null && is_a($uxon->getProperty('class'), '\\' . HttpBasicAuth::class, true)) {
            if ($sapClient = $this->getSapClient()) {
                $authUrl = $uxon->getProperty('authentication_url') ?? $this->getMetadataUrl();
                if (stripos($authUrl, 'sap-client=') === false) {
                    $authUrl = (strpos($authUrl, '?') === false ? '?' : '') . '?&sap-client=' . $sapClient;
                    $uxon->setProperty('authentication_url', $authUrl);
                }
            }
        }
        
        return $uxon;
    }
}