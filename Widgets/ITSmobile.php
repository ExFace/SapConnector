<?php
namespace exface\SapConnector\Widgets;

use exface\Core\Widgets\AbstractWidget;
use exface\Core\Interfaces\Facades\FacadeInterface;
use exface\Core\Interfaces\Widgets\CustomWidgetInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Interfaces\Widgets\iTriggerAction;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Factories\DataSourceFactory;
use exface\UI5Facade\Facades\Elements\UI5AbstractElement;
use exface\UI5Facade\Facades\UI5Facade;
use exface\SapConnector\Facades\Elements\UI5ITSmobile;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Widgets\WidgetConfigurationError;
use exface\Core\Interfaces\Widgets\iHaveContextualHelp;
use exface\Core\Widgets\Traits\iHaveContextualHelpTrait;
use exface\Core\Interfaces\Widgets\iFillEntireContainer;

/**
 * A widget to integrate an ITSmobile app.
 * 
 * **NOTE:** This widget currenlty only works with the UI5 facade.
 * 
 * This widget can be used to integrate an ITSmobile app into a facade. It is basically a 
 * remote control for the ITSmobile app and a visual transformation layer to make the app
 * look and feel as an integral part of the facade. 
 * 
 * Under the hood, the `ITSmobileProxyFacade` is used as middleware between the web browser
 * and SAP. All web requests are routed through this proxy, which also takes care of
 * authentication, CORS, etc.
 * 
 * To use this widget, you will need a separate data source for every ITSmobile app with: 
 * 
 * - the `SapITSmobileConnector`, where the `url` points to the ITSmobile web service,
 * - the `DummyQueryBuilder`
 * 
 * You will also need a dummy meta object because every widget requires a meta object. In fact, you 
 * can use any existing object or create a new one for your ITSmobile data source. An empty object
 * without attributes and marked as neither readable nor writable will do fine. The data source cannot 
 * be used for reading or writig data directly. The entire logic remains in SAP - it's just the 
 * appearance, that is being enhanced by this widget.
 * 
 * ## Examples
 * 
 * Place this code in a page's root or in a container like a `Dialog` or `SplitVertical`. 
 * 
 * ```
 * {
 *  "widget_type": "exface.SapConnector.ITSmobile",
 *  "data_source_alias": "POWERUI_DEMO_ITSMOBILE",
 *  "object_alias": "powerui.DemoMES.basis_objekt",
 *  "f_key_back": "F7",
 *  "f_keys": {
 *    "F1": "Help",
 *    "F7": "Go Back"
 *  }
 * }
 * 
 * ```
 * 
 * Note the properties `f_key_back` and `f_keys`: since ITSmobile apps are often controlled by hardware 
 * keys, this widget provides softkey alternatives. These softkeys can be labeled using the `f_keys`
 * configuration. There is also a dedicated back-softkey, so `f_key_back` let's you define, which
 * F-key it should "press".
 * 
 * If a screen contains buttons labeled with `Fxx` (e.g. "F2 List"), the corresponding F-softkey will
 * automatically get the label of the button on that screen. It will change back to it's initial
 * value when leaving the screen.
 * 
 * 
 * @author Ralf Mulansky
 *
 */
class ITSmobile extends AbstractWidget implements CustomWidgetInterface, iHaveContextualHelp, iFillEntireContainer
{
    use iHaveContextualHelpTrait ;

    private $dataSourceAlias = null;
    
    private $dataSource = null;
    
    private $fKeys = [];
    
    private $fKeyBack = null;
    
    private $orphanContainer = null;
    
    /**
     * 
     * {@inheritdoc}
     * @return \exface\Core\Interfaces\Widgets\CustomWidgetInterface::createFacadeElement()
     */
    public function createFacadeElement(FacadeInterface $facade, $baseElement = null)
    {
        if ($facade instanceof UI5Facade) {
            return $this->createUI5Page($baseElement);
        }
        return $baseElement;
    }
    
    /**
     * The alias of the data source to use.
     * 
     * @uxon-property data_source_alias
     * @uxon-type metamodel:data_source
     * @uxon-required true 
     * 
     * @param string $selectorString
     * @return ITSmobile
     */
    public function setDataSourceAlias(string $selectorString) : ITSmobile
    {
        $this->dataSourceAlias = $selectorString;
        $this->dataSource = null;
        
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    public function getDataSourceAlias() : string
    {
        return $this->dataSourceAlias;
    }
    
    /**
     * 
     * @return DataSourceInterface
     */
    protected function getDataSource() : DataSourceInterface
    {
        if ($this->dataSource === null) {
            $this->dataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $this->getDataSourceAlias());
        }
        return $this->dataSource;
    }
    
    /**
     * Returns the UI5 facade element for an ITSmobile remote control
     * 
     * @return UI5ITSmobile
     */
    protected function createUI5Page(UI5AbstractElement $ui5Element) : UI5ITSmobile
    {
        return new UI5ITSmobile($ui5Element->getWidget(), $ui5Element->getFacade());
    }
    
    /**
     * Set F-Key captions/function to be shown in ITSmobile view
     * 
     * @uxon-property f_keys
     * @uxon-type object
     * @uxon-template {"F1": "", "F2": "", "F3": "", "F4": "", "F5": "", "F6": "", "F7": "", "F8": "", "F9": "", "F10": "", "F11": "", "F12": ""}
     * 
     * @param UxonObject $uxon
     * @return ITSmobile
     */
    public function setFKeys(UxonObject $uxon) : ITSmobile
    {
        $this->fKeys = $uxon->toArray();
        return $this;
    }
    
    /**
     * Return the F-Key captions/functions array
     * 
     * @return array
     */
    public function getFKeys() : array
    {       
        $fKeys_default = [
            "F1" => '',
            "F2" => '',
            "F3" => '',
            "F4" => '',
            "F5" => '',
            "F6" => '',
            "F7" => '',
            "F8" => '',
            "F9" => '',
            "F10" => '',
            "F11" => '',
            "F12" => ''
        ];
        $new_fKeys = array_merge($fKeys_default, $this->fKeys);
        return $new_fKeys;
    }
    
    /**
     * Set the F-Key that has the function "Back" or similar in the specific ITSmobile app
     * 
     * @uxon-property f_key_back
     * @uxon-type string
     * @uxon-default F7
     * 
     * @param string $fKeyBack
     * @return ITSmobile
     */
    public function setFKeyBack(string $fKeyBack) : ITSmobile
    {
        $this->fKeyBack = $fKeyBack;
        return $this;
    }
    
    /**
     * Returns the F-Key that has the function "Back"
     * 
     * @return string
     */
    public function getFKeyBack() : string
    {
        if ($this->fKeyBack === null) {
            $this->fKeyBack = "F7";
        }
        return $this->fKeyBack;
    }
    
    /**
     * 
     * @param string $keyWithF
     * @throws WidgetConfigurationError
     * @return int
     */
    public function getFKeyNumber(string $keyWithF) : int
    {
        if (strcasecmp(substr($keyWithF, 0, 1), 'f') !== 0) {
            throw new WidgetConfigurationError($this, 'Invalid F-Key "' . $keyWithF . '": F-Keys must start with an "F"!');
        }
        return substr($keyWithF, 1);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\AbstractWidget::getChildren()
     */
    public function getChildren() : \Iterator
    {
        foreach (parent::getChildren() as $child) {
            yield $child;
        }
        
        // Add the help button, so pages will be able to find it when dealing with the ShowHelpDialog action.
        // IMPORTANT: Add the help button to the children only if it is not hidden. This is needed to hide the button in
        // help widgets themselves, because otherwise they would produce their own help widgets, with - in turn - even
        // more help widgets, resulting in an infinite loop.
        if (! $this->getHideHelpButton()) {
            yield $this->getHelpButton();
        }
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iFillEntireContainer::getAlternativeContainerForOrphanedSiblings()
     */
    public function getAlternativeContainerForOrphanedSiblings() : ?iContainOtherWidgets
    {
        if ($this->getParent() && $this->getParent() instanceof iContainOtherWidgets) {
            return $this->getParent();
        }
        
        if ($this->orphanContainer === null) {
            $this->orphanContainer = WidgetFactory::create($this->getPage(), 'Container', $this);
        }
        return $this->orphanContainer;
    }
}