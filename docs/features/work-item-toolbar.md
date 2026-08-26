# Work item detail toolbar — vote and subscribe

Source: `html/work-items.html`, the drawer toolbar (`#wi-vote-up`, `#wi-vote-down`,
`#wi-subscribe`). Both controls were in the POC and neither had been built; the toolbar shipped
with only Copy link and the ⋯ menu.

## Requirement

On the work item detail — the drawer and the full-page view, which are the same component —
the right-hand side of the toolbar carries:

- **Upvote / downvote**, each with its running count.
- **Subscribe**, a toggle that reads *Unsubscribe* once you are following.

## User Roles

Both are gated on **viewing** the work item, not on editing it. Voting on a proposal and asking
to hear about it are things a Commenter, a Viewer or a Guest has every reason to do, and
neither changes the item. `WorkItemPolicy@view` still applies, so an item in a project you
cannot open is refused — as a **404**, never a 403, so a refusal cannot confirm it exists.

## Database Fields

`2026_09_21_000001_create_work_item_votes_and_subscribers_tables`. Both tenant-scoped
(CLAUDE.md §7), both one row per person per item, enforced by a unique pair at the database
rather than by the code that writes them.

**`work_item_votes`** — `tenant_id`, `work_item_id`, `user_id`, `value` (`up` | `down`),
`UNIQUE(work_item_id, user_id)`, `INDEX(work_item_id, value)`.

One `value` column rather than two tables: a person holds **one** opinion, so switching sides
is an update, never an insert plus a delete that could half fail and leave them counted on both
sides at once. Taking a vote back deletes the row rather than storing a third state meaning
"none" — "have they voted?" is then the row's existence, not a value check.

**`work_item_subscribers`** — `tenant_id`, `work_item_id`, `user_id`,
`UNIQUE(work_item_id, user_id)`, `INDEX(user_id, work_item_id)`.

The row payload gains `votes: {up, down}`, `my_vote` and `subscribed`.

## Business Rules

1. Voting the **same** side again takes the vote back; voting the **other** side switches it.
2. The count is everyone's; `my_vote` is only ever yours. Both travel with the row, because
   whether *you* voted is not derivable from a total, and the alternative is shipping every
   voter's id to the browser so it can find you in the list.
3. Subscribing to a work item is **not** subscribing to its project. `project_subscribers`
   answers "tell me about this project"; this answers "tell me about this work item". Somebody
   following one contentious item does not thereby want everything else in the project.
4. Both endpoints toggle server-side and answer with the **resulting state**, not `ok`. The
   toolbar paints from the response rather than predicting what its click did, so two tabs open
   on the same item cannot disagree about the count.

## Acceptance Criteria

- **WT-01** Up then down leaves one row, one down-vote and no up-vote.
- **WT-02** The same side twice removes the vote entirely.
- **WT-03** Two people voting up count 2; one of them retracting leaves the other's vote alone.
- **WT-04** A `value` that is neither `up` nor `down` is refused (422).
- **WT-05** Subscribe toggles, and does not write a `project_subscribers` row.
- **WT-06** A project Commenter can vote and subscribe, and still cannot edit the item.
- **WT-07** Someone who cannot see the item gets 404, and no vote is recorded.
- **WT-08** The row payload carries the counts on load, not only after a click.
- **WT-09** A first vote writes one `work_item_vote_changed` row with `none → up`, and the
  Activity tab reads "Rohit voted 👍 Up".
- **WT-10** Switching sides writes `up → down` and reads "changed vote from 👍 Up to 👎 Down".
  Still one row in `work_item_votes`.
- **WT-11** Voting the same side again writes `up → none` and reads "removed their vote" —
  the withdrawal is kept even though the vote row is gone.
- **WT-12** Voting creates **no** comment; `work_item_comments` is untouched.
- **WT-13** The vote lines appear in All, in timestamp order among comments, updates and
  status changes, and in History as `None → 👍 Up`.
- **WT-14** A refused vote (404 / 422) writes no activity row.

## UI Requirements

Copied from the POC, including the two arrow SVGs, which joined `IconRegistry` as `arrow-up`
and `arrow-down` rather than being inlined — so they follow the icon-set switch like every
other icon.

The chosen side is **filled** (`bg-sel text-brand`) rather than outlined, so "how does the team
feel" and "what did I say" are both answerable at a glance. Once subscribed the button wears
**your own avatar** in place of the person glyph — the POC's way of saying *you are on this
list*, which a filled bell cannot.

## Real-Time Requirements

**None yet**, and this is the obvious place for them later: a vote count is exactly the sort of
thing two people looking at the same item should see move. Deferred rather than forgotten —
the endpoints already answer with the full resulting state, which is the payload a broadcast
would carry.

## Queue Requirements

**None.** Both are a single small write.

## Audit Requirements

**Voting is audited** (added after the first cut — see D-T5). Every cast, switch and
withdrawal writes one `work_item_activity` row:

| Column | Value |
|---|---|
| `event` | `work_item_vote_changed` |
| `work_item_id` | the item voted on |
| `actor_id` | the voter |
| `field` | `vote` |
| `old_value` | `up` \| `down` \| `none` |
| `new_value` | `up` \| `down` \| `none` |
| `meta` | `old_label` / `new_label` — the 👍 Up / 👎 Down / None wording, frozen at write time |
| `created_at` | timestamp |

Written by `WorkItemActivityRecorder::voteChanged()`, from
`WorkItemReactionController@vote`. `none` is stored on either side rather than null, because
"no vote" is a real state of the trail — and History filters out rows with nothing on either
side, so a null would hide the withdrawal it is meant to record.

The `work_item_votes` table still holds only the **current** position (one row per person,
deleted when the vote is taken back). The trail lives in the activity table; the tally lives
in the votes table. Neither is derived from the other.

Where it shows up:

- **Activity** — "Rohit voted 👍 Up", "Rohit changed vote from 👍 Up to 👎 Down",
  "Rohit removed their vote", each with the actor and timestamp.
- **History** — the same line rendered before → after (`None → 👍 Up`).
- **All** — merged into the single timeline by timestamp, alongside comments, updates,
  status changes and assignments. No extra work: All merges the activity rows.

**No comment is created for a vote.** The vote endpoint answers with the rebuilt feed as well
as the counts, so an open drawer shows the new line without a reload.

Subscribing is still **not** audited: it is a private preference, not a position on the item.

---

## Planning & Reasoning (Claude Code)

### Decisions

| # | Question | Decision |
|---|---|---|
| D-T1 | Who may vote and subscribe? | **Anyone who can view the item.** Gating on edit would mean the people most likely to want a say — a Commenter reviewing a proposal, a stakeholder following a release blocker — could do neither. Neither action mutates the work item. |
| D-T2 | One vote table or two? | **One, with a `value` column.** See above: switching sides has to be atomic. |
| D-T3 | Item subscription vs project subscription | **Separate tables, separate meanings.** Deliberately not reusing `project_subscribers`, which is a different question with a different blast radius. |
| D-T4 | Counting on a list | **Batched** in `WorkItemReactions`, alongside `WorkItemBlockers` and `WorkItemStatusUpdates`. Per row it would be three queries per work item — 750 on a full list — instead of three in total. |
| D-T5 | Should a vote be audited? | **Yes — as its own event**, `work_item_vote_changed`, not as an `updated` row and never as a comment. Reversed from the original "a vote changes nothing about the work item": the trail of *who moved which way, and when* is the point of a vote on a proposal, and the votes table cannot answer it — it keeps only where everyone currently stands. |

### An open question this raises

Your Work's **Subscribed** tab currently lists items from projects you subscribe to (see
[your-work.md](your-work.md) D-Y1), a decision taken *because* per-item subscription did not
exist. It does now. That tab could reasonably become per-item subscriptions, or the union of
both. Left as it was rather than changed quietly — it is a product call, not a tidy-up.

## Files Changed

**Migration** — `database/migrations/2026_09_21_000001_create_work_item_votes_and_subscribers_tables.php`

**Models** *(new)* — `app/Models/WorkItemVote.php`, `app/Models/WorkItemSubscriber.php`;
`app/Models/WorkItem.php` (`votes()`, `subscribers()`)

**Controller** *(new)* — `app/Http/Controllers/Project/WorkItemReactionController.php`

**Service** *(new)* — `app/Services/WorkItemReactions.php`;
`app/Services/WorkItemScreenPayload.php` (the row payload and the two endpoints)

**Routes** — `routes/project.php`

**Icons** — `app/Support/IconRegistry.php` (`arrow-up`, `arrow-down`)

**JS** — `public/assets/js/projects/work-items.js` (the toolbar, `vote()`, `toggleSubscribe()`)

**Tests** *(new)* — `tests/Feature/Project/WorkItemToolbarTest.php`
