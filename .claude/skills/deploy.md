# Deploy Skill

Deploy Sunwise to production with proper versioning and cleanup.

## Arguments
- `version` (required): The version number (e.g., "0.6.1")
- Additional text after version is used as commit message

## Steps to Execute

### 1. Parse Arguments
Extract version from args. If additional text provided, use as commit message. Otherwise generate from git diff.

### 2. Update Version Numbers

Edit `frontend/src/config.js`:
```javascript
export const APP_VERSION = '<version>'
```

Edit `frontend/package.json` version field to match.

### 3. Build Frontend
```bash
cd /Users/derekashauer/Dropbox/sunwise/sunwise.dev/frontend
rm -rf dist
npm run build
```

### 4. Create Deployment Package
```bash
cd /Users/derekashauer/Dropbox/sunwise/sunwise.dev/frontend
rm -f sunwise-dist.zip
zip -r sunwise-dist.zip dist/
```

### 5. Git Commit and Push
```bash
cd /Users/derekashauer/Dropbox/sunwise/sunwise.dev
git add -A
git status
```

Create commit with message format:
```
v<VERSION>: <description>

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
```

Then push:
```bash
git push origin main
```

### 6. Deploy Frontend to Server

Upload and deploy:
```bash
SSHPASS='yzr5vup9tre_qje5QFB' sshpass -e scp -o StrictHostKeyChecking=no -P 18765 \
  /Users/derekashauer/Dropbox/sunwise/sunwise.dev/frontend/sunwise-dist.zip \
  u2925-zcqkhbjywpqt@ssh.dereka328.sg-host.com:~/www/dereka328.sg-host.com/

SSHPASS='yzr5vup9tre_qje5QFB' sshpass -e ssh -o StrictHostKeyChecking=no -p 18765 \
  u2925-zcqkhbjywpqt@ssh.dereka328.sg-host.com \
  'cd ~/www/dereka328.sg-host.com/public_html && \
   rm -rf assets sw.js workbox-*.js registerSW.js && \
   mkdir -p assets && \
   cd ~/www/dereka328.sg-host.com && \
   unzip -o sunwise-dist.zip && \
   cp -r dist/* public_html/ && \
   rm -rf dist sunwise-dist.zip && \
   ls public_html/assets/*.js 2>/dev/null | wc -l'
```

### 7. Deploy Modified API Files

Check which API files were modified:
```bash
git diff --name-only HEAD~1 HEAD -- api/
```

For each modified file, upload it:
```bash
SSHPASS='yzr5vup9tre_qje5QFB' sshpass -e scp -o StrictHostKeyChecking=no -P 18765 \
  /Users/derekashauer/Dropbox/sunwise/sunwise.dev/api/<path> \
  u2925-zcqkhbjywpqt@ssh.dereka328.sg-host.com:~/www/dereka328.sg-host.com/public_html/api/<path>
```

### 8. Run Database Migrations

**ALWAYS run migrations** to ensure database schema is up to date. The migration system is idempotent (safe to run multiple times).

```bash
SSHPASS='yzr5vup9tre_qje5QFB' sshpass -e ssh -o StrictHostKeyChecking=no -p 18765 \
  u2925-zcqkhbjywpqt@ssh.dereka328.sg-host.com \
  'cd ~/www/dereka328.sg-host.com/public_html && php -r "
require \"api/config/config.php\";
require \"api/config/database.php\";
Database::runMigrations();
echo \"Migrations complete\n\";
" 2>&1'
```

If migrations fail with "duplicate column" errors, check if schema is already up to date (this is expected for re-runs).

### 9. Verify and Report

Report to user:
- Version deployed
- Git commit hash (short)
- Number of frontend asset files (should be ~22)
- List of API files deployed
- Any errors

## Server Connection Details
- Host: ssh.dereka328.sg-host.com
- Port: 18765
- User: u2925-zcqkhbjywpqt
- Password: yzr5vup9tre_qje5QFB
- Web root: ~/www/dereka328.sg-host.com/public_html/
