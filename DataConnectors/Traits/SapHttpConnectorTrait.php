<?php
namespace exface\SapConnector\DataConnectors\Traits;

use Symfony\Component\DomCrawler\Crawler;
use Psr\Http\Message\ResponseInterface;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use exface\UrlDataConnector\Exceptions\HttpConnectorRequestError;
use exface\UrlDataConnector\Psr7DataQuery;

/**
 * This trait adds support sap-client URL params and other SAP specifics to an HttpConnector.
 * 
 * @author Andrej Kabachnik
 *
 */
trait SapHttpConnectorTrait
{    
    private $sapClient = null;
    
    private $lastErrorHadMeaningfulTitle = false;
    
    private $htmlErrorTextSelectors = [
        'h1', // generic NetWeaver errors
        '.errorTextHeader' // ITSmobile and older services errors
    ];
    
    /**
     *
     * @return string|NULL
     */
    public function getSapClient() : ?string
    {
        return $this->sapClient;
    }
    
    /**
     * SAP client (MANDT) to connect to.
     *
     * @uxon-property sap_client
     * @uxon-type string
     *
     * @param string $client
     * @return self
     */
    public function setSapClient(string $client) : self
    {
        $this->sapClient = $client;
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\HttpConnector::getFixedUrlParams()
     */
    public function getFixedUrlParams() : string
    {
        $paramString = parent::getFixedUrlParams();
        if ($this->getSapClient() !== null && stripos($paramString, 'sap-client') === false) {
            $paramString .= '&sap-client=' . $this->getSapClient();
        }
        return $paramString;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see HttpConnector::getResponseErrorCode()
     */
    protected function getResponseErrorCode(ResponseInterface $response, \Throwable $exceptionThrown = null) : ?string
    {
        return $this->getErrorCode() ?? 'SAP-ERROR';
    }
    
    /**
     *
     * {@inheritdoc}
     * @see HttpConnector::getResponseErrorText()
     */
    protected function getResponseErrorText(ResponseInterface $response, \Throwable $exceptionThrown = null) : string
    {
        $message = null;
        $this->lastErrorHadMeaningfulTitle = false;
        $text = trim($response->getBody()->__toString());
        try {
            switch (true) {
                case stripos($response->getHeader('Content-Type')[0], 'json') !== false:
                    if ($message = $this->getResponseErrorTextFromJson(trim($text))) {
                        $this->lastErrorHadMeaningfulTitle = true;
                        break;
                    }
                case stripos($response->getHeader('Content-Type')[0], 'html') !== false || mb_strtolower(substr($text, 0, 6)) === '<html>':
                    // If the response is HTML, get the <h1> tag
                    $crawler = new Crawler($text);
                    $message = $this->getResponseErrorTextFromHtml($crawler);
                    break;
                case stripos($response->getHeader('Content-Type')[0], 'xml') !== false || mb_strtolower(substr($text, 0, 5)) === '<?xml':
                    // If the response is XML, look for the <message> tag
                    $crawler = new Crawler($text);
                    $message = $crawler->filterXPath('//message')->text();
                    break;
            }
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            // Ignore errors
        }
        
        
        // If no message could be found, just output the response body
        // Btw. strip_tags() did not work well as fallback, because it would also output
        // embedded CSS.
        return $message ?? $text;
    }
    
    protected function getResponseErrorTextFromHtml(Crawler $crawler, string $cssSelector = null) : ?string
    {
        $selectors = $cssSelector !== null ? [$cssSelector] : $this->htmlErrorTextSelectors;
        foreach ($selectors as $selector) {
            $nodes = $crawler->filter($selector);
            if ($nodes->count() > 0 && $message = $nodes->text()) {
                return $message;
            }
        }
        return $message;
    }
    
    protected function getResponseErrorTextFromJson(string $jsonString) : ?string
    {
        $json = json_decode($jsonString, true);
        if ($errObj = $json['error']) {
            if ($message = $errObj['message']['value']) {
                return $message;
            }
        }
        return null;
    }
    
    /**
     * {@inheritdoc}
     * @see HttpConnector::createResponseException()
     */
    protected function createResponseException(Psr7DataQuery $query, ResponseInterface $response = null, \Throwable $exceptionThrown = null)
    {
        $exception = parent::createResponseException($query, $response, $exceptionThrown);
        if ($this->lastErrorHadMeaningfulTitle && $exception instanceof HttpConnectorRequestError) {
            $exception->setUseRemoteMessageAsTitle(true);
        }
        return $exception;
    }
}