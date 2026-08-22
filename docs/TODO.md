# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)

## Tasks

### 10. Spec and build NYT-style puzzle games

Add Wordle, Pips, Spelling Bee, Strands, and Connections. All need daily/random puzzle content and, since there's no licensed puzzle source, that content will most likely need to be AI-generated rather than hand-authored (unlike Best Match's hand-written card set, this isn't a fixed pool — new puzzles are needed continuously). Each game needs its own build spec, written the same skeleton-first way `docs/specs/clear-the-deck.md` was (see `docs/DEVLOG.md`'s Clear the Deck section), before any building starts. Once built, wire each into the shared puzzle-code/daily-puzzle/Challenge-a-Friend infrastructure Task 11 built for Boggle and Word Blocks (see `docs/DEVLOG.md`'s "Boggle" and "Word Blocks" sections): add the game to `GameType::isChallengeable()`, generate its seed via `App\Services\Puzzles\DailyPuzzle::seedFor()`, and add its own link column to the `/daily-puzzles` archive page.

**Wordle ("Five Letters") is now fully built** — see `docs/DEVLOG.md`'s own "Wordle" section for the complete build writeup (secrecy model, `CommonWordFilter`/`word_frequency.txt`, hard mode, scoring, Challenge a Friend). Remaining: Pips, Spelling Bee, Connections, Strands. Skeletons for all four exist: [`docs/specs/spelling-bee.md`](specs/spelling-bee.md), [`docs/specs/pips.md`](specs/pips.md), [`docs/specs/connections.md`](specs/connections.md), [`docs/specs/strands.md`](specs/strands.md) — open design questions flagged inline, not yet ready to build from. Spelling Bee and Pips fit the existing zero-persistence `DailyPuzzle::seedFor()` model with purely algorithmic generation (no LLM call), same as Wordle turned out to — lower risk than Connections/Strands. Connections and Strands both need real semantic content this app has no precedent for generating (its only existing LLM-backed service, `FamilyFeud\JudgeService`, judges a guess against a fixed answer list rather than authoring content), and likely need a persisted puzzle-content table rather than the on-the-fly seed derivation every other puzzle game uses — a shared open question worth resolving once rather than per-game (see either spec's section 1). Strands additionally needs a new word-placement/grid-tiling solver with no existing precedent in this app.

**Follow-up spun out of building Wordle:** apply the new `App\Services\Words\CommonWordFilter` (a general English word-frequency corpus, not scoped to Wordle — see `docs/DEVLOG.md`'s Wordle section) to Word Blocks' own `PuzzleGenerator` answer-word selection (`docs/specs/quartiles.md`), since an obscure Word Blocks answer word has the same "reads as unfair/obscure" problem Wordle's own answer selection was built to avoid. Deliberately deferred, not part of Wordle's own build.

