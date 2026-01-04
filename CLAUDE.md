# Sunwise Project

A Vue 3 plant care app with PHP backend and SQLite database.

## Project Structure

- `frontend/` - Vue 3 + Vite + TailwindCSS
- `api/` - PHP REST API
- `api/migrations/` - Database migrations (run automatically)

## Development

```bash
cd frontend && npm run dev
```

## Skills

### /deploy

Deploy the app to production. Usage:

```
/deploy <version> [commit message]
```

Examples:
- `/deploy 0.6.1` - Deploy version 0.6.1 with auto-generated commit message
- `/deploy 0.6.1 Fix task filtering bug` - Deploy with custom commit message

This skill will:
1. Update version in `frontend/src/config.js` and `frontend/package.json`
2. Build the frontend
3. Commit and push to GitHub
4. Deploy frontend to production server
5. Deploy any modified API files
6. Run migrations if needed
7. Clean up old asset files

## Server Details

- Host: dereka328.sg-host.com
- Web root: `~/www/dereka328.sg-host.com/public_html/`
- Database: SQLite at `../data/sunwise.db`
