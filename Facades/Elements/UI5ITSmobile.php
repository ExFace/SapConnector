<?php
namespace exface\SapConnector\Facades\Elements;

use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSourceFactory;
use exface\UI5Facade\Facades\Elements\UI5AbstractElement;
use exface\SapConnector\Facades\ITSmobileProxyFacade;
use exface\Core\Factories\FacadeFactory;
use exface\UI5Facade\Facades\Elements\Traits\UI5HelpButtonTrait;

/**
 * 
 * @author rml
 *
 * @method exface\SapConnector\Widgets\ITSmobile\ITSmobile getWidget()
 */
class UI5ITSmobile extends UI5AbstractElement
{
    use UI5HelpButtonTrait;
    
    private $proxyFacade = null;
    
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {
        $controller = $this->getController();
        $controller->addMethod('getITSmobileContent', $this, 'url, body', $this->buildJsLoaderControllerMethod('url', 'body'));
        
        $dataSourceAlias = $this->getWidget()->getDataSourceAlias();
        $dataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $dataSourceAlias);
        
        $serviceUrl = $dataSource->getConnection()->getUrl();
        $baseUrl = $dataSource->getConnection()->getUrlServerRoot();
        $proxyUrl = $this->getProxyFacade()->buildUrlToFacade() . '/' .  $dataSourceAlias;
        $serviceUrlWithProxy = $proxyUrl . '/?url=' . urlencode($serviceUrl);
        
        $this->registerITSmobileThemeIncludes($baseUrl, $proxyUrl);
        
        $showNavButton = $this->getView()->isWebAppRoot() ? 'false' : 'true';
        
        return <<<JS

new sap.m.Page({
	title: "{$this->getCaption()}",
	showNavButton: {$showNavButton},
	navButtonPress: [oController.navBack, oController],
    headerContent: [
        {$this->buildJsHelpButtonConstructor($oControllerJs)}
    ],
	content: [
		new sap.ui.core.HTML("{$this->getId()}", {
            content: "<div class=\"its-mobile-wrapper\" style=\"height: 100%; overflow: hidden; position: relative;\"></div>",
            afterRendering: function() { 
                // Render F-keys menu
                if (! sap.ui.getCore().byId('f-keys-menu-{$this->getId()}')) {
                    oPopover = new sap.m.Popover("f-keys-menu-{$this->getId()}", {
    					title: "{$this->translate('WIDGET.ITSMOBILE.F_KEYS')}",
    					placement: "Top",
    					content: [
    						new sap.m.List({
    							items: [
    								{$this->buildJsFKeyListItems()}
    							]
    						})
    					]
    				})
    				//.setModel(oButton.getModel())
    				//.setModel(oButton.getModel('i18n'), 'i18n');
                }

                // load initial ITSmobile page
                $oControllerJs.{$controller->buildJsMethodName('getITSmobileContent', $this)}("$serviceUrlWithProxy");
			}
        })
	],
	footer: [
        new sap.m.OverflowToolbar({
			content: [
				
				//F-Tasten Dropdown
				new sap.m.Button({
                    icon: "sap-icon://slim-arrow-up",
                    text: "{$this->translate('WIDGET.ITSMOBILE.F_KEYS')}",
                    layoutData: new sap.m.OverflowToolbarLayoutData({priority: "NeverOverflow"}),
                    press: function(oEvent){
						var oButton = oEvent.getSource();
						var oPopover = sap.ui.getCore().byId('f-keys-menu-{$this->getId()}');						
						jQuery.sap.delayedCall(0, this, function () {
							oPopover.openBy(oButton);
						});
					}
                }),
				
				new sap.m.ToolbarSpacer(),
				
				{$this->buildJsFKeyBack()}
				
				//Weiter-Button
				new sap.m.Button({							
					icon: "sap-icon://navigation-right-arrow",
					text: "{$this->translate('WIDGET.ITSMOBILE.F_KEY_CONTINUE')}",
					type: "Emphasized",
					press: function(oEvent){
						setOkCodeEnter();
					}
				}),
			]
		})
    ]
}).addStyleClass('exf-itsmobile sapUiContentPadding')

JS;
    }
        
    protected function buildJsLoaderControllerMethod(string $urlJs = 'url', string $bodyJs = 'body') : string
    {
        return <<<JS

            var oController = this;
			//UI being busy prevents user from multiple form submits when view building takes longer and user does press buttons again
			if (sap.ui.getCore().byId("{$this->getId()}").getBusy()) {
				return;
			}
			//set UI busy till building view content is finished
			sap.ui.getCore().byId("{$this->getId()}").setBusy(true).setBusyIndicatorDelay(0);
			
			//Ajax Request over Proxy with form content from 'form'
			$.ajax({
				type: 'POST',
				url: $urlJs,
				data: $bodyJs
			//when Ajax request got response transform response style to new style	
			})
            .fail(function(jqXHR, textStatus, errorThrown) {
                oController.getOwnerComponent().showAjaxErrorDialog(jqXHR)
            })
            .done(function(data, textStatus, jqXHR) { 
				var jqhtml = $(data);
				jqhtml.find('.MobileLabel').addClass('sapMLabel');				
				
				//Make styling responsible
				//Works by getting old margins and widths and transform them to percentages
				jqhtml.find('.MobileRow').each(function(){
					var jqrow = $(this);
					//var childrenCount = jqrow.children().length;
					var totalWidth = 0;
					jqrow.children().each(function(){
						var jqcell = $(this);
						var margin = parseFloat(jqcell.css('margin-left'));
						if (!isNaN(margin)){
							totalWidth += margin;
						}
						var cellWidth = parseFloat(jqcell.width());
						if (!isNaN(cellWidth)){
							totalWidth += cellWidth;
						};
					});
					var sumWidth = 0;
					jqrow.children().each(function(){
						var jqcell = $(this);
						var oldMargin = parseFloat(jqcell.css('margin-left'));
						if (!isNaN(oldMargin)){
							var newMargin = (oldMargin/totalWidth*100).toFixed(2);
							if ((parseFloat(sumWidth) + parseFloat(newMargin)) > 100){
								newMargin = (100-sumWidth).toFixed(2);
							}
							sumWidth = (parseFloat(sumWidth) + parseFloat(newMargin)).toFixed(2);
							jqcell.css('margin-left', newMargin+'%');
						}
						var oldWidth = parseFloat(jqcell.width());
						if (!isNaN(oldWidth) && !(oldWidth==0)){
							var newWidth = (oldWidth/totalWidth*100).toFixed(2);
							if ((parseFloat(sumWidth) + parseFloat(newWidth)) > 100){
								newWidth= (100-sumWidth).toFixed(2);
							}
							sumWidth = (parseFloat(sumWidth) + parseFloat(newWidth)).toFixed(2);
							jqcell.css('width', newWidth+'%');
						};
					});
				});			
				
				// Transform Button Elements to new style
				jqhtml.find("input[type='button']").each(function(){
					var button = this;
					var jqbutton = $(this);
					var btnProps = '';
					//copy attributes of old buttons to new buttons
					$.each(button.attributes, function() {
						// this.attributes is not a plain object, but an array
						// of attribute nodes, which contain both the name and value						
						if(this.specified) {
						  btnProps += ' ' + this.name + '="' + this.value + '"';
						}
					  });
					$(button).replaceWith('<button class="sapMBtn sapMBtnBase" "'+btnProps+'"><span class="sapMBtnDefault sapMBtnHoverable sapMBtnInner sapMBtnText sapMFocusable"><span class="sapMBtnContent"><bdi>'+jqbutton.val()+'</bdi></span></span></button>');					

                    // Update F-key menu with button descriptions
                    var text = jqbutton.val();
                    var fMatch = text.match(/f\d{1,2}/i);
                    if (fMatch) {
                        var fKey = fMatch[0];
                        var fKeyDesc = text.replace(fKey, '').trim();
                        var oFPopover = sap.ui.getCore().byId('f-keys-menu-{$this->getId()}');
                        var aFListItems = oFPopover.getContent()[0].getItems();
                        for (var i in aFListItems) {
                            var oItem = aFListItems[i];
                            if (oItem.getTitle() === fKey || oItem.getTitle().startsWith(fKey + ' ')) {
                                oItem.setTitle(fKey + ' - ' + fKeyDesc);
                                break;
                            }
                        }
                    }
				});
				
				// Transform Input Elements to new style
				jqhtml.find("input[type!='hidden']").each(function(){					
					var jqinput = $(this);
					var jqparent = jqinput.parent();
                    var sSapMInputClasses = '';
                    var ssapMInputBaseContentWrapperClasses = '';
                    if (jqinput.attr('readonly')) {
                        sSapMInputClasses += ' sapMInputBaseReadonly';
                        ssapMInputBaseContentWrapperClasses += ' sapMInputBaseReadonlyWrapper';
                    }
					$(this).replaceWith('<div class="sapMInput sapMInputBase sapMInputBaseHeightMargin' + sSapMInputClasses + '"><div class="sapMInputBaseContentWrapper' + ssapMInputBaseContentWrapperClasses + '"></div></div>');
					jqinput.addClass('sapMInputBaseInner').appendTo(jqparent.find('.sapMInputBaseContentWrapper:last'));	
					jqinput.parents('.sapMInput').css('width', jqinput.css('width'));
					jqinput.css('width', '100%');
				});
				
				jqhtml.find('.MobileCuaArea').hide();
				$('.its-mobile-wrapper').html(jqhtml);		

                // Give focus to first visible input element
                $(".its-mobile-wrapper input[type!='hidden']:visible:not([readonly])").first().focus();		
					
				
				//unset busy, building view content finished
				sap.ui.getCore().byId("{$this->getId()}").setBusy(false).setBusyIndicatorDelay(0);		

				//prevent default submit from form, instead call our function again
				var form = document.forms["mobileform"];
				var formHandler = form.onsubmit;
				$(form).off('submit').submit(function(event){
					formHandler(event);
					var url = form.action;
					event.preventDefault();				
					var jqdata = $(form).serialize();
					oController.{$this->getController()->buildJsMethodName('getITSmobileContent', $this)}(url, jqdata);					
					return false;
				});
			});

JS;
    }
        
    /**
     * Theme 99 specific CSS and JavaScript file including
     * 
     * @param string $baseUrl
     * @param string $proxyUrl
     * @return UI5ITSmobile
     */
    protected function registerITSmobileThemeIncludes(string $baseUrl, string $proxyUrl) : UI5ITSmobile
    {
        $mobileJsPath = urlencode(gzdeflate( $baseUrl . '/sap/public/bc/its/mimes/itsmobile/99/scripts/all/mobile.js'));
        $this->getController()->addExternalModule('ITSmobileJS', $proxyUrl . '/url/'. $mobileJsPath);
        
        $this->getController()->addExternalCss($this->getWorkbench()->getUrl() . 'vendor/exface/SapConnector/Facades/Css/theme99.css', 'itsmobile_theme99');
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::translate()
     */
    public function translate($message_id, array $placeholders = array(), $number_for_plurification = null)
    {
        $message_id = trim($message_id);
        return $this->getWorkbench()->getApp('exface.SapConnector')->getTranslator()->translate($message_id, $placeholders, $number_for_plurification);
    }
    
    /**
     * Builds the F-Key list
     * 
     * @return string
     */
    protected function buildJsFKeyListItems() : string
    {
        $js = '';
        foreach ($this->getWidget()->getFKeys() as $key => $desc) {
            $keyNr = $this->getWidget()->getFKeyNumber($key);
            if ($desc == ''){
                $js .= <<<JS
                
                                            new sap.m.StandardListItem({
												title: "{$key}",
												type: "Active",
												press: function(oEvent){
													setFKey('{$keyNr}');
												}
											}),
											
JS;
                
            } else {
                $js .= <<<JS
                
                                            new sap.m.StandardListItem({
												title: "{$key} - {$desc}",
												type: "Active",
												press: function(oEvent){
													setFKey('{$keyNr}');
												}
											}),
											
JS;
            }
            
        }
        return $js;
    }
    
    /**
     * Builds the "Back" Button
     * 
     * @return string
     */
    protected function buildJsFKeyBack() : string
    {
        $js = '';
        $key = $this->getWidget()->getFKeyBack();
        $keyNr = $this->getWidget()->getFKeyNumber($key);
        $js .= <<<JS
                            
                new sap.m.Button({
					icon: "sap-icon://navigation-left-arrow",
					text: "{$this->translate('WIDGET.ITSMOBILE.F_KEY_BACK')}",
					press: function(oEvent){
						setFKey('{$keyNr}');
					}
				}),
											
JS;
        return $js;
    }
    
    /**
     * 
     * @return ITSmobileProxyFacade
     */
    protected function getProxyFacade() : ITSmobileProxyFacade
    {
        if ($this->proxyFacade === null) {
            $this->proxyFacade = FacadeFactory::createFromString(ITSmobileProxyFacade::class, $this->getWorkbench());
        }
        return $this->proxyFacade;
    }
}