# Changelog

All notable changes to Sunwise are documented in this file.

## [0.13.1] - 2026-02-10

### Fixed
- **Water propagation detection** - Fixed check tasks incorrectly showing "water propagation" for normal potted plants by using explicit integer comparison instead of truthy check
- **Plant data type normalization** - Backend now casts `is_propagation`, `has_grow_light`, and other integer fields to proper types before JSON encoding
- **Task query SQL error** - Fixed escaped quote syntax (`\'@\'`) in task queries that caused "unrecognized token" errors and prevented tasks from loading
- **OpenAI API compatibility** - Updated to use `max_completion_tokens` instead of deprecated `max_tokens` parameter for GPT-5.2, fixing species identification and care plan generation
- **Propagation task validation** - Care plan generator now blocks invalid task types for propagations (no "water" tasks for water propagations, no "change_water" for soil propagations)
- **Form validation conflict** - Added `novalidate` to plant form to prevent browser native validation from blocking submissions
- **Care plan evaluator improvements** - Now also detects plants with no care plan at all and plans missing check tasks

## [0.13.0] - 2026-02-08

### Added
- **Plant rotation setting** - Option to disable rotation tasks for plants that don't need it (hanging plants, symmetrical plants):
  - New "Needs rotation for even growth" checkbox on add/edit plant
  - AI care plans respect the setting and skip rotate tasks when disabled
  - New database column `can_rotate` on plants table
- **Rebuild care plan button** - One-tap button on plant detail page to trigger AI care plan regeneration
- **Scheduled care plan evaluations** - New weekly cron job (`/cron/evaluate-plans`) that automatically regenerates stale care plans:
  - Catches expired plans (past valid_until date)
  - Refreshes old plans (over 60 days)
  - Rebuilds depleted plans (no pending tasks remaining)
  - Updates plans when season changes
- **Auto care plan updates on check** - Check task completion now triggers automatic care plan regeneration when:
  - Soil is very dry but watering is scheduled far in the future
  - Soil is very wet with watering scheduled soon
  - Plant health is critical or pests are detected
- **Chat action loading indicator** - Loading overlay shows "Applying changes..." when applying suggested actions from AI chat

### Changed
- **Disabled task types now hidden from all views** - When a task type is disabled in settings (e.g., misting), those tasks are filtered from today's tasks, upcoming tasks, and plant task lists. Tasks remain in the database so re-enabling shows them again.
- **Care schedule section redesigned** - Shows next due dates per task type with overdue highlighting
- Light meter reading moved from check tasks to plant add/edit (where it belongs)

### Fixed
- **OpenAI species identification missing candidates** - Fixed bug where OpenAI users never saw the species picker with confidence levels (the `candidates` array was missing from the prompt)
- **PlantController INSERT missing fields** - `baseline_light_reading` and `can_rotate` were missing from the plant INSERT statement

## [0.12.0] - 2026-01-30

### Added
- **Baseline light meter reading** - Add optional foot-candle reading when creating or editing plants:
  - Helps AI optimize care schedules based on actual light measurements
  - Shows reference ranges for low, medium, bright, and direct light
  - New database field `baseline_light_reading` for plants

### Fixed
- **Propagation care plan for soil propagations** - Fixed issue where soil-based propagations (rooting medium) could incorrectly get "change water" tasks:
  - AI now explicitly knows if propagation is in water or soil
  - Soil propagations correctly get "water" tasks instead of "change_water"

## [0.11.1] - 2026-01-28

### Added
- **Light range guidance in check tasks** - When entering a light meter reading, see recommended foot-candle ranges:
  - Plant-specific ranges based on light condition (low/medium/high/full sun)
  - Shows ideal range plus too-low/acceptable/too-bright thresholds
  - Falls back to general ranges when plant light preference is unknown

## [0.11.0] - 2026-01-24

### Added
- **Smart Check Analysis** - After completing a plant check, get automatic insights:
  - Local pattern detection for immediate alerts (pests, low health, moisture extremes)
  - AI-powered analysis when concerning patterns are found
  - Insights displayed with visual indicators (urgent/warning/success/info)
  - Specific recommendations based on check data and history
- Check completion modal now shows insights before closing

## [0.10.1] - 2026-01-24

### Fixed
- Care info now uses user's configured AI provider (was hardcoded to Claude)
- Updated fertilize icon to nature-care design
- Updated check_roots icon to soil design

## [0.10.0] - 2026-01-24

### Added
- **Enhanced check tasks** - Capture structured plant health data during check tasks:
  - Moisture level (1-10 scale with dry/moist/wet indicators)
  - Light readings in foot-candles
  - Observations: new growth, yellowing leaves, brown tips, pests, dusty/dirty
  - Overall health rating (1-5)
  - Optional notes
- Time of check is recorded for context (e.g., light readings at midday vs evening)
- AI now uses check readings to optimize care plans and identify health trends

## [0.9.6] - 2026-01-24

### Fixed
- Rotate task icon now uses correct URL
- Skip task modal header now shows the task icon instead of JSON object data

## [0.9.5] - 2026-01-20

### Added
- **Bulk skip tasks** - Skip all tasks of a type at once (e.g., skip all mist tasks when it's too cold)
- "Skip All" button appears alongside "Complete All" on the dashboard when grouped by location or task type

## [0.9.4] - 2026-01-20

### Changed
- **User notes now override species defaults** - AI will follow your explicit care preferences (e.g., "water 2x/week")
- Care plan generation now calculates due dates based on when tasks were last completed, not arbitrary future dates
- Overdue tasks are now scheduled for today when regenerating care plans

### Fixed
- "Database is locked" error when updating care plans from chat - enabled WAL mode and busy timeout
- Tasks that should be overdue are now properly marked as due today

## [0.9.3] - 2026-01-19

### Fixed
- Care plan regeneration now works correctly (was failing due to missing database column)
- Added missing `light_level` column to locations table

## [0.9.2] - 2026-01-19

### Changed
- Tasks within each location are now sorted by task type (water, mist, fertilize, etc.) for a more logical workflow

## [0.9.1] - 2026-01-17

### Added
- **AI Status Banner**: Dashboard shows warnings when AI service has issues or cron jobs haven't run
- **Cron Job Monitoring**: New `/cron/status` endpoint for tracking cron job execution history
- Cron jobs now log execution with success/failure tracking

### Changed
- Improved care schedule updates from AI chat - now directly updates task frequency instead of regenerating entire care plan
- PlantShareView now handles route params more robustly with watchers
- Better error handling for non-JSON responses in share view

### Fixed
- Care plan generation properly passes userId for AI logging attribution

## [0.9.0] - 2026-01-14

### Added
- **Species Care Guides**: Comprehensive care information for each plant species
  - View detailed care guides from the task list (info icon next to plant name)
  - Includes light, water, humidity, temperature, soil, and fertilizer requirements
  - Shows toxicity warnings, common issues, propagation tips, and fun facts
  - Care guides are generated automatically when adding plants or confirming species
- **AI Usage Logging**: Track all AI operations with detailed activity log
  - View AI connection status in Settings (Connected/Error/Not Configured)
  - See recent AI activity with success/failure indicators
  - Error count badge for issues in the last 24 hours
  - Test AI connection button to verify API key validity
- New API endpoints for AI status monitoring (`/settings/ai/status`, `/settings/ai/log`, `/settings/ai/test`)
- Species care info endpoint (`/plants/{id}/care-info`)

### Changed
- Species confirmation is now required when adding plants (removed skip option)
- Added helpful links to Google Lens and PlantNet for plant identification
- Improved species picker UI with better guidance for manual entry
- All AI operations now log success/failure for debugging

### Fixed
- Care plan generation now properly tracks user attribution for AI logging

## [0.8.1] - 2026-01-07

### Fixed
- HouseholdController now properly returns `is_owner` flag for household ownership detection
- HouseholdController returns `is_self` flag for member list so users can identify themselves
- Updated household icons to use home icon instead of family icon

## [0.8.0] - 2026-01-07

### Added
- **Household Plant Sharing**: Invite family members or roommates to help manage your plants
  - Create households and invite members via email
  - Choose to share all plants or select specific ones
  - Members can complete tasks on shared plants
  - Attribution shows who completed each task and care action
- New HouseholdView for managing households, members, and shared plants
- Invitation acceptance flow for joining households
- Household link in Settings quick links section

### Changed
- PlantController now supports shared plant access via households
- TaskController includes shared plants in task lists with attribution
- CareLogController logs who performed each action
- TaskItem component displays who completed tasks
- CareLogEntry component shows performer attribution

## [0.7.3] - 2025-01-06

### Added
- Daily email reminder feature for care tasks

## [0.7.2] - 2025-01-05

### Changed
- Task list redesign with search and grouping
- Various bug fixes

## [0.6.1] - 2025-01-04

### Added
- Plant sharing with social preview images (OG tags)

## [0.6.0] - 2025-01-03

### Added
- Task types customization
- Pot inventory management
- Plant graveyard for archived plants
- AI model selection in settings
