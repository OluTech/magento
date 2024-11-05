# FortisPay for Magento 2.4.4 and higher

## Composer Requirement

This payment plugin requires the composer package ```ramsey/uuid.```This is usually installed as a dependency of the
Magento Framework and does not have to be installed separately.

If for some reason it is not installed run the following command from the project
root: ```composer require ramsey/uuid```

## Installation

- Unzip the contents of the zip file to a temporary directory on your computer.
- Use an FTP client to upload the contents to the ``{{project_root}}/app/code`` directory of your Magento installation.
  The resultant directory structure should be ``{{project_root}}/app/code/Fortispay/Fortis.``
- From the project root run the following commands:
- ``bin/magento module:enable Fortispay_Fortis.``
- ``bin/magento setup:upgrade.``
- ``bin/magento setup:di:compile.``
- ``bin/magento setup:static-content:deploy.`` This may not be necessary depending on your setup.
- ``bin/magento indexer:reindex.``
- ``bin/magento cache:clean.``

You will then be able to configure the plugin via the admin portal at ``Stores/Configuration/Sales/Payment Methods.``

## Level 3 Data

If your Fortis account has Level 3 Data enabled additional custom attributes have to be configured on products for Level
3 Data to be successfully updated:

- Create two new product attributes (if they do not exist) with the names ``commodity_code`` and ``unit_code``.
- Add these attributes to products, and set the value for each attribute in each product.
- If these values are not configured checkout and payment will proceed normally, but the Level 3 Data will not be
  populated and an exception will be logged in the Magento logs.
- The ``commodity_code`` value is a string of between 1-12 characters, and is *"An international description code of the
  individual good or service being supplied."*
- The ``unit_code`` value is a string 3 characters long that describes *"Units of measurement as used in international
  trade."* Further information on this may be found
  at https://docs.fortispay.com/developers/api/endpoints/level3data#codesforunitsofmeasurement.
