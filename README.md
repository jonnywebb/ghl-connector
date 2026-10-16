# ghl-connector

Gravity Forms add-on that sends form entries to HighLevel (API v2): it upserts
the contact, adds a note, and can create a pipeline opportunity. No premium
plugin needed.

## What a feed can do

- **Map fields** to HighLevel's standard contact fields and your custom fields.
  Values are cast to each custom field's type (number, multi-select…).
- **Tags**, with merge tags (`{Service:5}` tags by form answer).
- **Assign the contact to a user** (needs `users.readonly`).
- **Note**: the ticked form fields, plus optional sections:
  - **Lead source** from Sebo Attribution (channel, UTM tags, click IDs,
    landing page). To send a value to its own custom field instead, map it:
    the "Attribution: …" entries appear in the Gravity Forms field list.
  - **User journey** from Campaign Tracker, reformatted into readable bullets.
- **Opportunity** in a chosen pipeline stage, with name, status and value.
- **Conditions**: Gravity Forms' standard feed logic.

Failures are written to the entry's notes and the Gravity Forms log, not
swallowed. The settings page shows a green tick when the token and location
work.

## Token scopes

`contacts.write`, `contacts.readonly`, `locations/customFields.readonly`;
`users.readonly` to assign contacts; `opportunities.write` and
`opportunities.readonly` for pipelines.

## Multisite: network settings

**Network Admin > Settings > GHL Connector** holds one Private Integration
Token and Location ID for every site. Each site's own settings
(**Forms > Settings > GHL**) override them; leave a site's fields blank to use
the network values. Feeds are still set up per form on each site.

Each site's settings page says which values it uses (network or its own) and
links super admins to the network page. Empty fields show the network
Location ID and a masked token as placeholders.

The saved token is never printed back on the network page: the field shows a
mask. Leave it to keep the token, paste a new one to replace it, or tick
"Remove the saved token".

## Updates

Sites update from this repo's GitHub releases, like any plugin. No token or
setup is needed (the repo is public).

To release: bump `Version:` and `GHL_CONNECTOR_VERSION` in `ghl-connector.php`,
push to `main`, then publish a GitHub release tagged `ghl-connector-vX.Y.Z`.

## History

- **2.5.2**: on a multisite, each site's settings page says whether it uses
  the network token and Location ID or its own, and the empty fields show the
  network values (the token stays masked).
- **2.5.1**: one plugin again. The 2.0.0 rewrite (opportunities, typed custom
  fields, credential check, logging, Campaign Tracker journeys) merged with
  2.3's user assignment, 2.5's network settings and GitHub updates, plus Sebo
  Attribution support.
- **2.5**: network settings; updates without a token.
- **2.3**: assign contacts to a user.
- **2.0.0**: rewrite (installed by hand on a few sites; never in this repo).
