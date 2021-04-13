<?php
namespace exface\SapConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Actions\iCallService;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use GuzzleHttp\Psr7\Request;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Interfaces\Model\ConditionGroupInterface;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;

/**
 * Calls a SOAP service operation.
 * 
 * To use this action, you will need to create a meta object with attributes for all input and output
 * parameters of the RFC module. Then you can add on or more actions to this object in it's model using
 * `exface.SapConnector.CallRfcViaSoap` as prototype - an action for every set of parameters you
 * want to use to call the RFC with. 
 * 
 * Here is an example function module definition:
 * 
 * ```
 *   IMPORTING
 *      VALUE(IV_LENUM) TYPE  CHAR20
 *      VALUE(IV_PLATZ_ZIEL) TYPE  CHAR20
 *      VALUE(IV_BUCHEN) TYPE  CHAR1
 *   EXPORTING
 *      VALUE(EV_MATNR) TYPE  CHAR20
 *      VALUE(EV_MENGE) TYPE  QUA_VALCOM
 *      VALUE(EV_MEINS) TYPE  MEINS
 *      VALUE(EV_MAKTX) TYPE  MAKTX
 *      VALUE(EV_LENUM) TYPE  CHAR20
 *      VALUE(EV_PLATZ_ZIEL) TYPE  CHAR20
 *      VALUE(EV_MSGTY) TYPE  SYMSGTY
 *      VALUE(EV_MSGTXT) TYPE  BAPI_MSG
 * 
 * ```
 *
 * While this is the definition of the function module in SAP, the web service is defined by the WSDL generated
 * automatically, when publishing the function module. You can see the URL of the WSDL in the properties
 * of the generated service. Note, that the WSDL uses camelCase syntax for the parameters.
 * 
 * Thus, our meta object should have an attribute for every parameter needed with the camelCased parameter name 
 * as data address: e.g. `IvLenum` for `IV_LENUM`. The importing parameters should be marked editable and writable, 
 * but not readable, and the exporting parameter the other way around.
 * 
 * Let's assume, the importing parameters `ÌV_PLATZ_ZIEL` and `IV_BUCHEN` are optional, but `IV_BUCHEN` can
 * only be used with the other two parameters set. This means, we have three possible combinations of parameters
 * to call the RFC: `IV_LENUM` only, `IV_LENUM` + `IV_PLATZ_ZIEL` and all three together. In general, it is a good
 * idea to create three actions here - one for each parameter set. This allows us to describe in the metamodel, 
 * what exactly each combination does, which makes corresponding buttons and contextual help much better
 * understandable for users.
 * 
 * The first action would have the following configuration:
 * 
 * ```
 * {
 *  "service_name": "ServiceNameInWSDL",
 *  "soap_operation": "NameOfOperationInWSDL",
 *  "result_message_parameter_name": "EvMsgtxt",
 *  "parameters": [
 *    {
 *      "name": "IvLenum"
 *    },
 *    {
 *      "name": "IvPlatzZiel",
 *      "empty": true
 *    },
 *    {
 *      "name": "IvBuchen",
 *      "empty": true
 *    }
 *  ]
 * }
 * 
 * ```
 * 
 * The second one would not have the `empty` property for the second parameter and the third one would
 * not have `empty` parameters at all.
 * 
 * Note, that the exporting parameter `EV_MSGTXT` contains a human-readable response text in our example.
 * We can make our action use it as it's result message by setting `result_message_parameter_name`.
 * 
 * In this example, we did not specify the data types of the parameters in the model. This means, they 
 * will all be treated as generic strings. If you wish to have type checks _before_ the SOAP request is sent, 
 * configure appropriate data types for every attribute and/or every parameter. Data types for attributes will 
 * give you type-specific input widgets and type-validation in the UI, while data types for parameters (i.e. 
 * `data_type` in the above `parameters` definition) will give you type validation right before the SOAP 
 * request is made - regardles of where the input data came from.
 * 
 * @author Ralf Mulansky
 *
 */
class CallRfcViaSoap extends AbstractAction implements iCallService 
{
    private $serviceName = null;
    
    private $httpMethod = 'POST';
    
    private $operation = null;
    
    private $parameters = [];
    
    private $result_message_parameter_name = null;
    
    private $error_message = null;
    
    protected function init()
    {
        parent::init();
        // TODO name, icon
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        $input = $this->getInputDataSheet($task);
        
        $body = $this->buildRequestBodyEnvelope($this->getSoapOperation(), $this->buildBodyParams($input));
        $request = new Request($this->getHttpMethod(), $this->buildUrl($input), $this->buildRequestHeader(), $body);
        $query = new Psr7DataQuery($request);
        $response = $this->getDataConnection()->query($query)->getResponse();
        try {
            $resultData = $this->parseResponse($response);
        } catch (\Throwable $e) {
            throw new DataQueryFailedError($query, $e->getMessage(), null, $e);
        }
        
        return ResultFactory::createDataResult($task, $resultData, $this->getResultMessageText() ?? $this->buildResultMessage($response));
    }
    
    protected function buildRequestBodyEnvelope(string $operation, string $params) : string
    {
        return <<<XML
        
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:sap-com:document:sap:soap:functions:mc-style">
   <soapenv:Header/>
   <soapenv:Body>
      <urn:{$operation}>
            {$params}
      </urn:{$operation}>
   </soapenv:Body>
</soapenv:Envelope>

XML;
    }
            
    protected function buildBodyParams(DataSheetInterface $data) : string
    {
        $params = '';
        foreach ($this->getParameters() as $param) {
            $val = $this->getParamValue($data, $param);
            $params .= '<' . $param->getName() . '>' . $this->prepareParamValue($param, $val) . '</' . $param->getName() . '>';
        }
        return $params;
    }
    
    protected function getParamValue(DataSheetInterface $data, ServiceParameterInterface $param) : ?string
    {
        $val = null;
        if ($col = $data->getColumns()->get($param->getName())) {
            $val = $col->getCellValue(0);
        } else {
            $val = $this->getParamValueFromFilters($data->getFilters(), $param);
        }
        return $val;
    }
    
    protected function getParamValueFromFilters(ConditionGroupInterface $filters, ServiceParameterInterface $param) : ?string
    {
        $val = null;
        if ($filters->getOperator() === EXF_LOGICAL_AND) {
            foreach ($filters->getConditions() as $condition) {
                if (strcasecmp($condition->getExpression()->__toString(), $param->getName()) === 0 && false === $condition->isEmpty()) {
                    switch ($condition->getComparator()) {
                        case ComparatorDataType::IS:
                        case ComparatorDataType::EQUALS:
                            return $condition->getValue();
                    }
                }
            }
            foreach ($filters->getNestedGroups() as $grp) {
                if ($val = $this->getParamValueFromFilters($grp, $param)) {
                    return $val;
                }
            }
        }
        return $val;
    }
    
    protected function buildRequestHeader() : array
    {
        return array(
            "Content-Type" => "text/xml"
        );
    }
    
    protected function buildUrl(DataSheetInterface $data) : string
    {
        $url = $this->getServiceName() . '?';
        return $url;
    }
    
    protected function buildResultMessage(ResponseInterface $response) : string
    {
        $crawler = new Crawler($response->getBody()->__toString());
        $resultmsg = $crawler->filter($this->getResultMessageParameterName())->text();
        if ($resultmsg == null) { 
            return $this->getWorkbench()->getApp('exface.SapConnector')->getTranslator()->translate('SOAPSERVICECALLSUCCESSFUL');
        }
        elseif ($this->error_message !== null) {
            //$resultmsg .= $this->error_message;
            return $this->getWorkbench()->getApp('exface.SapConnector')->getTranslator()->translate($this->error_message);
        }
        else {
            return $this->getWorkbench()->getApp('exface.SapConnector')->getTranslator()->translate($resultmsg);
        }
    }
          
    protected function prepareParamValue(ServiceParameterInterface $parameter, $val) : ?string
    {
        if ($parameter->hasDefaultValue() === true && $val === null) {
            $val = $parameter->getDefaultValue();
        }
        if ($val == null && $parameter->isRequired() === true) {
            $value = 'Pflichtparameter ' . $parameter->getName() .  ' nicht angegeben';
            $this->setErrorMessage($value);
        }
        if ($parameter->isEmpty() === true){
            $val = '';
        }
        //$val = $parameter->getDataType()->parse($val);
        return  $val;
    }
 
    protected function parseResponse(ResponseInterface $response) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObject($this->getResultObject());
        $ds->setAutoCount(false);
        
        $crawler = new Crawler($response->getBody()->__toString());
        $params = $crawler->filter($this->getMetaObject()->getAlias())->children();
        foreach ($params as $paramCrawler) {
            $attrName = $paramCrawler->nodeName;
            $attrVal = $paramCrawler->textContent;
            
            foreach ($this->getMetaObject()->getAttributes()->getReadable() as $attr) {
                if (strcasecmp($attrName, $attr->getDataAddress()) === 0) {
                    $ds->setCellValue($attr->getDataAddress(), 0, $attrVal);
                }
            }  
        }
        if ($response->getStatusCode() == 200) {
            $ds->setFresh(true);
        }
        $ds->setCounterForRowsInDataSource($ds->countRows());
        return $ds;
    }
    
    protected function getResultObject() : MetaObjectInterface
    {
        if ($this->hasResultObjectRestriction()) {
            return $this->getResultObjectExpected();
        }
        return $this->getMetaObject();
    }
    
    protected function getDataConnection() : HttpConnectionInterface
    {
        return $this->getMetaObject()->getDataConnection();
    }
    
    /**
     *
     * @return string
     */
    public function getResultMessageParameterName() : string
    {
        return $this->result_message_parameter_name;
    }
    
    /**
     * The XML node for the resultmessage .
     *
     * @uxon-property result_message_parameter_name
     * @uxon-type string
     *
     * @param string $value
     * @return CallRfcViaSoap
     */
    public function setResultMessageParameterName(string $value) : CallRfcViaSoap
    {
        $this->result_message_parameter_name = $value;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    public function getServiceName() : string
    {
        return $this->serviceName;
    }
    
    /**
     * The name for the operation.
     * 
     * @uxon-property service_name
     * @uxon-type string
     * 
     * @param string $value
     * @return CallRfcViaSoap
     */
    public function setServiceName(string $value) : CallRfcViaSoap
    {
        $this->serviceName = $value;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    public function getHttpMethod() : string
    {
        return $this->httpMethod;
    }
    
    /**
     * 
     * @param string $value
     * @return CallRfcViaSoap
     */
    public function setHttpMethod(string $value) : CallRfcViaSoap
    {
        $this->httpMethod = $value;
        return $this;
    }
    
    /**
     *
     * @return UxonObject
     */
    public function getParameters() : array
    {
        return $this->parameters;
    }
    
    /**
     * Defines parameters supported by the service.
     * 
     * @uxon-property parameters
     * @uxon-type \exface\Core\CommonLogic\Actions\ServiceParameter[]
     * @uxon-template [{"name": ""}]
     * 
     * @param UxonObject $value
     * @return CallRfcViaSoap
     */
    public function setParameters(UxonObject $uxon) : CallRfcViaSoap
    {
        foreach ($uxon as $paramUxon) {
            $this->parameters[] = new ServiceParameter($this, $paramUxon);
        }
        return $this;
    }
    
    public function getParameter(string $name) : ServiceParameterInterface
    {
        foreach ($this->getParameters() as $arg) {
            if ($arg->getName() === $name) {
                return $arg;
            }
        }
    }
    
    /**
     *
     * @return string
     */
    public function getSoapOperation() : string
    {
        return $this->operation;
    }
    
    /**
     * SOAP operation name
     * 
     * @uxon-property soap_operation
     * @uxon-type string
     * 
     * @param string $value
     * @return CallRfcViaSoap
     */
    public function setSoapOperation(string $value) : CallRfcViaSoap
    {
        $this->operation = $value;
        return $this;
    }
    
    protected function setErrorMessage(string $value) : CallRfcViaSoap
    {
        $this->error_message = $value;
        return $this;
    }
    
    protected function getErrorMessage()
    {
        return $this->error_message;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getEffects()
     */
    public function getEffects() : array
    {
        return array_merge(parent::getEffects(), $this->getEffectsFromModel());
    }
}