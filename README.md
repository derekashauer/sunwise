# Sunwise - Personal Plant Manager

A mobile-first PWA for managing houseplants with AI-powered care plans.

## Quick Start

### Prerequisites
- Node.js 18+
- PHP 8.0+
- Claude API key (for AI features)

### Local Development

1. **Install dependencies:**
   ```bash
   cd frontend
   npm install
   ```

2. **Configure environment:**
   ```bash
   cp .env.example .env
   # Edit .env and add your Claude API key
   ```

3. **Start development servers:**

   Terminal 1 - Frontend:
   ```bash
   cd frontend
   npm run dev
   ```

   Terminal 2 - Backend:
   ```bash
   cd api
   php -S localhost:8080
   ```

4. **Open http://localhost:5173**

### Deployment

See [DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md) for deployment instructions.

## Features

- **Plant Management** - Add plants with photos, AI auto-identifies species with Wikipedia links
- **AI Care Plans** - Personalized care schedules showing dates with relative time (e.g., "Jan 4 - water (2 days)")
- **What to Do Today** - Daily task dashboard with plant photos and names
- **Location Management** - Organize plants by room/location, filter by location
- **Sitter Mode** - Shareable care instructions for plant sitters
- **Push Notifications** - Reminders for watering and care tasks
- **Offline Support** - PWA works without internet
- **iPhone Photo Support** - Automatic EXIF orientation correction for camera uploads

## Tech Stack

- **Frontend:** Vue 3 + Vite + Tailwind CSS
- **Backend:** PHP 8 (vanilla, no framework)
- **Database:** SQLite
- **AI:** Claude API
- **PWA:** Workbox
