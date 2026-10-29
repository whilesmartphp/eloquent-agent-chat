# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-30

### Added

- Agent turns on conversations: posting to a conversation queues a run of an eloquent-agents harness and posts the reply back as a message.
- The prompt is always authored by the signed-in user, and the agent runs with that user's access. Access is checked again when the turn runs.
- Replies carry typed blocks: markdown, table, headline numbers, chart, callout, timeline, progress, question and canvas.
- Render tools the agent calls to draw those blocks, registered with eloquent-agents.
- Canvas documents that gather a turn's blocks, can be edited in a later turn, and export to Markdown.
- Live progress steps per turn, stored on the turn and optionally broadcast on the conversation's private channel.
- Each turn runs once: workers claim it atomically, and the reply and completion are saved together.
- Provider failures and answers cut off mid stream are retried within the configured attempts, then fail the turn with the reason.
- Failed turns keep their error and can be retried by the person who asked. A turn that cannot be queued fails so it can be retried.
- Configurable model, request, resource, response formatter, input builder, controller, routes, progress labels and exporters.
