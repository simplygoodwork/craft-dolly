# Import log and queued Imports

## Check the Import log

The **Import log** is the permanent record of Imports in the Target environment. Find it below the Payload form in **Utilities > Dolly**, newest first. Click an Import number to see its details.

The log records who ran the Import and when, the Payload’s identity and Source environment, the choices made for Unresolved references, and the result for each Root element. The detail page links to created entries or drafts and lists warnings, errors, Stubs and assets created by Fetch or Upload. Block pastes and command-line Imports are recorded too.

A Preview alone does not create an Import log entry.

## Large Imports in the queue

An entry Import from the Dolly utility runs as a **Queued Import** when it has more than 10 Root elements or any asset Fetch from a source URL. This means a Craft queue job runs the Import instead of the web request.

The Import log entry appears at once. Its detail page reloads itself while the Import is unfinished.

| Status | What it means |
| --- | --- |
| Queued | Waiting for the job to start. |
| In progress | The job is importing Root elements. Each says “not imported yet” until it has a result. |
| Completed | Every Root element has a result. An individual failure stays in the results and does not stop the others; each Root element has its own transaction. |
| Failed | The job could not run, or its worker died. See the causes below. |

An Import is marked Failed if:

- The user who queued it no longer exists or no longer has **Import content** permission.
- The Payload is refused or the job could not be added to the queue.
- The worker dies. An Import in progress for over an hour without a result is shown as failed.
- The Target environment changed after the Preview, so the Import would no longer do what you reviewed. In this case nothing is imported. Preview the Payload again before importing it.

A job that has started is never retried, because some Root elements may already have been imported.

The job keeps the Payload text, your exact Preview choices and a fingerprint of the plan you reviewed. It runs as the user who queued it.

Files you upload for unresolved assets stay in your staging directory until the job uses them, then are discarded. **The queue worker must share Craft’s runtime temp path with the web server.** Staged files are purged after 24 hours, when you next upload a file. A queue more than a day behind can therefore fail an Import that needs an uploaded asset.

Without a [Shared key](configuration.md#set-a-shared-key), an optional secret for encrypting and authenticating Payloads, the Payload text stays in Craft’s `queue` table and is visible in Queue Manager job details until the job runs. With a Shared key, the stored text is encrypted.

`dolly/import` always runs inline, regardless of size. Block pastes also run immediately, regardless of size or asset Fetches, so the blocks are in your draft when the paste finishes.
