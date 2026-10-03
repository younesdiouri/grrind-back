@/Users/younesdiouri/.codex/RTK.md

# Codex workflow

Read `CLAUDE.md` first: it is the source of truth for this repo, including the workflow.

There is no implementation agent and no cross-review. The thread that scopes a ticket
implements it, runs `make qa`, `make test` and `make openapi`, pushes, opens the PR, merges it
into `main`, and deploys to Fly (`.claude/skills/deploy/SKILL.md`) when the merge touches what
runs in production. None of these steps waits for the author's review.

Stop and ask only when a decision is not settled by the ticket, or when an invariant of
`CLAUDE.md` blocks the way. Spawn a sub-agent only when the author asks for one.
