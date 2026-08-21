# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)

## Tasks

### 10. Spec and build NYT-style puzzle games

Add Wordle, Pips, Spelling Bee, Strands, and Connections. All need daily/random puzzle content and, since there's no licensed puzzle source, that content will most likely need to be AI-generated rather than hand-authored (unlike Best Match's hand-written card set, this isn't a fixed pool — new puzzles are needed continuously). Each game needs its own build spec, written the same skeleton-first way `docs/specs/clear-the-deck.md` was (see `docs/DEVLOG.md`'s Clear the Deck section), before any building starts. Once built, wire each into the shared puzzle-code/daily-puzzle/Challenge-a-Friend infrastructure Task 11 built for Boggle and Word Blocks (see `docs/DEVLOG.md`'s "Boggle" and "Word Blocks" sections): add the game to `GameType::isChallengeable()`, generate its seed via `App\Services\Puzzles\DailyPuzzle::seedFor()`, and add its own link column to the `/daily-puzzles` archive page.

