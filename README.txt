README file for Commerce Klarna Checkout

INTRODUCTION
------------
This project integrates Klarna Checkout payment into the Drupal Commerce
payment and checkout systems.
https://developers.klarna.com/en/se/kco-v2/klarna-checkout-overview-v1


REQUIREMENTS
------------
This module requires the following:
* Submodules of Drupal Commerce package (https://drupal.org/project/commerce)
  - Commerce core,
  - Commerce Payment (and its dependencies);
* Klarna Checkout PHP Library, v4.0 (https://github.com/klarna/kco_php);
* Klarna Merchant account (https://merchants.klarna.com).


INSTALLATION
------------
This module needs to be installed via Composer, which will download
the required libraries.
* Add repository definition to composer.json
    "repositories": {
        "commerce_klarna_checkout": {
            "type": "vcs",
            "url": "https://github.com/mitrpaka/commerce_klarna_checkout.git"
        }
    }
* Install module
composer require "drupal/commerce_klarna_checkout"
https://www.drupal.org/docs/8/extending-drupal-8/installing-modules-composer-dependencies


CONFIGURATION
-------------
* Create new Klarna Checkout payment gateway
  Administration > Commerce > Configuration > Payment gateways > Add payment gateway
  Klarna Checkout specific settings available:
  - Merchant ID (test/live);
  - Password (test/live);
  - Path to terms and conditions page;
  - Language.
  API credentials are provided by the Klarna Checkout merchant account.

* Checkout flow
  Klarna Checkout module adds "Klarna Confirmation message" checkout pane to
  complete phase by default
  - If Klarna Checkout is only payment gateway in your store, you may want to
  disable "Completion message" pane
  - If you use other payment gateways along with Klarna Checkout, you may want
  to define custom "Completion message" pane (that is not visible when
  Klarna Checkout payment method is selected)


TROUBLESHOOTING
---------------
* No troubleshooting pending for now.


KNOWN ISSUES
------------


MAINTAINERS
-----------
This project has been developed by:
mitrpaka@gmail.com
