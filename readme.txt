=== GF ↔ Zoho Sync ===
Contributors: mattduncan
Tags: zoho, gravityforms, crm, zoho desk, integration, form, sync
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Two-way synchronization between Gravity Forms and Zoho CRM/Desk modules.

== Description ==

GF ↔ Zoho Sync provides seamless two-way integration between your Gravity Forms and Zoho CRM or Zoho Desk. Push form submissions to Zoho as new records or update existing ones, and sync changes back from Zoho to your form entries.

= Features =

* **Two-way synchronization**: Updates in either system are reflected in the other
* **Multiple module support**: Works with Zoho CRM (Contacts, Leads, Accounts, Deals, Products, Cases) and Zoho Desk (Tickets)
* **Smart field mapping**: Map any Gravity Forms field to any Zoho field
* **Record lookup**: Find existing Zoho records using any field (email, VIN, etc.)
* **Conditional feeds**: Control when entries sync based on form values
* **File uploads**: Attach form file uploads to Zoho records
* **Comprehensive logging**: Debug and monitor all sync operations
* **Multiple data centers**: Works with any Zoho data center (US, EU, IN, AU, etc.)

= Requirements =

* WordPress 5.0 or higher
* Gravity Forms 2.5 or higher
* Zoho CRM and/or Zoho Desk account with API access

== Installation ==

1. Upload the `gf-zoho-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Zoho Sync to enter your Zoho API credentials
4. Create a new API client in the [Zoho API Console](https://api-console.zoho.com/) with these scopes:
   * ZohoCRM.modules.ALL
   * ZohoCRM.settings.ALL
   * ZohoSearch.securesearch.READ
   * desk.tickets.ALL (if using Zoho Desk)
   * desk.settings.ALL (if using Zoho Desk)
5. Connect to Zoho using the button on the settings page
6. Add a feed to any Gravity Form via Form Settings > Zoho Sync

== Frequently Asked Questions ==

= How do I set up two-way synchronization? =

When creating a feed, select "Two-way" in the Sync Direction setting. This will automatically create a webhook in Zoho to notify WordPress when records change.

= Can I map file uploads from my form to Zoho? =

Yes, file upload fields are automatically detected and will be attached to the Zoho record.

= How do I know if the sync is working properly? =

The plugin includes comprehensive logging that shows all sync operations. Go to Forms > Settings > Logging, select "Zoho Sync" from the dropdown, and enable logging.

= What happens if a sync operation fails? =

All errors are logged in the Gravity Forms logging system. If a sync fails, the error details will be recorded there.

= Can I have multiple forms sync to the same Zoho module? =

Yes, you can create feeds for as many forms as needed, all syncing to the same or different Zoho modules.

= Can I sync with both Zoho CRM and Zoho Desk? =

Yes, the plugin supports both Zoho CRM and Zoho Desk. Select the appropriate API type in your feed settings.

== Screenshots ==

1. Zoho API settings page
2. Feed configuration
3. Field mapping interface
4. Logging and debugging

== Changelog ==

= 1.0.0 =
* Initial release
* Two-way sync between Gravity Forms and Zoho CRM/Desk
* Module support for Contacts, Leads, Accounts, Deals, Products, Cases, and Tickets
* Comprehensive logging system
* File upload support

== Upgrade Notice ==

= 1.0.0 =
Initial release

== Debugging ==

This plugin includes comprehensive logging to help troubleshoot any issues:

1. Go to Forms > Settings > Logging
2. Select "Zoho Sync" from the dropdown menu
3. Set the log level to "Debug"
4. Click "Save Settings"
5. View the logs to see detailed information about all sync operations

Key information that is logged includes:
* API requests and responses
* Field mapping operations
* Record creation and updates
* Webhook processing
* Error conditions and resolutions
