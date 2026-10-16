# ghl-connector

Gravity Forms add-on that sends form entries to HighLevel (V2 API) as contacts, with an optional note and user journey.

## Multisite: network settings (2.4)

On a multisite, **Network Admin > Settings > GHL Connector** holds one Private
Integration Token and Location ID for every site. Each site's own settings
(**Forms > Settings > GHL**) override them; leave a site's fields blank to use
the network values. Feeds are still set up per form on each site.

The saved token is never printed back on the network page. Leave the field
blank to keep it, or tick "Remove the saved token".

## Updates (private repo)

Updates come from this repo's GitHub releases. Since 2.4 the access token is
not in the code: add it to each site's `wp-config.php`, above "That's all,
stop editing":

```php
define( 'GHL_CONNECTOR_GITHUB_TOKEN', 'github_pat_...' );
```

Use a fine-grained token limited to this repository with read-only
**Contents** access. Without it the plugin works normally but does not see
new versions.

To release: bump `Version:` in `ghl-connector.php` and `$_version` in
`class-ghl-feed-addon.php`, push to `main`, then publish a GitHub release
tagged `ghl-connector-vX.Y`.
