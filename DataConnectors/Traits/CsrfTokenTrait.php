<?php
namespace exface\SapConnector\DataConnectors\Traits;

use GuzzleHttp\Exception\RequestException;
use exface\Core\Exceptions\DataSources\DataConnectionFailedError;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\Core\Interfaces\Contexts\ContextManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use function GuzzleHttp\Psr7\_caseless_remove;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use GuzzleHttp\Psr7\Request;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\Interfaces\UserInterface;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\CommonLogic\UxonObject;

/**
 * This trait adds support for CSRF-tokens to an HTTP connector.
 * 
 * The trait hooks in to the `addDefaultHeadersToQuery()` method of `HttpConnector` and adds CSRF
 * related headers if required for the specific request (determined by `isCsrfRequired()`).
 * 
 * By default the CSRF headers are `X-CSRF-Token` and `Cookie`. These are stored in the session
 * context. If they are not there, the trait will automatically perform `refreshCsrfToken()`
 * sending a GET-request to the `csrf_request_url` and storing the response headers in the session.
 * 
 * @author Andrej Kabachnik
 *
 */
trait CsrfTokenTrait
{    
    /**
     * 
     * @var array|NULL
     */
    private $csrfHeaders = null;
    
    /**
     * 
     * @var string
     */
    private $csrfRequestUrl = '';
    
    /**
     * 
     * @param RequestInterface $request
     * @return bool
     */
    protected function isCsrfRequired(RequestInterface $request) : bool
    {
        return true;
    }
    
    /**
     * 
     * @return string
     */
    protected function getCsrfContextVarName() : string
    {
        return 'csrf_' . $this->getId();
    }
    
    /**
     * Returns a PSR7 compatible array of request headers required for the CSRF check
     * 
     * @return array
     */
    protected function getCsrfHeaders() : array
    {
        if ($this->csrfHeaders === null) {
            $headers = null;
            $ctxVar = $this->getWorkbench()->getApp('exface.SapConnector')->getContextVariable($this->getCsrfContextVarName(), ContextManagerInterface::CONTEXT_SCOPE_SESSION);
            $ctxArray = $ctxVar !== null ? json_decode($ctxVar, true) : [];
            if (! empty($ctxArray)) {
                if ($ctxArray['hash'] === $this->getCsrfHash()) {
                    $headers = $ctxArray['headers'];
                } else {
                    $this->unsetCsrfHeaders();
                }
            }
            
            if (is_array($headers) && ! empty($headers)) {
                $this->csrfHeaders = $headers;
            } else {
                $this->refreshCsrfToken();
            }
        }
        
        return $this->csrfHeaders;
    }
    
    /**
     * Computes a hash of connection properties, changes of which should cause a token refresh.
     * 
     * By default, these properties are
     * - url
     * - csrf_request_url
     * - authentication provider settings
     * 
     * The hash is stored together with the CSRF token and headers and used to detect changes in
     * the configuration before every connection. If changes are detected, a new CSRF token is
     * requested.
     * 
     * The authentication provider is not entirely part of the hash, but only it's `getDefaultRequestOptions()`
     * because this is what is visible on "the other end" of the connection.
     * 
     * @return string
     */
    protected function getCsrfHash() : string
    {
        return md5($this->getUrl() . $this->getCsrfRequestUrl() . json_encode($this->getAuthProvider()->getDefaultRequestOptions([])));
    }
    
    /**
     * Replaces the CSRF token and other headers in the storage.
     * 
     * @param array $headers
     * @return HttpConnectionInterface
     */
    protected function setCsrfHeaders(array $headers) : HttpConnectionInterface
    {
        $this->csrfHeaders = $headers;
        $ctxArray = [
            'hash' => $this->getCsrfHash(),
            'headers' => $headers
        ];
        $this->getWorkbench()->getApp('exface.SapConnector')->setContextVariable($this->getCsrfContextVarName(), json_encode($ctxArray), ContextManagerInterface::CONTEXT_SCOPE_SESSION);
        return $this;
    }
    
    /**
     * Removes the CSRF token and other headers from the storage completely.
     * 
     * @return HttpConnectionInterface
     */
    protected function unsetCsrfHeaders() : HttpConnectionInterface
    {
        $this->csrfHeaders = null;
        $this->getWorkbench()->getApp('exface.SapConnector')->unsetContextVariable($this->getCsrfContextVarName(), ContextManagerInterface::CONTEXT_SCOPE_SESSION);
        return $this;
    }
    
    /**
     * 
     * @param RequestInterface $request
     * @return RequestInterface
     */
    protected function addCsrfHeaders(RequestInterface $request) : RequestInterface
    {
        foreach ($this->getCsrfHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }
    
    /**
     * Requests a new CSRF token from the csrf_request_url and stores the relevant headers in
     * the workbench's context.
     * 
     * @param bool $retryOnError
     * @throws RequestException
     * @throws DataConnectionFailedError
     * @return string|NULL
     */
    protected function refreshCsrfToken(bool $retryOnError = true) : ?string
    {
        $token = null;
        $csrfRequestError = null;
        try {
            $request = new Request('GET', $this->getCsrfRequestUrl(), ['X-CSRF-Token' => 'Fetch']);
            $response = $this->getClient()->send($request);
            $token = $response->getHeader('X-CSRF-Token')[0];
            $cookie = implode(';', $response->getHeader('Set-Cookie'));
        } catch (RequestException $csrfRequestError) {
            $response = $csrfRequestError->getResponse();
            // If there was an error, but there is no response (i.e. the error occurred before
            // the response was received), just rethrow the exception.
            if (! $response) {
                throw $this->createResponseException(new Psr7DataQuery($request), null, $csrfRequestError);
            }
            $token = $response->getHeader('X-CSRF-Token')[0];
            $cookie = implode(';', $response->getHeader('Set-Cookie'));
        }
        
        if (! $token) {
            $this->unsetCsrfHeaders();
            if ($response->getStatusCode() == 401) {
                throw $this->createAuthenticationException($csrfRequestError);
            } else {
                throw $this->createResponseException(new Psr7DataQuery($request), $response, new DataConnectionFailedError($this, 'Cannot fetch CSRF token: ' . $this->getResponseErrorText($response) . '. See logs for more details!', null, $csrfRequestError));
            }
        } else {
            $this->setCsrfHeaders([
                'X-CSRF-Token' => $token,
                'Cookie' => $cookie
            ]);
        }
        
        return $token;
    }
    
    /**
     * Returns the URL to fetch the CSRF token from - relative to the base URL of the connection.
     * 
     * @return string
     */
    public function getCsrfRequestUrl() : string
    {
        $url = $this->csrfRequestUrl ?? '';
        
        if ($this->getFixedUrlParams() !== '') {
            $url = $url . (strpos($url, '?') === false ? '?' : '&') . $this->getFixedUrlParams();
        }
        
        return $url;
    }
    
    /**
     * The endpoint of the webservice to request a CSRF token (relative to `url` of the connection)
     * 
     * @uxon-property csrf_request_url
     * @uxon-type string
     * 
     * @param string $urlRelativeToBase
     * @return HttpConnectionInterface
     */
    public function setCsrfRequestUrl(string $urlRelativeToBase) : HttpConnectionInterface
    {
        $this->csrfRequestUrl = $urlRelativeToBase;
        return $this;
    }
    
    /**
     * Adds CSRF headers to the given PSR7 data query if required.
     * 
     * @see HttpConnector::addDefaultHeadersToQuery()
     */
    protected function addDefaultHeadersToQuery(Psr7DataQuery $query)
    {
        $query = parent::addDefaultHeadersToQuery($query);
        if ($this->isCsrfRequired($query->getRequest())) {
            $query->setRequest($this->addCsrfHeaders($query->getRequest()));
        }
        return $query;
    }
    
    /**
     * Once credentials are saved for this connection, make sure the CSRF headers are emptied.
     * 
     * @see AbstractDataConnector::saveCredentials()
     */
    protected function saveCredentials(UxonObject $uxon, string $credentialSetName = null, UserInterface $user = null, bool $credentialsArePrivate = null) : AbstractDataConnector
    {
        $result = parent::saveCredentials($uxon, $credentialSetName, $user, $credentialsArePrivate);
        if ($user === null || $user->getUsername() === $this->getWorkbench()->getSecurity()->getAuthenticatedToken()->getUsername()) {
            $this->unsetCsrfHeaders();
        }
        return $result;
    }
    
    /**
     * Extracts the message text from an error-response of an ADT web service
     *
     * @param ResponseInterface $response
     * @return string
     */
    abstract function getResponseErrorText(ResponseInterface $response, \Throwable $exceptionThrown = null) : string;
    
    /**
     * 
     * @return string
     */
    abstract function getFixedUrlParams() : string;
    
    /**
     * {@inheritdoc}
     * @see HttpConnector::createResponseException()
     */
    protected abstract function createResponseException(Psr7DataQuery $query, ResponseInterface $response = null, \Throwable $exceptionThrown = null);

    /**
     * {@inheritdoc}
     * @see HttpConnector::createAuthenticationException()
     */
    protected abstract function createAuthenticationException(\Throwable $exceptionThrown = null, string $message = null) : AuthenticationFailedError;
}