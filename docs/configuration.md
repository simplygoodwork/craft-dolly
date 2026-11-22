# Configuration and permissions

## Config settings

Create `config/dolly.php` to change Dolly's settings. Like any Craft config file, it can return different values per environment.

```php
return [
    'clipboardLimit' => 100 * 1000,
    'maxPayloadSize' => 8 * 1024 * 1024,
    'fetchTimeout' => 30,
];
```

| Setting | Default | What it does |
| --- | --- | --- |
| `clipboardLimit` | 100,000 bytes | The largest Payload copied to the clipboard. Larger Payloads are offered as a `.dolly` file. |
| `maxPayloadSize` | 8 MB | The largest Payload an Import accepts, for both the text and its decompressed content. |
| `fetchTimeout` | 30 seconds | How long an asset Fetch waits for the file to download. Craft's `maxUploadFileSize` limits the file's size. |
| `sharedKey` | none | See [Set a Shared key](#set-a-shared-key). |
| `dynamicFields` | `[]` | See [Configure Dynamic Fields](developers.md#configure-dynamic-fields). |

## Set a Shared key

A **Shared key** is an optional secret that encrypts and authenticates every Payload. Use the same secret in every environment.

1. Go to **Settings > Plugins > Dolly**.
2. Set **Shared key** to an environment variable reference, such as `$DOLLY_SHARED_KEY`.
3. Give that environment variable the same secret in every environment.

The settings form only accepts an environment variable reference. The secret itself never reaches project config. A `sharedKey` in `config/dolly.php` is not checked this way, so use an environment variable reference there too.

With a Shared key, every Export produces a `dolly:v1e:` Payload using Craft’s authenticated encryption. Import accepts only Payloads encrypted with that same key. It refuses unencrypted Payloads, Payloads encrypted with another key, and Payloads changed after Export.

Without a Shared key, an encrypted Payload is refused. If your setting names an environment variable that is not set, both Export and Import fail. Dolly does not fall back to unencrypted Payloads.

## Permissions

Assign Dolly’s permissions to the users or groups who need them:

| Permission | Allows you to |
| --- | --- |
| **Export content** (`dolly-exportContent`) | Copy entries and selected blocks for another environment. Both the editor and index actions check that you can view the entries. |
| **Import content** (`dolly-importContent`) | Preview and import entries, paste blocks, and view the Import log. |

For **Utilities > Dolly**, you also need Craft’s permission to access that utility. Dolly’s permissions work alongside Craft’s content permissions. You need permission to create entries in the Target section, create drafts on an entry you overwrite, or edit the draft receiving pasted blocks. Replacements must be elements you can view. Fetching or uploading assets needs permission to save assets in the chosen volume, and to create folders if the folder does not exist.
