# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Progress steps can fail. A step is running, done or failed, and a failed step can say why. When an attempt fails, the step it was on is marked failed, and when a run gets its answer, any step still running is marked done.

## [1.0.0] - 2026-10-31

### Added

- Agent runs on conversations: posting a prompt queues a run of an eloquent-agents harness and posts the reply back as a message.
- Each run keeps its attempts, one per provider tried, with the provider, model, finish reason, error, tool calls and usage. Usage stays empty when the provider never reported it.
- The prompt is always authored by the signed-in user, and the run executes with that user's access. Access is checked again when the run starts.
- Prompts can carry attachments in a shared shape. Images and documents are shown to the model, and files may only come from allowed hosts and disks.
- Files the agent produces are attached to the reply, so media channels deliver them, and images are shown as image blocks.
- Replies carry typed blocks: markdown, table, headline numbers, chart, callout, timeline, progress, question, image and canvas, plus block types the host registers.
- Render tools the agent calls to draw those blocks, registered with eloquent-agents.
- Canvas documents that gather a run's blocks, can be edited in a later run, and export to Markdown.
- Live progress steps from tool names or reported by tools themselves, optionally broadcast on the conversation's private channel.
- Each run executes once: workers claim it atomically, only the attempt holding the claim may publish, and the reply and completion are saved together.
- Provider fallback through a provider plan, and a retry policy deciding which failures are tried again. Answers cut off mid stream count as failures.
- Runs can be held until released, cancelled by the person who asked, and retried by them after a failure.
- A concurrency policy per conversation: wait for the earlier run, refuse, or run in parallel.
- A language and options per prompt, passed to tools.
- History sent to the model names agent replies by the run that wrote them, so messages from outside contacts are never read as the agent.
- Configurable models, request, resources, response formatter, input builder, media resolver, provider plan, retry policy, controller, routes, progress labels and exporters.
