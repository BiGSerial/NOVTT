# SPEC-MVP-003: Mesa narrativa (redesenho do MVP)

Status: PROPOSED from owner feedback (2026-11-22); implementation status: IMPLEMENTED (API + web
clients, local Docker); NOT VERIFIED on real devices, screen readers or Android.
Authority: SPEC-MVP-002 (this spec changes its decision D2 and extends LT-04..LT-09).

## Owner feedback (2026-11-22, summarized)

The MVP worked but read like a corporate site: a poor master screen, unintuitive selection, thin
GM tools, a Roteiro that had to be written by hand every time, no way to walk the plot through
the table, player screens without illustrations and with a thin sheet, dice that left too fast,
and no way to manage a table with 5 players and 10 NPCs. Rolls must, by default, stay between
the player and the master.

## Decisions

- **D2 (revised by the owner): rolls are private by default.** A player's official roll is seen
  only by that player and the master. The master MAY request it "à mesa" (visible to everyone) or
  reveal it afterwards. NPC rolls stay secret by default (unchanged). The API default for
  `secret` on roll requests is now `true`.
- **D5 Dice stay on the table.** After the official dice settle, they remain on screen under the
  result card until the card is dismissed (tap, Esc) or after 14 s (7 s when more results are
  queued). The card no longer covers the dice.
- **D6 Illustrations are original or the table's own.** Scene art is drawn by NOVTT (12 settings,
  recoloured by the campaign theme); portraits default to a generated heraldic medallion. Masters
  (any image) and players (their own character's portrait) MAY upload JPEG/PNG/WebP up to 4 MB;
  the server re-encodes to WebP (metadata stripped) and serves images only to members allowed to
  see them (NPC portraits after the NPC is introduced; scene images when staged; handouts when
  revealed).
- **D7 Prep follows a short, guided method** (eight steps: characters, strong start, scenes,
  secrets and clues, places, NPCs, threats, rewards — inspired by published "lazy prep" advice; no
  third-party text is copied). Prep autosaves and offers original generators for every blank.

## Requirements

### NT-01 Plot (walk the story through the table)
The session carries a plot: the roteiro's scenes with status (done / current / pending). The
master moves to the previous or next scene, jumps to any scene, or adds an improvised scene. The
compass (scene, objective, next unrevealed clue) follows the current scene. The plot is
master-only.

### NT-02 Stage (what the players see now)
The master shows a scene on the players' screens: title, text to read aloud, illustration. Players
get a "Nova cena" notice and see it on the Cena tab; the master can withdraw it.

### NT-03 Cast at scale
The master's cast lists every player character and NPC as a portrait token with a health ring,
conditions, pending-roll and turn flags. Filters: all, party, NPCs; groups: party, in scene,
reserve. Search. Click selects one; Ctrl/⌘/Shift-click or the check box builds a group. Esc
clears a group. "In scene" is window-local workspace state; advancing the plot brings the scene's
NPCs on stage.

### NT-04 Character control
One selected character opens an inspector: portrait (upload), concept, vitals with −3/−1/+1/+3,
conditions, items (grant, spend, remove), the sheet as medallions (attributes) and rated skills;
tapping an attribute and a skill composes the roll. A selected group opens a board: the same
numbers side by side, bulk damage/healing, a condition for all, a whisper to each player, one roll
request for all, initiative from the group.

### NT-05 Roll tray
Collapsed until a combination is chosen. Shows the formula and the dice preview; presets and
advanced options; a visibility switch "Entre nós" (default) / "À mesa" with a plain-language
note of who will see the result.

### NT-06 Story column
Tabs: Trama (NPCs in scene, clues of the current scene as handouts with reveal-to-all or
whisper-to-one, other clues, scene list), Crônica (rolls and revealed clues in time order, replay,
reveal), Sussurros (one thread per player with unread counts), Notas, Oráculo (yes/no with odds,
name, NPC, complication, place, weather, rumor; original tables; "Anotar" saves to notes).

### NT-07 Player phone
Hero with the scene illustration, portrait (upload), concept and vitals; the master's request as
a prominent card that states who will see the result; the sheet as a character sheet (medallions,
skills, items, who the character is); Cena (staged scene, NPCs met with portraits, the party,
initiative); Diário (clues as handouts, whispers); Rolagens.

### NT-08 Prep carry-over
"Continuar a história" starts a roteiro from what is still open: the previous session's unrevealed
clues, open Atlas threads and what each character wants (`GET /campaigns/{id}/prep/carry-over`,
master-only).

### NT-09 Look and feel
Display type Cinzel, story text Alegreya, UI Alegreya Sans (bundled, OFL). Panels with ornamental
borders, parchment handouts, themes recolour art and UI. Reduced motion honoured.

### NT-10 Atmosphere (owner request 2026-11-23)
The master sets the table atmosphere: hour of day (0–24 in quarter hours), weather (`clear`,
`clouds`, `rain`, `storm`, `fog`, `snow`), intensity (0–1), time flow (`time_scale`, game minutes
per real minute, 0 = frozen) and how long clients animate the change (`transition_seconds`, 0–30).
`PUT /sessions/{id}/atmosphere` (master only, not on ended sessions) stores it on the session with
its own `revision` and `set_at`, and emits the table event `atmosphere.updated`; the session
snapshot carries it for every member (null until set). The server stores parameters only: sky,
light, particles and animation are rendered by each client from them, and while `time_scale > 0`
clients advance the hour from `set_at` without further requests. Clients MUST honour reduced
motion (no particles or animated transitions) and SHOULD keep rendering cheap (bounded particles,
paused when hidden). The atmosphere has no rules effect.

### NT-11 Clocks: tone and visibility (owner request 2026-11-23)
Each clock carries a `tone` (`threat`, `progress`, `mystery`, `neutral`; colour and meaning on
screen) and a `visibility`: `master` (console only, the default and the value for clocks saved
before this change), `table` (every player) or `players` (only the user ids in `player_ids`; ids
that are not active players of the campaign are dropped by the server). The master creates and
edits a clock in a dialog (name, segments, tone, who sees it). Players MUST receive only the clocks
they may see, without the visibility list: the session snapshot carries them as `session.clocks`,
and the user event `clocks.updated {clocks}` is sent to a player only when their visible set
changed. Players never change clocks.

### NT-12 Master control centre (owner request 2026-11-23)
With nobody selected, the centre of the console shows the current scene's prep as a narration aid
(what to read aloud, the scene's NPCs with their traits and lines, open decisions, clues still
hidden), not an empty prompt. The side column is for refinement: notes, roll review and a single
history of everything that happened in the session (player actions and rolls). Master actions
that need more than one control open in a dialog instead of expanding inline under a panel.

### NT-13 Places: who is near whom (owner decision 2026-11-23)
The master groups characters into named places (`PUT /sessions/{id}/places`, own revision,
table event `places.updated`). Characters sharing a place are near; with no places, or when
neither is placed, everyone is together. Players see the party with far members greyed out and
every member's visible conditions.

### NT-14 Item exchange between nearby characters (owner decision 2026-11-23)
A player offers an item of their character to a nearby character; the receiver accepts or
declines, the giver may withdraw. The server checks who controls the giver, the distance and the
quantity at offer and again at acceptance, then moves the items (merged by name at the
receiver) and emits `live.updated` for both. The master sees offers in the history and may undo
an accepted one. Events `item_offer.created` / `item_offer.resolved` reach the master, the giver
and the receiver only.

### Owner decisions 2026-11-23 (later phases)
- Proximity: the master groups characters into named places; same place = near. Players see who
  is near, far members greyed as unreachable, and visible party conditions.
- Item exchange between nearby players: offered by the giver, accepted by the receiver, recorded
  in the master's history; the master can undo.
- Scene/atlas map with character pins: only the master moves pins.
- Order: (1) player portrait, clocks, dialogs, control centre; (2) proximity, party status, item
  exchange; (3) map with pins.

## Acceptance (end-to-end, demo data: 5 player characters, 10 NPCs, 5-scene roteiro)

1. A request is private by default: the roller and the master see the same faces; another player
   sees nothing.
2. After settling, the dice stay on screen with the result card until it is dismissed.
3. A whisper reaches only its recipient; a secret NPC roll reaches players only after "Revelar".
4. "Próxima cena" moves the plot and the compass; "Mostrar aos jogadores" shows that scene on the
   players' Cena tab.
5. Selecting the whole party opens the group board with bulk actions.
6. A paired tablet follows the master's selection with the same inspector.
7. Image upload rejects non-images and oversize files; players cannot fetch NPC portraits before
   the NPC is introduced, nor scene images before they are staged.
8. The master changes the atmosphere and every player's screen follows it; players cannot change it.

`sh scripts/dev.sh e2e` checks 1–4 and 6; API tests check 1, 3, 7, 8 (server side) and the plot/stage rules.

## Open

- Campaign-level theme (today per browser) needs a contract change.
- Preset-defined roll formulas (rules contract v2) remain a later decision.
- Android native client follows the same acceptance.
