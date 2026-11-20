# Issue tracker: Solo

Issues for this repo live as **Solo todos**, and specs/long-form docs live as **Solo scratchpads**, in the Solo project `craft-dolly` (project_id `55`). Use the Solo MCP tools (`mcp__solo__*`). Pass `project_id: 55` on each call unless `whoami` shows the session is already scoped to it.

## Conventions

- **Create an issue**: `todo_create(title, body, priority, tags)`. Body is Markdown. Priority: `high` / `medium` / `low`.
- **Read an issue**: `todo_get(todo_id, include_comments: true)`.
- **List issues**: `todo_list` with `completed`, `status`, `tags`, `query`, `is_blocked` filters.
- **Comment**: `todo_comment_create(todo_id, body)`.
- **Labels are tags**: `todo_add_tag` / `todo_remove_tag`. Never pass `tags` to `todo_update` to change a single label; it replaces the whole set.
- **Status vs. triage**: Solo's `status` (`open`/`in_progress`/`backlog`/`completed`) tracks workflow; triage state lives in tags (see `triage-labels.md`).
- **Close**: comment the reason, then `todo_complete(todo_id, completed: true)`.
- **Specs**: `scratchpad_write(name, content, tags: ["spec", "feature:<slug>"])`.
- **Parent/child**: Solo has no sub-issues. Tag every ticket for a feature `feature:<slug>`, and start its body with `Spec: scratchpad #<id>` or `Part of todo #<id>`.
- **Blocking**: native, `todo_add_blocker(todo_id, blocker_id)`.

## When a skill says "publish to the issue tracker"

Create a Solo todo. If what's being published is a spec/PRD, create a scratchpad instead.

## When a skill says "fetch the relevant ticket"

`todo_get(id, include_comments: true)`; if the reference is a scratchpad, `scratchpad_read(id)`.

## Wayfinding operations

Used by `/wayfinder`.

- **Map**: a scratchpad tagged `wayfinder:map` and `wayfinder:<effort-slug>`, holding the Notes / Decisions-so-far / Fog body.
- **Child ticket**: a todo tagged `wayfinder:<type>` (`research`/`prototype`/`grilling`/`task`) and `wayfinder:<effort-slug>`, body starting `Map: scratchpad #<id>`.
- **Blocking**: `todo_add_blocker`. A ticket is unblocked when all blockers are completed.
- **Frontier query**: `todo_list(tags: ["wayfinder:<effort-slug>"], completed: false, is_blocked: false)`, skip `in_progress` or locked todos; lowest id wins.
- **Claim**: `todo_lock`, then `todo_update(status: "in_progress")`. This is the session's first write.
- **Resolve**: `todo_comment_create` with the answer, `todo_complete`, then append a context pointer (gist + `todo #<id>`) to the map's Decisions-so-far section.
