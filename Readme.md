# AdPage Tagging plugin for Shopware

## Installation

The plugin can be instaled with composer

```bash
composer require tagginggroup/shopware
```

After installation install and enable the plugin at:

```
Extensions -> My extensions
```

After the plugin is enabled please configure it with the following field:

for the url you should enter the connected url of your server container (you can find this in your AdPage Tagging dashboard) for example:

```
https://gtm.tryforwarder.com
```

All settings can be set per sales channel. A sales channel without its own value uses the "All Sales Channels" value, so a shop with one server container per storefront can configure each storefront separately.

### Invoice and buy-now-pay-later payments

By default the webhook is sent when an order's payment changes to "paid". Invoice and buy-now-pay-later methods keep the payment on "authorized" until it is captured, which is often at shipment. Enable **Also send when a payment is authorized** to send the webhook at checkout for those orders. Each payment is sent once, also when it later changes from "authorized" to "paid".

