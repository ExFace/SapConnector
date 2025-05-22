<?php
namespace exface\SapConnector\ModelBuilders;

use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\ModelBuilders\AbstractSqlModelBuilder;
use exface\SapConnector\DataConnectors\SapAdtSqlConnector;
use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Exceptions\NotImplementedError;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\AppInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\StringEnumDataType;
use exface\Core\DataTypes\NumberEnumDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Factories\SelectorFactory;
use exface\Core\CommonLogic\Selectors\DataTypeSelector;
use exface\Core\Exceptions\DataTypes\DataTypeNotFoundError;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\DataTypes\HexadecimalNumberDataType;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Exceptions\RuntimeException;

/**
 * 
 * 
 * @author Andrej Kabachnik
 * 
 * @method SapAdtSqlConnector getDataConnection()
 *
 */
class SapAdtSqlModelBuilder extends AbstractSqlModelBuilder
{
    private $domains = [];
    
    private $dataTypeConfigs = [];
    
    private $overwriteDataTypes = false;
    
    private $overwriteDescriptions = false;
    
    private $overwriteRequired = false;
    
    protected function getAttributeDataFromTableColumns(MetaObjectInterface $meta_object, string $table_name): array
    {
        $response = $this->getDataConnection()->performRequest('POST', 'ddic?rowNumber=1&ddicEntityName=' . $table_name, '');
        $xmlCrawler = new Crawler($response->getBody()->__toString());
        $attrRows = [];
        $uidColName = $this->findUidColumnName($table_name);
        foreach ($xmlCrawler->filterXPath('//dataPreview:columns/dataPreview:metadata') as $colNode) {
            $colName = $colNode->getAttribute('dataPreview:name');
            $colDesc = $colNode->getAttribute('dataPreview:description');
            $type = $this->guessDataType($meta_object, $colNode->getAttribute('dataPreview:type'), $table_name, $colName);
            $fieldData = $this->getFieldData($table_name, $colName);
            $attrData = [
                'ALIAS' => $this->generateAlias($colName),
                'NAME' => $colDesc,
                'DATATYPE' => $this->getDataTypeId($type),
                'DATA_ADDRESS' => $colName,
                'OBJECT' => $meta_object->getId(),
                'UIDFLAG' => ($uidColName && $uidColName === $colName ? 1 : 0),
                'REQUIREDFLAG' => ($this->isRequired($table_name, $colName) ? 1 : 0),
                'SHORT_DESCRIPTION' => ($colDesc !== $fieldData['FIELD_DESC'] ? $fieldData['FIELD_DESC'] : '')
            ];
            
            if ($opts = $this->getDataTypeCustomOptions($table_name, $colName, $type)) {
                $attrData['CUSTOM_DATA_TYPE'] = (new UxonObject($opts))->toJson();
            }
                
            $attrRows[] = $attrData;
        }
        
        return $attrRows;
    }
    
    protected function generateAlias(string $openSqlName) : string
    {
        $alias = str_replace('/', '_', $openSqlName);
        if (substr($alias, 0, 1) === '_') {
            $alias = substr($alias, 1);
        }
        return $alias;
    }

    protected function findObjectTables(string $data_address_mask = null): array
    {
        throw new NotImplementedError('Generating meta objects currently not supported for SAP ADT SQL connections');
    }    
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\ModelBuilders\AbstractSqlModelBuilder::guessDataType($workbench, $sql_data_type, $length, $scale)
     */
    protected function guessDataType(MetaObjectInterface $object, string $sapType, $tableName = null, $columnName = null) : DataTypeInterface
    {
        $workbench = $object->getWorkbench();
        $sapType = strtoupper(trim($sapType));
        $sapDomain = $this->getDomainName($tableName, $columnName);
        $fieldData = $this->getFieldData($tableName, $columnName);
        
        if ($sapDomain !== '') {
            try {
                $typeSelector = SelectorFactory::createFromAlias($object->getApp(), $this->generateAlias($sapDomain), DataTypeSelector::class);
                $data_type = $workbench->model()->getModelLoader()->loadDataType($typeSelector);
            } catch (DataTypeNotFoundError $e) {
                $data_type = null;
            }
        }
        
        if ($data_type === null) {
            $enumVals = $this->getDomainEnumValues($tableName, $columnName);
            
            // If there is no meta data type for the domain yet, find the best match or create one.
            switch ($sapType) {
                case 'F':
                case 'P':
                    switch ($fieldData['DECIMALS']) {
                        case '1': $data_type =  DataTypeFactory::createFromString($workbench, 'exface.Core.Number1'); break;
                        case '2': $data_type =  DataTypeFactory::createFromString($workbench, 'exface.Core.Number2'); break;
                        default:
                            $data_type = DataTypeFactory::createFromString($workbench, NumberDataType::class);
                            if ($fieldData['DECIMALS']) {
                                $this->addDataTypeCustomOption($tableName, $columnName, 'precision', $fieldData['DECIMALS']);
                            }
                    }
                    break;
                case 'I':
                    $data_type = DataTypeFactory::createFromString($workbench, IntegerDataType::class);
                    break;
                case 'T':
                    $data_type = DataTypeFactory::createFromString($workbench, DateTimeDataType::class);
                    break;
                case 'D':
                    $data_type = DataTypeFactory::createFromString($workbench, DateDataType::class);
                    break;
                case 'X':
                case 'XSTRING':
                    $data_type = DataTypeFactory::createFromString($workbench, HexadecimalNumberDataType::class);
                    break;
                case 'N':
                    $data_type = DataTypeFactory::createFromString($workbench, 'exface.Core.NumericString');
                    if ($fieldData['LENG']) {
                        $this->addDataTypeCustomOption($tableName, $columnName, 'length_max', $fieldData['LENG']);
                    }
                    break;
                default:
                    $data_type = DataTypeFactory::createFromString($workbench, StringDataType::class);
                    if ($fieldData['LENG']) {
                        $this->addDataTypeCustomOption($tableName, $columnName, 'length_max', $fieldData['LENG']);
                    }
            }
            
            // Booleans can be represented by different data types in SAP, but will have special enum-values
            if ($fieldData['LENG'] === 1 && count($enumVals) === 2 && ($data_type instanceof StringDataType) && ($enumVals['X'] !== null || $enumVals['x'] !== null)) {
                $data_type = DataTypeFactory::createFromString($workbench, BooleanDataType::class);
                // IMPORTANT: Empty $enumVals, to prevent conversion to an enum later in the code!!!
                $enumVals = [];
                // IMPORTANT: Empty data type options because boolean has no options
                $this->emptyDataTypeCustomOptions($tableName, $columnName);
            }
            
            if (false === empty($enumVals)) {
                if ($data_type instanceof NumberDataType) {
                    $prototypeClass = NumberEnumDataType::class;
                } else {
                    $prototypeClass = StringEnumDataType::class;
                }
                $prototypeFile = ltrim(str_replace("\\", "/", $prototypeClass), "/") . '.php';
                
                $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'exface.Core.DATATYPE');
                $ds->addRow([
                    'ALIAS' => $this->generateAlias($sapDomain),
                    'NAME' => $this->getDomainDescription($tableName, $columnName),
                    'PROTOTYPE' => $prototypeFile,
                    'CONFIG_UXON' => (new UxonObject(['values' => $enumVals]))->toJson(),
                    'DEFAULT_EDITOR_UXON' => (new UxonObject(['widget_type' => 'InputSelect']))->toJson(),
                    'APP' => $object->getApp()->getUid()
                ]);
                $ds->dataCreate();
                
                $data_type = $workbench->model()->getModelLoader()->loadDataType($typeSelector);
            }
        } else {
            // If there is a domain-specific data type and it is an enum, make sure, it has all the values.
            // Add new values in any case - even if overwrite_data_types is FALSE.
            if ($data_type instanceof EnumDataTypeInterface) {
                $sapVals = $this->getDomainEnumValues($tableName, $columnName);
                $sapKeys = array_keys($sapVals);
                $modelVals = $data_type->toArray();
                $modelKeys = array_keys($modelVals);
                $missingKeys = array_diff($sapKeys, $modelKeys);
                if (false === empty($missingKeys)) {
                    foreach ($missingKeys as $key) {
                        $modelVals[$key] = $sapVals[$key];
                    }
                    $this->updateDataType($data_type, ['CONFIG_UXON' => (new UxonObject(['values' => $modelVals]))->toJson()]);
                }
            }
        }
        
        return $data_type;
    }
    
    protected function updateDataType(DataTypeInterface $type, array $data) : int
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($type->getWorkbench(), 'exface.Core.DATATYPE');
        $ds->getColumns()->addFromSystemAttributes();
        $ds->getColumns()->addMultiple([
            'APP__ALIAS',
            'ALIAS'
        ]);
        $ds->getFilters()->addConditionFromString($ds->getMetaObject()->getUidAttributeAlias(), $this->getDataTypeId($type), EXF_COMPARATOR_EQUALS);
        $ds->dataRead();
        foreach ($data as $col => $val) {
            $ds->setCellValue($col, 0, $val);
        }
        return $ds->dataUpdate();
    }
    
    protected function getDomainEnumValues(string $tableName, string $columnName) : array
    {
        $values = [];
        foreach ($this->getTableData($tableName) as $dom) {
            if (strcasecmp($dom['FIELDNAME'], $columnName) === 0 && ($dom['ENUM_VALUE'] !== '' || $dom['ENUM_TEXT'] !== '')) {
                $values[$dom['ENUM_VALUE']] = $dom['ENUM_TEXT'];
            }
        }
        return $values;
    }
    
    protected function getFieldData(string $tableName, string $columnName) : array
    {
        foreach ($this->getTableData($tableName) as $dom) {
            if (strcasecmp($dom['FIELDNAME'], $columnName) === 0) {
                $data = $dom;
                unset($data['ENUM_VALUE']);
                unset($data['ENUM_TEXT']);
                return $data;
            }
        }
        return [];
    }
    
    protected function getDomainName(string $tableName, string $columnName) : string
    {
        return $this->getFieldData($tableName, $columnName)['DOMAIN'] ?? '';
    }
    
    protected function getDomainDescription(string $tableName, string $columnName) : string
    {
        $fieldData = $this->getFieldData($tableName, $columnName);
        if ($fieldData['DOMAIN_TEXT'] !== '' && $fieldData['DOMAIN_TEXT'] !== null) {
            return $fieldData['DOMAIN_TEXT'];
        }
        return $fieldData['DOMAIN'];
    }
    
    protected function getTableData(string $tableName) : array
    {
        if ($this->domains[$tableName] === null) {
            $sql = <<<SQL
            
        SELECT
            dd03l~TABNAME,
            dd03l~FIELDNAME,
            dd03l~NOTNULL,
            dd03l~MANDATORY,
            dd03l~KEYFLAG,
            dd03l~LENG,
            dd03l~DECIMALS,
            dd03l~DOMNAME AS DOMAIN,
            dd01t~DDTEXT as DOMAIN_TEXT,
            dd04t~DDTEXT AS FIELD_DESC,
            dd04t~SCRTEXT_L,
            dd07v~DOMVALUE_L AS ENUM_VALUE,
            dd07v~DDTEXT AS ENUM_TEXT
        FROM DD03L AS dd03l
            LEFT OUTER JOIN DD01T AS dd01t ON dd03l~DOMNAME = dd01t~DOMNAME AND dd01t~ddLANGUAGE = '{$this->getSapLang($this->getModelLanguage())}'
            LEFT OUTER JOIN DD04T AS dd04t ON dd03l~ROLLNAME = dd04t~ROLLNAME AND dd04t~ddLANGUAGE = '{$this->getSapLang($this->getModelLanguage())}'
            LEFT OUTER JOIN DD07V AS dd07v ON dd03l~DOMNAME = dd07v~DOMNAME AND dd07v~DDLANGUAGE = '{$this->getSapLang($this->getModelLanguage())}'
        WHERE dd03l~TABNAME = '{$tableName}'
            AND dd03l~FIELDNAME <> '.INCLUDE'
            UP TO 10000 OFFSET 0
            
SQL;
            $q = $this->getDataConnection()->runSql($sql);
            $data = $q->getResultArray();
            
            // Using a CDS-view directly, will not yield any results here - only it's SQL name.
            // Assuming, the names differ in _CDS at the end, we can try to strip it off a read again. 
            if (empty($data) && StringDataType::endsWith($tableName, '_CDS')) {
                $data = $this->getTableData(StringDataType::substringBefore($tableName, '_CDS'));
            }
            
            if (count($data) == 10000) {
                throw new RuntimeException('Too many rows found in SAP data dictionary for table "' . $tableName . '" (> 10 000) - there seems to be an error in the model builder logic!');
            }
            
            foreach ($data as $i => $row) {
                $data[$i]['LENG'] = intval($row['LENG']);
                $data[$i]['DECIMALS'] = intval($row['DECIMALS']);
            }
            
            $this->domains[$tableName] = $data;
        }
        return $this->domains[$tableName];
    }
    
    /**
     *
     * @return string
     */
    public function getSapLang(string $langugeCode) : string
    {
        return 'D';
    }    
    
    protected function generateAttributes(MetaObjectInterface $meta_object, DataTransactionInterface $transaction = null) : DataSheetInterface
    {
        $result_data_sheet = DataSheetFactory::createFromObjectIdOrAlias($meta_object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $tableName = $meta_object->getDataAddress();
        $imported_rows = $this->getAttributeDataFromTableColumns($meta_object, $tableName);
        foreach ($imported_rows as $row) {
            $existingAttrs = $meta_object->findAttributesByDataAddress($row['DATA_ADDRESS']);
            if (empty($existingAttrs)) {
                if ($meta_object->isWritable(true) === false) {
                    $row['WRITABLEFLAG'] = false;
                    $row['EDITABLEFLAG'] = false;
                }
                $result_data_sheet->addRow($row);
            } else {
                foreach ($existingAttrs as $attr) {
                    if($attr->getAlias() === $row['ALIAS']) {
                        $updateData = [];
                        $importedType = DataTypeFactory::createFromString($meta_object->getWorkbench(), $row['DATATYPE']);
                        if (true === $this->getOverwriteDataTypes()) {
                            $customOpts = $this->getDataTypeCustomOptions($tableName, $attr->getDataAddress(), $importedType);
                            if (! $attr->getDataType()->isExactly($importedType)) {
                                $updateData['DATATYPE'] = $row['DATATYPE'];
                                $updateData['CUSTOM_DATA_TYPE'] = $row['CUSTOM_DATA_TYPE'];
                            }
                            if ($attr->getCustomDataTypeUxon()->isEmpty() && $customOpts) {
                                $updateData['CUSTOM_DATA_TYPE'] = $row['CUSTOM_DATA_TYPE'];
                            }
                        }
                        
                        if ($this->overwriteRequired && $row['REQUIREDFLAG']) {
                            $updateData['REQUIREDFLAG'] = $row['REQUIREDFLAG'];
                        }
                        
                        if ($this->overwriteDescriptions && $row['SHORT_DESCRIPTION']) {
                            $updateData['SHORT_DESCRIPTION'] = $row['SHORT_DESCRIPTION'];
                        }
                        
                        if (false === empty($updateData)) {
                            $this->updateAttribute($attr, $updateData);
                        }
                    }
                }
            }
        }
        
        if (! $result_data_sheet->isEmpty()) {
            $result_data_sheet->dataCreate(false, $transaction);
        }
        
        $result_data_sheet->setCounterForRowsInDataSource(count($imported_rows));
        
        return $result_data_sheet;
    }
    
    protected function updateAttribute(MetaAttributeInterface $attr, array $data) : int
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($attr->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $ds->getFilters()->addConditionFromString($ds->getMetaObject()->getUidAttributeAlias(), $attr->getId());
        $ds->getColumns()->addFromSystemAttributes();
        $ds->getColumns()->addMultiple([
            'UID',
            'DATATYPE',
            'CUSTOM_DATA_TYPE' => '{}'
        ]);
        $ds->dataRead();
        
        foreach ($data as $col => $val) {
            $ds->setCellValue($col, 0, $val);
        }
        return $ds->dataUpdate();
    }
    
    /**
     *
     * @return bool
     */
    protected function getOverwriteDataTypes() : bool
    {
        return $this->overwriteDataTypes;
    }
    
    /**
     * 
     * Set to TRUE to replace attribute data types with current auto-generated types.
     * 
     * This will not affect secondary attributes for a certain data address (i.e. will only
     * affect attributes, where the data address matches the alias).
     * 
     * @uxon-property overwrite_data_types
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $value
     * @return SapAdtSqlModelBuilder
     */
    public function setOverwriteDataTypes(bool $value) : SapAdtSqlModelBuilder
    {
        $this->overwriteDataTypes = $value;
        return $this;
    }
    
    protected function isRequired(string $tableName, string $columnName) : bool
    {
        $fieldData = $this->getFieldData($tableName, $columnName);
        return $fieldData['NOTNULL'] || $fieldData['MANDATORY'];
    }
    
    /**
     * Returns the primary key (UID) column name or NULL if the table has multiple keys (MANDT is ignored).
     * 
     * @param string $tableName
     * @return string|NULL
     */
    protected function findUidColumnName(string $tableName) : ?string
    {
        $found = null;
        foreach ($this->getTableData($tableName) as $row) {
            if ($row['KEYFLAG'] && $row['FIELDNAME'] !== 'MANDT') {
                if ($found !== null) {
                    return null;
                }
                $found = $row['FIELDNAME'];
            }
        }
        return $found;
    }
    
    /**
     * 
     * @param string $tableName
     * @param string $columnName
     * @param string $option
     * @param mixed $value
     * @return SapAdtSqlModelBuilder
     */
    protected function addDataTypeCustomOption(string $tableName, string $columnName, string $option, $value) : SapAdtSqlModelBuilder
    {
        $this->dataTypeConfigs[$tableName][$columnName][$option] = $value;
        return $this;
    }
    
    /**
     *
     * @param string $columnName
     * @param DataTypeInterface $dataType
     * @return array|NULL
     */
    protected function getDataTypeCustomOptions(string $tableName, string $columnName, DataTypeInterface $dataType) : ?array
    {
        return $this->dataTypeConfigs[$tableName][$columnName];
    }
    
    /**
     * 
     * @param string $tableName
     * @param string $columnName
     * @return SapAdtSqlModelBuilder
     */
    protected function emptyDataTypeCustomOptions(string $tableName, string $columnName) : SapAdtSqlModelBuilder
    {
        $this->dataTypeConfigs[$tableName][$columnName] = null;
        return $this;
    }
}