# What Dolly doesn’t do

- **Move project config.** The environments need to share it. Dolly reports Drift so you can review differences.
- **Copy all related content automatically.** Full content travels only for Root elements and their Owned content. Other related elements travel as References. Select each entry you want to move as a Root element. A Stub carries only identifying information.
- **Publish imported entries for you.** New entries are disabled. Overwrites use drafts; block pastes edit drafts.
- **Import across Craft major versions.** A Payload from another Craft major is refused.
- **Translate unsupported fields or arbitrary IDs.** Check the fallback warnings and the caveats in [Supported field types](field-types.md).
- **Accept invalid Payloads.** Dolly checks the envelope before planning. An unknown format version, malformed section, or Root element or Reference with an unregistered element type refuses the Payload. Built-in types are entries, categories, tags, users and assets, plus Formie forms and Calendar events when their plugins are installed and enabled. See [Register an element type](developers.md#register-an-element-type).
- **Accept unlimited Payload sizes.** The default `maxPayloadSize` is 8 MB, for both Payload text and decompressed content. Dolly stops decompressing at the limit, so a small Payload that expands to gigabytes cannot exhaust memory. You can raise it in [`config/dolly.php`](configuration.md#config-settings).
