# Apirone Crypto Payments for Opencart 2.x, 3.x, 4.x #

## Description ##

Accept the most popular cryptocurrencies (BTC, LTC, BCH, Doge, etc.) in your store all around the world. Use any crypto supported by the provider to accept coins using the Forwarding payment process.

**Key features:**

* Payments are automatically forwarded from a temporarily generated crypto-address directly into your wallet (the temporary address associates the payment with an exact order).

* The payment gateway charges either a fixed fee which does not depend on the amount of the order or a percentage fee in the amount of 1% of the transfer. Small payments are totally free of service fees. See about fee plans on [https://apirone.com](https://apirone.com)

* You do not need to complete a KYC/Documentation to start using our plugin. Just fill in settings and start your business.

* White label processing (your online store accepts payments on the store side without redirects, iframes, advertisements, logo, etc.).

* This plugin works well all over the world.

* Tor network support.

## Installation ##

1. Download the build for your Opencart version:
    - Opencart 2 - apirone-crypto-payments.oc2.vX.X.X.ocmod.zip
    - Opencart 3 - apirone-crypto-payments.oc3.vX.X.X.ocmod.zip
    - Opencart 4 - apirone-crypto-payments.oc4.vX.X.X.ocmod.zip

    **Important for Opencart 4** - Rename file to __apirone.ocmod.zip__
2. Go to **Extensions** » **Installer** and upload the plugin file.
3. Go to **Extensions** » **Extensions**. Choose **Payments** from the dropdown menu.
4. Click the **Install** (the green plus) button to install the Apirone Crypto Currency plugin.
5. Click the **Edit** button.
6. Enter your **cryptocurrency addresses** for desired cryptos and switch plugin **Status** to enable the plugin.

In total to make **Cryptocurrency payment** method available to customers those minimal settings must be set:
- Status must be Enabled.
- For one or more currencies a valid address must be set.
- If a valid address is specified only for currencies with tokens, then a minimum one check-box for the main currency of the network or any token must be checked.

## Update ##

Opencart v3 updating:
- Download the build for Opencart v3
- Without deleting the old plugin version, install using the admin panel.
- Go to the plugin settings page. 
- All values should be from the previous version. 
- Check the status mapping and, if necessary, set the statuses you use for various invoice states. 

Opencart 4 updating:
- Without deleting the installed plugin, unpack the data archive into the extensions/apirone directory.

For all Opencart versions:
- On the **Currencies** tab of the plugin settings for currencies that have tokens and a valid address filled, check the check-boxes for the main currency of the network or any token.

## How does it work? ##

The Buyer adds items into the cart and prepares the order. Using API requests, the store generates crypto (BTC, LTC, BCH, Doge) addresses for payment and shows a QR code. Then, the buyer scans the QR code and pays for the order. This transaction goes to the blockchain. The payment gateway immediately notifies the store about the payment. The store completes the transaction.

## Requirements & License ##

Opencart 2.x, 3.x, 4.x

Since version 2.0.0 the plugin has been based on [Apirone SDK PHP](https://github.com/Apirone/apirone-sdk-php) that works on PHP v.7.4+. So the minimum PHP version is 7.4. PHP v.8.0+ is recommended.

License MIT

## Third Party API & License Information ##

* **API website:** [https://apirone.com](https://apirone.com)
* **API docs:** [https://apirone.com/docs/](https://apirone.com/docs/)
* **Privacy policy:** [https://apirone.com/privacy-policy](https://apirone.com/privacy-policy)
* **Support:** <support@apirone.com>

## Frequently Asked Questions ##

**I will get money in USD, EUR, CAD, JPY, RUR...?**

> No, you will get crypto only. You can enter the crypto address of your trading platform account and convert crypto (BTC, LTC, BCH, Doge) to fiat money at any time.

**How can The Store cancel orders and return bitcoins?**

> This process is fully manual because you will get all payments to your specified wallet. Only you control your money. Contact the Customer, ask for an address and finish the deal. Bitcoin protocol has no refunds, chargebacks, or transaction cancellations. Only the store manager makes decisions on underpaid or overpaid orders whether to cancel the order or return the rest directly to the customers.

**I would like to accept Litecoin only. What should I do?**

> Just enter your LTC address on settings and keep other fields empty.

**Fee:**

>The plugin uses the free Rest API of the Apirone crypto payment gateway. The pricing page [https://apirone.com/pricing](https://apirone.com/pricing)

## Changelog ##

See `CHANGELOG.md`
