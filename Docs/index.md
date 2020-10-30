# SAP Connector

The SAP connector offers multiple options to use SAP systems as data sources.

## Contents

- [Connect via OData](Connecting_via_oData/index.md)
	- [Creating an OData service in SAP](Creating_OData_services_in_SAP/index.md)
- Connect via ADT SQL console
- [Calling RFC function modules or BAPIs via SOAP](Connecting_via_RFC_webservice/index.md)
- Connecto SAP HANA via ODBC
- Remote control ITSmobile apps

## Using OData services

oData web servcies are the preferred data sources for SAP UI5 (Fiori) apps. Being highly standardized RESTfull services, they are easy to import into the meta model. However, since the backend of each OData service is a custom implementation in SAP NetWeaver, the ease of use, performance, etc. largely depend to that implementation.

Refer to the [SAP OData connector documentation](Connecting_via_oData/index.md) for more information.

## Run SQL-SELECTs via ADT SQL webservice

Don't like OData? You can use OpenSQL to perform SELECT-queries on any SAP table using a web service from ADT (ABAP Development Tools). This allows to quickly fetch any data - even complex JOINs, subselects, etc. are no problem. However, this method bypasses a lot of SAP security regulations: any user, having access to the SQL ADT service can potentially see all data from all MANDT's. All visibility restrictions will need to be implemented in the meta model!

TODO

## Calling RFC function modules or BAPIs via SOAP web service

Prefer good old battle-tested RFCs? There is a possibility to access plain old RFC function modules and even some BAPIs by configuring a SOAP webservice for them. [Here is how](Connecting_via_RFC_webservice/index.md).

## SAP HANA ODBC connector

If your are using SAP HANA, you can access it directly via native SQL. Choosing this option will speed up app development enormously, but it should only be used for custom data structures, that have no relation to SAP applications.

## Use ITSmobile apps as a widget

You can easily integrate existing ITSmobile applications into your app without even changing a thing in SAP. Simply 

1. create an HTTP data source for the ITSmobile service
2. Specify the URL and the desired authentication method in the corresponding connection and
3. add the widget `exface.SapConnector.ITSmobile` anywhere in your app.

When the widget is loaded, it will automatically log on to the ITSmobile service and show the entry screen - rendered using the look&feel of the template used for your app! Now you can use the ITSmobile application as allways - it will work inside the widget.

**NOTE:** The widget `exface.SapConnector.ITSmobile` currently only works with the UI5 Facade.
**NOTE:** At the moment, only the default ITSmobile theme 99 is supported.