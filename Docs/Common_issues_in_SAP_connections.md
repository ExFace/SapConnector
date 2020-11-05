# Common issues connecting to SAP

## Authorization fails even with correct password (error 401)

First of all, make sure it's the SAP _internal_ password and not the password from LDAP or any other single-sign-on method used within SAP. HTTP user authentication works with SAP's own passwords only!

Also make sure the SAP user is not locked (check transaction `SU01`). 

## SAP users getting locked after few login attempts