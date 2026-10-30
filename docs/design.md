# Agent chat design

This package lets an agent take part in conversations stored by
`whilesmart/eloquent-conversations`. A person posts a prompt, the agent runs
with that person's access, and the answer comes back to the same conversation
as a message. The package owns how a run is requested, executed, retried and
published. It does not own conversations, messages, or the records a host app
produces (designs, reports, invoices); it references them.

## Concepts

| Concept | What it is | Where it lives |
|---|---|---|
| Conversation | Where people and the agent talk. May also carry SMS, email or other channels. | `eloquent-conversations` |
| Prompt | The person's message: text, attachments, and the options sent with it. | A message, plus the run |
| Run | The request for one answer. Holds status, progress, options and the links to prompt and reply. | `agent_runs` |
| Attempt | One execution with one provider and model. Holds usage, tool calls, finish reason, error and timing. | `agent_run_attempts` |
| Output | The reply message. Its body is the narration; `metadata.blocks` holds typed blocks; `attachments` holds files the agent produced. | A message |
| Tool | Something the agent can call. The package ships render tools; hosts register their own. | `eloquent-agents` registry |

A run has many attempts. Retries, provider fallback and recovery from a dead
worker each add an attempt; the run keeps one identity for the whole answer,
so a client follows one record per prompt.

## Lifecycle

```text
held ──release──▶ queued ──claim──▶ running ──▶ completed
                     ▲                 │  │
                     └──retry/backoff──┘  ├──▶ waiting ──resolve──▶ queued
                                          └──▶ failed ──retry by requester──▶ queued
any unsettled state ──cancel──▶ cancelled
```

- `held`: created but not ready, for example while attachments are still
  uploading. The host releases it.
- `queued`: waiting for a worker.
- `running`: claimed by exactly one worker through a conditional update. A run
  whose worker outlived the job timeout can be claimed again, and only the
  attempt holding the claim may publish.
- `waiting`: the agent proposed something that needs a person, such as an
  action awaiting approval. Reserved until a harness can suspend and resume.
- `completed`, `failed`, `cancelled`: settled.

## Rules every host gets

1. The prompt's author is always the signed-in user, and the run executes with
   that user's access. Access to the conversation's owner is checked again
   when each attempt starts.
2. Only the requester can retry a failed run.
3. The reply is created only when an attempt succeeds, inside the same
   transaction that completes the run. Outbound delivery is triggered when a
   message is created, so a blank or partial answer never reaches SMS or email.
4. An answer cut off mid stream is a failed attempt, never a completed run.
5. Every attempt keeps its usage. When an attempt fails before usage is known,
   it is recorded as unknown, never as zero.
6. History sent to the model is built from published messages only. Agent
   replies are identified by the run they belong to, not by a missing author,
   so inbound messages from outside contacts are never read as the agent.

## Attachments

Messages carry attachments in the `attachments` column of
`eloquent-conversations`. This package reads and writes items in one shape:

```json
{
  "type": "image",
  "url": "https://...",
  "disk": null,
  "path": null,
  "mime": "image/png",
  "name": "reference.png",
  "size": 48213,
  "ref": { "type": "library_asset", "id": "..." }
}
```

- `type` is one of `image`, `document`, `audio`, `video`, `file`.
- A file is located by `url`, or by `disk` and `path`. `ref` points at a host
  record, such as a stored asset, without the package knowing its model.
- Prompt attachments are validated against this shape and stored on the prompt
  message.
- Files the agent produces are attached to the reply message as well as shown
  as blocks, so channels that send media (MMS, email) deliver them.

The shape should be adopted by `eloquent-conversations` itself so every
channel agrees on it.

## Extension points

Each has a default and a contract the host can bind, configured the same way
as the existing model, request, resource and controller settings.

| Extension point | Default | Used for |
|---|---|---|
| Input builder | Recent published messages, the prompt, and the canvas note. | Adding workspace context or query hints. |
| Media resolver | Turns prompt attachments of type `image` and `document` into engine media; earlier images in the conversation are referenced in the text only. | Resolving host asset references, receipts, reference images; deciding which earlier images the model sees again. |
| Provider plan | The harness's own provider and model, one candidate. | An ordered fallback list with the host's health checks. Each candidate tried becomes an attempt. |
| Retry policy | Provider errors and cut-off answers retry with backoff until `tries` is used up. | Deciding which failures are worth retrying. |
| Concurrency policy | `queue`: a new prompt waits behind a running run in the same conversation. Also `reject` and `parallel`. | Prompts sent close together. |
| Run options | A `metadata` object, validated by the configured request class. | Per prompt settings such as a format hint, a size preset or a brand kit. |
| Language | The `language` sent with the prompt, else the app locale. | Replying in the person's language when the worker runs in another. |
| Block types | Core types (below) plus types the host registers. | Host records shown in chat, such as a design version or a report card. |
| Progress | Labels matched from tool names, plus a call a tool can make to report a step as it starts and ends. | Showing what the agent is doing while it runs. |
| Hold and release | Runs start `queued`. | Waiting for uploads or other input before the agent starts. |
| Approval hook | Off. | Moving a run to `waiting` when an action needs approval. |

## Blocks

Core types: `markdown`, `table`, `kpi`, `chart`, `callout`, `timeline`,
`progress`, `question`, `image`, `canvas`.

- `image`: `src`, `alt`, optional `attachment` index and `ref`.
- `canvas`: a titled document that gathers the blocks one run drew. A later run
  can edit it and it can be exported.
- A host type is any `type` string the host registers, with a payload the host
  validates. Blocks that point at host records carry a `ref` and are resolved
  by the client; the block never becomes a second copy of the record's state.

## Schema

`agent_runs`

| Column | Notes |
|---|---|
| `id` | Integer or UUID, following `conversations.uuids`. |
| `owner` | Morph; copied from the conversation. |
| `requester` | Nullable morph; the user the run acts as. |
| `conversation_id`, `prompt_message_id`, `reply_message_id` | Reply set only on completion. |
| `harness` | Harness name. |
| `status` | One of the lifecycle states. |
| `language` | Nullable locale code. |
| `options` | JSON run options. |
| `progress` | JSON list of steps for clients. |
| `current_attempt_id` | The attempt allowed to publish. |
| `error` | The latest failure, for clients. |
| `queued_at`, `started_at`, `completed_at`, timestamps, soft deletes | |

`agent_run_attempts`

| Column | Notes |
|---|---|
| `id`, `run_id`, `number` | Unique on `run_id` and `number`. |
| `provider`, `model` | What this attempt actually used. |
| `status` | `running`, `succeeded`, `failed`, `abandoned`. |
| `finish_reason`, `error`, `retryable` | |
| `tool_calls` | JSON: name, arguments and outcome per call. |
| `usage` | JSON; null when unknown. |
| `history_until_message_id` | The last message the attempt saw. |
| `lease_expires_at`, `started_at`, `finished_at` | |

## Events

`AgentRunQueued`, `AgentRunStarted`, `AgentRunProgressed`,
`AgentRunAttemptFailed`, `AgentRunCompleted`, `AgentRunFailed`,
`AgentRunCancelled`. When broadcasting is on, a compact update is sent on the
conversation's private channel; the stored run stays the source of truth for
clients that poll or reconnect.

## HTTP

| Method | Path | Purpose |
|---|---|---|
| POST | `/conversations/{conversation}/agent-runs` | Post a prompt (`body`, `attachments`, `language`, `options`, `harness`) and start or hold a run. |
| GET | `/agent-runs` | List runs the user can reach, filtered by conversation and status. |
| GET | `/agent-runs/{run}` | A run with its current attempt and reply. |
| GET | `/agent-runs/{run}/attempts` | The attempt history. |
| POST | `/agent-runs/{run}/release` | Start a held run. |
| POST | `/agent-runs/{run}/retry` | Requester only; failed runs only. |
| POST | `/agent-runs/{run}/cancel` | Requester only; unsettled runs only. |
| GET | `/agent-runs/{run}/export` | Export the run's canvas. |

## Not in scope

- Storing conversations, messages or delivery: `eloquent-conversations`.
- Running harnesses, tools and providers: `eloquent-agents`.
- Billing ledgers: `eloquent-agent-metrics`; usage is linked per attempt.
- Proposals and approvals: `eloquent-agent-actions`; blocks reference them.
- Streaming answer text token by token. Progress steps cover the wait; a
  streamed draft can be added later as attempt state outside messages.
- Branching and regenerating earlier answers in place.
