# Setting up a SAP OData connection and data source

Each OData service should be modeled as a sperate data source. The $metadata document can be imported and used to generate the corresponding metamodel automatically.

## Creating a data connection

1. Go to Administration > Metamodel > Connections 
2. Add a new connection with the connector, that matches the OData version - i.e. `SapOData2Connector` in most cases. For non-SAP OData services you can also use the `OData2Connector` or `OData4Connector` from the [UrlDataConnector](https://github.com/ExFace/UrlDataConnector/blob/master/Docs/index.md) app.
3. Press the magic wand on the configuration widget and choose a config preset, that looks best to you, and fill out the missing values.
4. If you have created an app for your OData metamodel, don't forget to assign it to the connection.

The only mandatory configuration option is the `url`, which should point to the root of your OData service. Make sure, it ends with a slash (e.g. `http://services.odata.org/V4/Northwind/Northwind.svc/`). However, setting `sap_client` explicitly is a good idea in most cases: if you amend this option, the OData service will determine the client by itself!

The name and alias of your connection can be anything - refer to the general [data source documentation](https://github.com/exface/core/blob/1.x-dev/Docs/understanding_the_metamodel/data_sources_and_connections.md) for details.

## Creating a data source

Now it's time to create a data source for our model. Proceed to Administration > Metamodel > Data Sources and add a new entry as shown below using the connection created in the previous step as default data connection.

![SAP OData data source settings](images/northwind_data_source.png)

Again, depending on the use SAP-specific annotations, choose either `SapOData2JsonUrlBuilder` or `OData2JsonUrlBuilder`. Make sure, the OData version of the query builder matches the version of your service. Most SAP OData services will use OData v2.

## Generating the metamodel

In the [next tutorial](generate_metamodel_from_odata.md), we will use the OData $metadata document to generate a metamodel. 



