# One Craft major per branch; Payloads never cross majors

Craft 4 and Craft 5 store Matrix content in fundamentally different shapes (`MatrixBlock` elements with block types vs. nested entries with shared entry types), so Dolly is built as separate branches per Craft major (Craft 5 on `main` first, Craft 4 as a maintained branch later) rather than one codebase with a version-adapter layer. What is shared across branches is the documented, version-tagged Payload format, the Resolution rules and the UI concepts — not runtime code. Every Payload records the Craft major it was exported from, and Import refuses a Payload from a different major: moving content from Craft 4 to Craft 5 is a migration, which Craft's own upgrade already performs.

## Considered Options

- **Single codebase with a version adapter layer**: rejected because the Matrix difference leaks into nearly every Field adapter and the Preview, so the abstraction cost would be paid everywhere for one structural difference.
- **Cross-major Import (4 → 5)**: rejected as out of scope; it would require Dolly to re-implement Craft's Matrix-to-entries migration.
