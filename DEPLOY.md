# Deploy to Railway – Step-by-Step Guide (No Docker)

Railway uses **Nixpacks** to auto-detect your PHP project and build it automatically — no Docker required.

---

## What You Need Before Starting

| Requirement | Link |
|---|---|
| GitHub account | https://github.com |
| Railway account (free) | https://railway.app |
| Git installed on your computer | https://git-scm.com |

---

## Step 1 – Prepare Your Project

These config files are **already included** in your project:

| File | Purpose |
|---|---|
| `nixpacks.toml` | Tells Railway to use PHP 8.2 and run `composer install` |
| `Procfile` | Tells Railway how to start the PHP server |
| `.gitignore` | Excludes `vendor/` and uploaded log files from Git |

You don't need to create anything — just move to Step 2.

---

## Step 2 – Push Project to GitHub

Open your terminal in the project folder and run:

```bash
# 1. Initialize git (skip if already done)
git init

# 2. Stage all files
git add .

# 3. First commit
git commit -m "Initial commit – Attendance Report System"

# 4. Create a new repo on GitHub (do this on github.com first),
#    then connect it:
git remote add origin https://github.com/YOUR_USERNAME/attendance-report-system.git

# 5. Push to GitHub
git push -u origin main
```

> Go to **https://github.com/new** to create the repository first, then copy the remote URL it gives you.

---

## Step 3 – Create a Railway Account

1. Go to **https://railway.app**
2. Click **Login** → **Login with GitHub**
3. Authorize Railway to access your GitHub account

---

## Step 4 – Create a New Railway Project

1. On the Railway dashboard, click **New Project**
2. Select **Deploy from GitHub repo**
3. Find and click your **`attendance-report-system`** repository
4. Railway will immediately start **detecting and building** your project

---

## Step 5 – Watch the Build

Railway will automatically:

```
→ Detect PHP via composer.json
→ Install PHP 8.2 via Nixpacks
→ Run: composer install --no-dev --optimize-autoloader
→ Create uploads/ directory
→ Start: php -S 0.0.0.0:$PORT
```

Click the **Build Logs** tab to watch it happen in real time.

If the build succeeds you'll see:
```
✓ Build succeeded
✓ Deployment live
```

---

## Step 6 – Get Your Public URL

1. Go to your project in Railway
2. Click on the **service card** (your app)
3. Go to the **Settings** tab → **Networking**
4. Click **Generate Domain**
5. Railway gives you a free URL like:
   ```
   https://attendance-report-system-production.up.railway.app
   ```

Click it — your app is live! 🎉

---

## Step 7 – Test Everything

| Test | Expected result |
|---|---|
| Open the URL | Upload dropzone appears |
| Upload a `.txt` log file | Success alert, table appears |
| Apply filters | Table updates correctly |
| Export PDF | PDF downloads |
| Click Clear | File removed, page resets |

---

## Step 8 – Future Updates (Auto-Deploy)

Every time you push to GitHub, Railway **automatically redeploys**:

```bash
# Make your code changes, then:
git add .
git commit -m "your change description"
git push
```

Railway picks it up within seconds.

---

## ⚠️ Important Note: File Storage on Railway

Railway's filesystem is **ephemeral** — uploaded files are deleted when the app restarts or redeploys.

This means:
- After a redeployment, users must re-upload their log file
- This is fine for a **demo / office tool** used in a single session
- For permanent storage, you would need an external service like **AWS S3** or **Cloudflare R2** (not required for basic use)

---

## Troubleshooting

### Build fails with "composer not found"
Make sure `nixpacks.toml` is committed and pushed:
```bash
git add nixpacks.toml Procfile .gitignore
git commit -m "Add Railway config"
git push
```

### App shows blank page
Check Railway **Deploy Logs** → look for PHP errors.
Common fix: make sure `index.php` is in the **root** of your repo (not in a subfolder).

### "Permission denied" on uploads/
The `Procfile` runs `mkdir -p uploads` before starting PHP — this handles it automatically.

### PDF export doesn't work
Dompdf requires the `mbstring` and `dom` PHP extensions.
Add to `nixpacks.toml` if needed:
```toml
[phases.setup]
nixPkgs = ["php82", "php82Packages.composer", "php82Extensions.mbstring", "php82Extensions.dom"]
```

---

## Quick Reference – All Commands

```bash
# First time setup
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/YOUR_USERNAME/REPO_NAME.git
git push -u origin main

# After any code change
git add .
git commit -m "describe what you changed"
git push
```

That's it — Railway handles everything else automatically.
