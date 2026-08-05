### Version 2.1.0
* Support for GRAM coin
* The SDK updated to 2.1.2

### Version 2.0.0 ###
* The plugin source code for all Opencart versions is a single, non-repeating code base.
* Now the plugin source code is based on [Apirone SDK PHP library 2.0](https://github.com/Apirone/apirone-sdk-php).
* The “**Invoice** application” is a separate SPA now. This means invoice rendering occurs client-side. This SPA is also a part of the SDK, but can be accessed as an [independent application](https://github.com/Apirone/invoice-app).
* Invalid settings do not block the opportunity to save valid settings.
* The "**Include fees**" option was added to the main **Settings** tab. It adds service and network fees to the total. The final amount per coin in fiat will be shown to the customer.
* For currencies with tokens (USDT, USDC) in the “**Currencies**” tab there are more flexible settings to set visibility of boxes displayed for end customers. The address of the currency must be set in only one input field. Check the boxes below this input field for the main network currency or any token. If no boxes are checked for the currency, even with a filled valid address, the currency will not appear in the currency selector.
* Default status mapping was changed. The order has the “**Complete**” status in Opencart if the invoice has “**Paid**”, “**Overpaid**”, or “**Completed**” status. Yet the mapping can be changed.
* The currency selector now has an image for every currency. If fees are not included in the total amount, the text for a currency contains only its name. If included, the total amount in fiat (plus the fees), is added to the text.
* The order history comments contain a hyperlink to the corresponding cryptocurrency explorer.

### Version 1.2.6 ###
* Show tbtc for non-auth users when * is set into test customer field.
* Fixed links when oc installed to relative path (not www-root)

### Version 1.2.5 ###
* Added internal QR code generator.
* Added Logging & Debug mode.
* Settings are divided by tabs.
* Added a “**Tips and Information**” tab.
* Fixed admin page layout.

### Version 1.2.4 ###
* Fixed bug with unsaved settings for Opencart version 4.0.2.3

### Version 1.2.3 ###
* Fixed bug with displaying small amounts in exponential format for the currency selector

### Version 1.2.2 ###
* Added the ability to pay for downloads and subscriptions

### Version 1.2.1 ###
* Added support Opencart up to 4.0.2.2 version

### Version 1.2.0 ###
* The plugin is switched to a new fee plan.
  Now the fee is not fixed but charged in amount of 1% of the transfer.
