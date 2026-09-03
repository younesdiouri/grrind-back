@/Users/younesdiouri/.codex/RTK.md

# Codex workflow: architect and implementation agent

For implementation-ready tickets, the primary Codex thread is the architect.
It delegates the full implementation to the project custom agent `developer_terra`, defined in
`.codex/agents/developer-terra.toml`, and gives it the ticket number plus every decision or
constraint that is not already explicit in the ticket.

`developer_terra` owns the implementation, tests, required QA, commits, push, and PR. It never
merges. Once the required tests and QA pass, it pushes the branch and opens the PR directly,
without waiting for a cross-review or approval from the primary thread. While it is working, the
primary thread must not edit the same scope in parallel. The primary thread reports the resulting
PR and validation evidence to the user; no cross-agent review is required.

Use this delegation workflow only after the ticket and its scope are ready. Exploration,
architecture decisions, and ticket writing remain with the primary thread.
