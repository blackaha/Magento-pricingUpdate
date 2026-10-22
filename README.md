# BlackAha Pricing Updater for Magento 2

Keeps your Magento product prices in line with the prices you set in **BlackAha**. When BlackAha sends a new price,
the module finds the product by its SKU, updates the price, and tells BlackAha whether it worked and why not when it
didn't. You stay in control: nothing is converted, rounded or guessed.

- Version 1.0.0 · Magento Open Source / Adobe Commerce 2.4.4 or later (tested on 2.4.9) · PHP 8.1 to 8.4
- License: OSL-3.0 and AFL-3.0 (see `LICENSE.txt` and `LICENSE_AFL.txt`)
- Requires a BlackAha account whose plan includes automatic price updates

## What you need

- Magento **2.4.4 or later**.
- Your store served over **HTTPS on a public domain** (for example `https://www.myshop.com`). Stores on `localhost`,
  an IP address or plain `http://` cannot be connected.
- In *Stores → Configuration → General → Web*, for the **default store view**: the secure **Base URL** and secure
  **Base Link URL** on that same HTTPS address.
- A **SKU** on every product you want BlackAha to update. Prices go to **simple, virtual and downloadable** products;
  for configurable, bundle and grouped products, use the SKUs of their simple products.
- Your store's **base currency** (*Stores → Configuration → General → Currency Setup*) must be the currency your
  BlackAha prices are in.

## Installation

### With Composer (recommended)

```bash
composer require blackaha/module-pricing-updater
bin/magento module:enable Blackaha_PricingUpdater
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento setup:static-content:deploy   # production mode only
bin/magento cache:flush
```

### From the zip file

1. Unzip the package into `app/code/Blackaha/PricingUpdater/` of your Magento installation, so that
   `app/code/Blackaha/PricingUpdater/registration.php` exists.
2. Run the same `bin/magento` commands as above, from `module:enable` on.

After installation, sign out of the Magento admin and sign in again so that the new menu entry appears.

The module does not change any Magento file. It adds its own database tables, the menu entry
**Catalog → Pricing Updater**, a configuration section, and a daily cleanup cron job. Make sure the Magento cron is
running (`bin/magento cron:install`).

## Connecting your store to BlackAha

### One-click connection (recommended)

1. Open **Catalog → Pricing Updater**.
2. Check that both lines under *Connect your store to BlackAha* show a green tick. If not, see
   [Troubleshooting](#troubleshooting).
3. Click **Connect to BlackAha**. Sign in to BlackAha if asked, and approve the connection.
4. You are brought back to Magento, which shows **Configuration successful**.
5. In BlackAha, open **Workspace Settings → Pricing rules → Store connection** and click **Test connection**.
   The page in Magento then shows **Connected**.

BlackAha sends no price until the connection test has succeeded.

### Manual connection

Use this if the one-click connection is not available for your store.

1. Open **Catalog → Pricing Updater** and expand **Connect manually**.
2. Click **Copy** next to *Webhook URL*.
3. In BlackAha, open **Workspace Settings → Pricing rules → Store connection**, paste the Webhook URL and save.
   BlackAha shows a **signing secret** once: copy it.
4. Back in Magento, paste it into **Signing secret** and click **Save signing secret**.
5. In BlackAha, click **Test connection**.

Keep the signing secret private. It is stored encrypted, never shown again, and saving a new one replaces the old one.

## Settings

*Stores → Configuration → Catalog → Blackaha Pricing Updater → Price updates*

- **Website for price updates**: used only when *Stores → Configuration → Catalog → Catalog → Price → Catalog Price Scope* is **Website**.
  Prices are written to this one website, never to all websites at once. With a single website it is chosen
  automatically. With the scope set to **Global**, prices apply to every website.
- **Keep results for (days)**: how long results are kept (30 by default). A repeated update within this time is
  answered from the stored result instead of being applied again.

## How prices are applied

- Each price is matched to **exactly one product by SKU**. If no product has that SKU, nothing is changed.
- A price counts as applied when a guest (NOT LOGGED IN customer group) buying one unit, **before tax**, in the base
  currency, pays exactly that price. This takes special prices, tier prices and catalog price rules into account.
  If the store would show another price, BlackAha shows the reason, with the price customers actually pay.
- **Special prices**:
  - A product with a running special price keeps its regular price; the new price becomes the special price when it
    is lower than the regular price, so the product still shows as discounted.
  - A new price equal to or above the regular price becomes the regular price, and the special price is removed.
- Prices are **never converted** to another currency and **never rounded**: a price with more than 2 decimals is
  reported as not applied.
- A price sent twice is applied once. BlackAha may resend an update; the module answers with the first result.
- Prices are reindexed and the affected product pages are cleared from the page cache right away. With the price
  indexer on **Update by Schedule**, category pages and search show the new price after the next cron run.

Every update is listed under **Recent price updates** on the Pricing Updater page, with its result and, when it was
not applied, the reason.

## Troubleshooting

| Message or symptom | What to do |
|---|---|
| *Your store must use HTTPS on a public domain to connect.* | Serve the store over HTTPS on its public domain, and set the secure Base URL of the default store view to that address. |
| *The base URL and the base link URL of the default store view use different domains or protocols.* | In *Stores → Configuration → General → Web → Base URLs (Secure)*, make **Secure Base URL** and **Secure Base Link URL** the same HTTPS address, for the default store view. |
| *This store's addresses were not accepted (…).* | Same as above. |
| *The connection could not be started (invalid_request).* | One-click connection is not available for your store yet. Use **Connect manually**. |
| *Your plan does not include automatic price updates.* | Upgrade your BlackAha plan, then connect again. |
| *Your server clock is more than a day off…* | Ask your host to correct the server time, or connect manually. |
| *Too many connection attempts.* | Wait the number of seconds shown, then try again. |
| *Connection cancelled.* | You declined the connection in BlackAha. Click **Connect** again when you are ready. |
| The connection test in BlackAha fails | Allow requests to `/pricingupdater/` in Cloudflare, your firewall or WAF, and make sure Varnish or any page cache passes POST requests to `/pricingupdater/` through. Turn off maintenance mode (`bin/magento maintenance:status`). |
| *No product with SKU "…" was found in the store.* | Add the SKU to the product, exactly as it is written in BlackAha. |
| *SKU "…" belongs to a configurable product…* | Use the SKU of the simple product (the variant) in BlackAha. |
| *The price is in EUR but the store's base currency is USD.* | Use the same currency in BlackAha and in Magento. Prices are never converted. |
| *The store sets prices per website, and no website is chosen…* | Choose **Website for price updates** in the settings above. |
| *The store did not keep the new price: … the product sells at …* | A catalog price rule, a tier price or another extension changes the price. Adjust it, then send the price again. |
| The menu entry does not appear | Sign out of the admin and sign in again; check that your admin role has *Catalog → Pricing Updater* allowed (*System → Permissions → User Roles*). |

The module writes connection problems (never secrets) to `var/log/pricing_updater.log`. Please include the relevant
lines when you contact support.

## Disconnecting and uninstalling

- **Disconnect** (on the Pricing Updater page) removes the signing secret: the store refuses price updates until it
  is connected again.
- Installed with Composer: `bin/magento module:uninstall Blackaha_PricingUpdater` removes the module, its tables and
  its settings. Prices already applied stay as they are.
- Installed from the zip: `bin/magento module:disable Blackaha_PricingUpdater`, delete
  `app/code/Blackaha/PricingUpdater/`, then `bin/magento setup:upgrade`.

## Privacy

The module stores, in your Magento database: the signing secret (encrypted), the BlackAha workspace it is connected
to, and the results of price updates (SKU, price, result, date). It stores no customer data and sends BlackAha only
the results of the updates it received, plus your store's address when you connect.

## Support

- Email: support@blackaha.com
- Website: https://app.blackaha.com

## License

Copyright © 2026 BlackAha. Licensed under the Open Software License 3.0 (`LICENSE.txt`) and the Academic Free
License 3.0 (`LICENSE_AFL.txt`).
