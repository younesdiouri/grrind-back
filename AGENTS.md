@/Users/younesdiouri/.codex/RTK.md

# Codex workflow: architect and developer

For implementation-ready tickets, the primary Codex thread is the architect and final reviewer.
It delegates the full implementation to the project custom agent `developer_terra`, defined in
`.codex/agents/developer-terra.toml`, and gives it the ticket number plus every decision or
constraint that is not already explicit in the ticket.

`developer_terra` owns the implementation, tests, required QA, commits, push, and PR. It never
merges. While it is working, the primary thread must not edit the same scope in parallel. The
primary thread waits for the handoff, reviews the resulting diff and validation evidence against
the ticket and project invariants, then sends precise correction instructions back to the
developer when needed. The primary thread remains responsible for accepting the work and for any
final merge decision.

Use this delegation workflow only after the ticket and its scope are ready. Exploration,
architecture decisions, ticket writing, and final review remain with the primary thread.
