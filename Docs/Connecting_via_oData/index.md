# Using SAP OData services as data sources

Any OData service in SAP NetWeaver can be used as a data source. The more it adheres to the OData standard, the easier it is to use it and the less configuration you will need - see detailed recommendations below.

If you want to use your data source to generate UI5/Fiori apps, that can run on NetWeaver - refer to the documentation of the [UI5Facade](https://github.com/exface/UI5Facade/blob/master/Docs/index.md).

## Walkthrough

**CAUTION**: The model builder relies on annotations in the $metadata like `sap:filterable`, `sap:pageable`, etc. These need to be set manually in SAP. Make sure, they are set correctly and truly reflect the capabilities of the OData service! The trouble is, that the server will not error if a not-implemented feature is used: e.g. if an attribute is marked filterable, but the filter is not implemented, the service will simply ignore it. This makes troubleshooting a real headache!

1. [Create an OData service in SAP](../Creating_OData_services_in_SAP/index.md) if you don't have one yet.
2. Create an app for your OData service - a separate app helps organize your models and is very advisable in most cases!
3. [Create a connection and a data source](setting_up_an_oData_data_source.md)
4. [Import the $metadata](generate_metamodel_from_odata.md) 
5. [Finetune the model](metamodel_finetuning.md) to handle difficult situations

## Recommendations for OData services to work well with the metamodel

**Most important:** stick to the OData standards and URL conventions!

- Each business object should have exactly one URL endpoint 
- Each URL should yield exaclty one business object type (eventually including expanded related objects)
- Be accurate in your $metadata - if an EntityType property is marked filterable, it should really be filterable!
- Make listing endpoints (EntitySet) as generic as possible to allow the UI designer to pick his filters and sorters without having to request code changes every time.