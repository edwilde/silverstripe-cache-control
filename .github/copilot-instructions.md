# GitHub Copilot Instructions

All project knowledge for this module lives in [`/Agents.md`](../Agents.md): architecture, data flow, database schema, header generation rules, testing strategy, code style, commit conventions and the step-by-step recipe for adding a cache directive. Read it before starting any task and keep it as the single source of truth; do not duplicate its content here.

## Copilot-specific notes

- Create and edit files with the editor's `create`/edit tools, never with `cat >`, `echo >` or other shell redirection.
- Run the suite with `vendor/bin/phpunit tests/Extensions/ --testdox` before proposing a change as complete.
- When a change alters architecture, schema or conventions, update `Agents.md` in the same change rather than adding notes to this file.
