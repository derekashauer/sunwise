# Sunwise - Personal Plant Manager

## Overview
A mobile-first PWA for managing houseplants with AI-powered care plans, hosted on shared hosting (SiteGround).

## Tech Stack
| Layer | Technology |
|-------|------------|
| Frontend | Vue 3 (Composition API) + Vite + Tailwind CSS |
| Backend | Vanilla PHP API |
| Database | SQLite |
| AI | Claude API (Vision + Text) |
| PWA | Workbox + vite-plugin-pwa |
| Auth | Email/Password + Magic Links (JWT) |
| Deploy | Git-based auto-deploy |

---

## Directory Structure

```
sunwise.dev/
├── frontend/                    # Vue 3 SPA
│   ├── src/
│   │   ├── components/
│   │   │   ├── common/          # Button, Input, Modal, Toast
│   │   │   ├── plants/          # PlantCard, PlantForm, PlantGallery
│   │   │   ├── tasks/           # TaskList, TaskItem, TaskCalendar
│   │   │   └── sitter/          # SitterSetup, SitterView
│   │   ├── views/
│   │   │   ├── DashboardView.vue    # "What to do today"
│   │   │   ├── PlantsView.vue       # Plant list
│   │   │   ├── PlantDetailView.vue  # Single plant + care plan
│   │   │   ├── AddPlantView.vue     # Add/edit plant
│   │   │   ├── SitterSetupView.vue  # Create sitter link
│   │   │   ├── SitterGuestView.vue  # Guest view (no auth)
│   │   │   ├── LoginView.vue
│   │   │   └── SettingsView.vue
│   │   ├── stores/              # Pinia stores
│   │   │   ├── auth.js
│   │   │   ├── plants.js
│   │   │   ├── tasks.js
│   │   │   └── offline.js
│   │   ├── composables/
│   │   │   ├── useApi.js        # API wrapper with offline queue
│   │   │   ├── useOffline.js    # Offline detection + sync
│   │   │   └── useNotifications.js
│   │   ├── router/
│   │   ├── assets/
│   │   └── App.vue
│   ├── public/
│   │   └── icons/               # PWA icons
│   ├── vite.config.js
│   └── package.json
│
├── api/                         # PHP Backend
│   ├── index.php                # Entry point + router
│   ├── config/
│   │   ├── config.php           # Environment config
│   │   └── database.php         # SQLite connection
│   ├── controllers/
│   │   ├── AuthController.php
│   │   ├── PlantController.php
│   │   ├── TaskController.php
│   │   ├── CarePlanController.php
│   │   ├── PhotoController.php
│   │   ├── SitterController.php
│   │   └── NotificationController.php
│   ├── services/
│   │   ├── ClaudeService.php    # AI integration
│   │   ├── ImageService.php     # Resize, optimize
│   │   ├── PushService.php      # Web push notifications
│   │   └── EmailService.php     # Magic links
│   ├── middleware/
│   │   ├── AuthMiddleware.php
│   │   └── CorsMiddleware.php
│   ├── models/
│   │   ├── User.php
│   │   ├── Plant.php
│   │   ├── Task.php
│   │   ├── CarePlan.php
│   │   ├── Photo.php
│   │   └── SitterSession.php
│   └── migrations/
│       └── 001_initial.sql
│
├── uploads/                     # Plant photos (gitignored)
│   └── plants/
│
├── data/                        # SQLite database (gitignored)
│   └── sunwise.db
│
├── .htaccess                    # Route API + SPA fallback
├── deploy.sh                    # Git pull + build script
└── .github/
    └── workflows/
        └── deploy.yml           # GitHub Actions deploy
```

---

## Database Schema (SQLite)

```sql
-- Users
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT,
    magic_token TEXT,
    magic_token_expires DATETIME,
    push_subscription TEXT,          -- JSON for web push
    timezone TEXT DEFAULT 'America/New_York',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Plants
CREATE TABLE plants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    species TEXT,                    -- AI-identified
    species_confidence REAL,         -- AI confidence 0-1
    pot_size TEXT,                   -- small, medium, large, xlarge
    soil_type TEXT,                  -- standard, succulent, orchid, etc.
    light_condition TEXT,            -- low, medium, bright, direct
    location TEXT,                   -- user-defined room/spot
    acquired_date DATE,
    notes TEXT,
    health_status TEXT DEFAULT 'unknown',  -- thriving, healthy, struggling, critical
    last_health_check DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Photos (multiple per plant for health history)
CREATE TABLE photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    thumbnail TEXT,                  -- Smaller version for lists
    ai_analysis TEXT,                -- JSON: health assessment from Claude
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Care Plans (AI-generated, one active per plant)
CREATE TABLE care_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    valid_until DATE,                -- Regenerate seasonally
    season TEXT,                     -- spring, summer, fall, winter
    ai_reasoning TEXT,               -- Why AI made these recommendations
    next_photo_check DATE,           -- When AI wants new photo
    photo_check_reason TEXT,         -- Why AI requested it
    is_active BOOLEAN DEFAULT 1,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Tasks (individual care actions)
CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    care_plan_id INTEGER NOT NULL,
    plant_id INTEGER NOT NULL,
    task_type TEXT NOT NULL,         -- water, fertilize, trim, repot, rotate, mist, check
    due_date DATE NOT NULL,
    recurrence TEXT,                 -- JSON: {type: 'days', interval: 7}
    instructions TEXT,               -- AI-generated specific instructions
    priority TEXT DEFAULT 'normal',  -- low, normal, high, urgent
    completed_at DATETIME,
    skipped_at DATETIME,
    skip_reason TEXT,
    notes TEXT,                      -- User notes on completion
    FOREIGN KEY (care_plan_id) REFERENCES care_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Care Log (historical record for AI context)
CREATE TABLE care_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plant_id INTEGER NOT NULL,
    task_id INTEGER,
    action TEXT NOT NULL,            -- watered, fertilized, trimmed, etc.
    performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    photo_id INTEGER,                -- Optional linked photo
    outcome TEXT,                    -- positive, neutral, negative (updated later)
    FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
);

-- Sitter Sessions
CREATE TABLE sitter_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT UNIQUE NOT NULL,      -- Cryptographic random token
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    sitter_name TEXT,
    instructions TEXT,               -- General instructions from owner
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    accessed_at DATETIME,            -- Last access
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Sitter Plants (which plants are in session)
CREATE TABLE sitter_plants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    plant_id INTEGER NOT NULL,
    custom_instructions TEXT,        -- Per-plant override
    FOREIGN KEY (session_id) REFERENCES sitter_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (plant_id) REFERENCES plants(id)
);

-- Indexes
CREATE INDEX idx_tasks_due ON tasks(due_date, completed_at);
CREATE INDEX idx_plants_user ON plants(user_id);
CREATE INDEX idx_care_log_plant ON care_log(plant_id, performed_at);
CREATE INDEX idx_sitter_token ON sitter_sessions(token);
```

---

## API Endpoints

### Auth
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Create account |
| POST | `/api/auth/login` | Email/password login |
| POST | `/api/auth/magic-link` | Request magic link |
| GET | `/api/auth/verify/:token` | Verify magic link |
| POST | `/api/auth/refresh` | Refresh JWT |
| POST | `/api/auth/logout` | Invalidate session |

### Plants
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/plants` | List user's plants |
| POST | `/api/plants` | Add plant (with image) |
| GET | `/api/plants/:id` | Get plant details |
| PUT | `/api/plants/:id` | Update plant |
| DELETE | `/api/plants/:id` | Delete plant |
| POST | `/api/plants/:id/photo` | Upload new photo |
| GET | `/api/plants/:id/photos` | Get photo history |

### Tasks
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tasks/today` | Today's tasks |
| GET | `/api/tasks/upcoming` | Next 7 days |
| GET | `/api/tasks/plant/:id` | Tasks for plant |
| POST | `/api/tasks/:id/complete` | Mark complete |
| POST | `/api/tasks/:id/skip` | Skip with reason |

### Care Plans
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/plants/:id/care-plan` | Get active care plan |
| POST | `/api/plants/:id/care-plan/regenerate` | Force regenerate |
| GET | `/api/plants/:id/care-log` | Get care history |

### Sitter Mode
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/sitter/create` | Create sitter session |
| GET | `/api/sitter/:token` | Guest: Get session (no auth) |
| POST | `/api/sitter/:token/task/:id` | Guest: Complete task |

### AI
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/ai/identify` | Identify plant from image |
| POST | `/api/ai/health-check` | Analyze plant health |

### Notifications
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/notifications/subscribe` | Save push subscription |
| DELETE | `/api/notifications/unsubscribe` | Remove subscription |

---

## AI Integration Strategy

### 1. Plant Identification (on add)
**Trigger:** User uploads first photo when adding plant
**Claude Prompt:**
```
Analyze this plant photo and identify:
1. Species/common name (with confidence level)
2. Current health assessment
3. Any visible issues (pests, disease, nutrient deficiency)
4. Estimated age/maturity

Respond in JSON format.
```

### 2. Care Plan Generation
**Trigger:** After plant added, seasonally (every 3 months), or on-demand
**Context sent to Claude:**
- Plant species + conditions (pot, soil, light)
- Current season + user timezone
- Recent photos with health history
- Last 30 days of care log
- What worked/didn't (outcome data)

**Claude Prompt:**
```
Generate a care plan for this {species} plant.

Current conditions:
- Pot size: {pot_size}
- Soil: {soil_type}
- Light: {light_condition}
- Location: {location}
- Season: {season}
- Health: {health_status}

Recent care history:
{care_log_summary}

Health trend: {improving/stable/declining}

Generate specific tasks with:
1. Watering schedule (frequency, amount)
2. Fertilizing schedule (type, dilution, frequency)
3. Other care (misting, rotating, pruning)
4. Any corrective actions needed
5. When to request next health photo

Consider seasonal adjustments. Respond in JSON.
```

### 3. Health Check Requests
**Trigger:** Cron job checks `care_plans.next_photo_check`
**Action:** Send push notification asking for photo
**If plant struggling:** More frequent checks (every 3-5 days vs every 2-4 weeks)

### 4. Task Completion Learning
When user completes task with notes or skips with reason, log it. AI uses this context to adjust future recommendations.

---

## PWA Implementation

### Service Worker (Workbox via vite-plugin-pwa)
```javascript
// vite.config.js
import { VitePWA } from 'vite-plugin-pwa'

export default {
  plugins: [
    VitePWA({
      registerType: 'autoUpdate',
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/api\.sunwise\.dev\/api\//,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-cache',
              expiration: { maxEntries: 100, maxAgeSeconds: 86400 }
            }
          },
          {
            urlPattern: /\/uploads\/plants\//,
            handler: 'CacheFirst',
            options: {
              cacheName: 'plant-images',
              expiration: { maxEntries: 200, maxAgeSeconds: 604800 }
            }
          }
        ]
      },
      manifest: {
        name: 'Sunwise Plant Manager',
        short_name: 'Sunwise',
        theme_color: '#22c55e',
        background_color: '#f0fdf4',
        display: 'standalone',
        icons: [/* icon sizes */]
      }
    })
  ]
}
```

### Offline Support (Dexie.js for IndexedDB)
```javascript
// src/stores/offline.js
import Dexie from 'dexie'

const db = new Dexie('sunwise')
db.version(1).stores({
  plants: 'id, user_id',
  tasks: 'id, plant_id, due_date',
  syncQueue: '++id, action, endpoint, payload'
})

// Queue actions when offline, sync when back online
```

### Push Notifications (VAPID)
- Generate VAPID keys (one-time setup)
- Store subscription in `users.push_subscription`
- PHP sends via `web-push/web-push` library
- Cron job triggers notifications for due tasks

---

## Sitter Mode Security

1. **Token Generation:** 32-byte cryptographically random string (64 hex chars)
2. **URL Format:** `https://sunwise.dev/sitter/{token}`
3. **Scoped Access:** Token only exposes:
   - Plant names, photos, locations
   - Tasks within date range
   - Ability to mark tasks complete
4. **No Sensitive Data:** No user email, no care history, no AI analysis details
5. **Expiration:** Auto-expires after `end_date + 1 day`
6. **Rate Limiting:** Max 100 requests/hour per token

---

## Deployment Strategy (Git-based)

### Setup (One-time on SiteGround)
1. SSH into hosting, set up bare git repo
2. Configure post-receive hook to pull and build

### Deploy Script (`deploy.sh`)
```bash
#!/bin/bash
cd /home/user/sunwise.dev

# Pull latest
git pull origin main

# Install frontend deps and build
cd frontend
npm ci
npm run build

# Copy built files to public
cp -r dist/* ../public_html/

# Run any PHP migrations
cd ../api
php migrate.php
```

### GitHub Actions (`.github/workflows/deploy.yml`)
```yaml
name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Deploy via SSH
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.HOST }}
          username: ${{ secrets.USER }}
          key: ${{ secrets.SSH_KEY }}
          script: |
            cd ~/sunwise.dev && ./deploy.sh
```

### Local Development
```bash
# Terminal 1: Frontend dev server
cd frontend && npm run dev

# Terminal 2: PHP dev server
cd api && php -S localhost:8080

# Frontend proxies /api to PHP server (vite.config.js proxy)
```

---

## Enhancement Suggestions

### High Value
1. **Plant Health Timeline** - Visual graph showing health over time with care events overlaid
2. **Watering Reminder Widgets** - iOS/Android home screen widgets via PWA
3. **Plant Community ID** - When AI uncertain, option to ask community (future)
4. **Seasonal Prep Alerts** - "Winter is coming, here's how to prepare your plants"
5. **Propagation Tracking** - Track cuttings/babies from parent plants

### Medium Value
6. **Multiple Locations** - Manage plants across home, office, etc.
7. **Plant Wishlist** - Save plants you want, get care preview
8. **Care Streaks** - Gamification: "15-day watering streak!"
9. **Export Care Log** - PDF report for plant history
10. **Dark Mode** - With plant-friendly green accents

### Nice to Have
11. **Plant Value Tracking** - Track investment in plants
12. **Weather Integration** - Adjust care based on local weather (humidity, temp)
13. **QR Codes** - Print QR labels for quick plant access
14. **Voice Commands** - "Hey Sunwise, what needs water today?"

---

## Implementation Phases

### Phase 1: Foundation
- [ ] Project scaffolding (Vue 3 + Vite, PHP API structure)
- [ ] Database setup + migrations
- [ ] Basic auth (email/password, JWT)
- [ ] Deploy pipeline (GitHub Actions + SiteGround)
- [ ] Basic UI shell with routing

### Phase 2: Core Plants
- [ ] Add plant form with image upload
- [ ] Plant list view (cards with thumbnails)
- [ ] Plant detail view
- [ ] Photo upload + gallery
- [ ] Image optimization service

### Phase 3: AI Integration
- [ ] Claude API service (PHP)
- [ ] Plant identification on add
- [ ] Health analysis from photos
- [ ] Care plan generation
- [ ] Store AI responses in DB

### Phase 4: Task System
- [ ] Task model + CRUD
- [ ] "What to do today" dashboard
- [ ] Task completion flow
- [ ] Care log recording
- [ ] Recurring task generation

### Phase 5: PWA + Notifications
- [ ] Service worker setup
- [ ] Offline data sync (Dexie)
- [ ] Push notification subscription
- [ ] Notification triggers (cron)
- [ ] Install prompts

### Phase 6: Sitter Mode
- [ ] Sitter session creation
- [ ] Secure token generation
- [ ] Guest view (no auth)
- [ ] Guest task completion
- [ ] Session expiration

### Phase 7: Polish
- [ ] Magic link auth
- [ ] Settings page
- [ ] Error handling + toasts
- [ ] Loading states
- [ ] Mobile gestures
- [ ] Performance optimization

---

## Key Files to Create First

1. `frontend/package.json` - Vue 3 + Vite + Pinia + dependencies
2. `frontend/vite.config.js` - Build config with PWA plugin + API proxy
3. `api/index.php` - Router entry point
4. `api/config/config.php` - Environment configuration
5. `api/config/database.php` - SQLite connection singleton
6. `api/migrations/001_initial.sql` - Full schema
7. `.htaccess` - Route API + SPA fallback
8. `deploy.sh` - Deployment script
9. `.github/workflows/deploy.yml` - CI/CD pipeline

---

## Environment Variables

```php
// api/config/config.php
return [
    'db_path' => __DIR__ . '/../../data/sunwise.db',
    'jwt_secret' => getenv('JWT_SECRET'),
    'claude_api_key' => getenv('CLAUDE_API_KEY'),
    'vapid_public' => getenv('VAPID_PUBLIC_KEY'),
    'vapid_private' => getenv('VAPID_PRIVATE_KEY'),
    'app_url' => getenv('APP_URL') ?: 'https://sunwise.dev',
    'upload_path' => __DIR__ . '/../../uploads',
];
```

Set via SiteGround's environment variables or `.env` file (gitignored).

---

## Cron Jobs (SiteGround)

Set up via SiteGround's Site Tools > Cron Jobs:

### Every Hour
```
0 * * * * /usr/bin/php /home/user/sunwise.dev/api/cron/hourly.php
```
**Tasks:**
- Check for due task notifications (send push)
- Process notification queue

### Daily at 7 AM (User's Timezone)
```
0 7 * * * /usr/bin/php /home/user/sunwise.dev/api/cron/daily.php
```
**Tasks:**
- Send "What to do today" morning digest
- Check for photo check-in requests from AI
- Expire old sitter sessions

### Weekly (Sunday midnight)
```
0 0 * * 0 /usr/bin/php /home/user/sunwise.dev/api/cron/weekly.php
```
**Tasks:**
- Regenerate care plans if season changed
- Clean up old uploads
- Database maintenance (VACUUM)

### Cron Files Structure
```
api/cron/
├── hourly.php      # Notification processing
├── daily.php       # Morning digest + AI check-ins
├── weekly.php      # Maintenance + seasonal updates
└── CronBase.php    # Shared utilities, logging
```
