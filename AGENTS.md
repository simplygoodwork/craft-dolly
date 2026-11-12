# Portage

## Agent skills

### Issue tracker

Issues are Solo todos and specs are Solo scratchpads, both in the Solo project `craft-portage` (id 55), accessed through the Solo MCP tools. See `docs/agents/issue-tracker.md`.

### Triage labels

The five default triage roles, applied as Solo todo tags. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` and `docs/adr/` at the plugin root. See `docs/agents/domain.md`.

## Tests

Codeception with Craft's test module, run through DDEV against a separate `portage_test` database:

```bash
ddev exec --dir /var/www/html/plugins/portage composer test
ddev exec --dir /var/www/html/plugins/portage vendor/bin/codecept run unit <TestClass>
```

Build schema in tests with `tests/_support/Schema.php`. See "Running tests" in `README.md` for details.
