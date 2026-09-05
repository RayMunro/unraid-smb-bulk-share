# SMB Bulk Share Control for Unraid

SMB Bulk Share Control adds a page under **Settings → User Utilities** for
enabling or disabling SMB export across normal Unraid user shares.

## Safety behaviour

- Preserves each share's Public, Secure, or Private security mode.
- Preserves existing hidden and Time Machine export modes when enabling.
- Excludes `appdata`, `domains`, `system`, and `isos` by default.
- Never changes the flash share or disk shares.
- Creates a timestamped backup before every real bulk change.
- Can restore the most recent change, including hidden and Time Machine modes.
- Uses Unraid's own `update.htm` handler so live Samba configuration and the
  persistent share configuration remain in sync.

## Installation

**Via Community Applications:** search for "SMB Bulk Share Control" in the
Apps tab and click Install.

**Manually:** in the Unraid webGUI go to **Plugins → Install Plugin** and
paste:

```
https://raw.githubusercontent.com/RayMunro/unraid-smb-bulk-share/main/smb-bulk-share.plg
```

Then open **Settings → User Utilities → SMB Bulk Share Control**. The
package is embedded directly in the `.plg`, so nothing else needs to be
downloaded or copied by hand.

The plugin targets Unraid 6.12 or newer.

Current release: **2026.09.02a**.

## Automatic mode

When **Always enable new or disabled shares** is enabled, a five-minute check
turns SMB export on for any non-excluded share whose export setting is `No`.
Existing `Yes (hidden)` and Time Machine modes are not altered.

## Files on Unraid

- Settings: `/boot/config/plugins/smb-bulk-share/settings.cfg`
- Backups: `/boot/config/plugins/smb-bulk-share/backups/`
- WebGUI: `/usr/local/emhttp/plugins/smb-bulk-share/`
- Scheduled check: `/boot/config/plugins/smb-bulk-share/smb-bulk-share.cron`

## License

MIT License. See `LICENSE`.
