# ScanPlay Referral System - Integration Guide

## Overview
This adds a viral referral system that rewards users with free projects when they refer friends.

**Flow:**
1. User creates first project
2. Popup appears: "Help a friend, get 1 free project"
3. User shares referral link via WhatsApp/Telegram/SMS
4. Friend signs up with referral code
5. Friend creates their first project
6. Original referrer gets +1 free project instantly

---

## Database Setup

### 1. Run Migration
Connect to your SQLite database and execute:

```sql
-- Add referrer_id to users table
ALTER TABLE users ADD COLUMN referrer_id TEXT;
ALTER TABLE users ADD COLUMN referral_code TEXT UNIQUE;

-- Create referrals tracking table
CREATE TABLE IF NOT EXISTS referrals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  referrer_id TEXT NOT NULL,
  referee_id TEXT NOT NULL,
  referral_code TEXT,
  status TEXT DEFAULT 'pending', -- pending, completed, rewarded
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME,
  UNIQUE(referrer_id, referee_id),
  FOREIGN KEY(referrer_id) REFERENCES users(id),
  FOREIGN KEY(referee_id) REFERENCES users(id)
);

-- Track referral popup impressions
CREATE TABLE IF NOT EXISTS referral_popups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT NOT NULL,
  shown_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  dismissed_at DATETIME,
  action TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

-- Add referral bonus projects counter
ALTER TABLE users ADD COLUMN referral_projects_earned INTEGER DEFAULT 0;
```

**Note:** If columns already exist, you'll get an error—that's fine. The `IF NOT EXISTS` on tables handles re-runs safely.

---

## Backend Setup

### 2. Add referral-api.php
Place `referral-api.php` in your ScanPlay root (same level as `api.php`).

This handles:
- `action=init_referral_code` — Generate referral code for new users
- `action=track_signup` — Track referral on signup (via `?ref=CODE`)
- `action=mark_referral_completed` — Award bonus when referee creates first project
- `action=get_referral_stats` — Get user's referral stats
- `action=show_popup` — Check if popup should display
- `action=log_popup_action` — Log popup interactions

**No config changes needed** — uses same DB connection as your existing `config.php`.

---

## Frontend Integration

### 3. Add referral-popup.js
Place `referral-popup.js` in your `/assets` folder.

### 4. Initialize Popup in Your App

Add to **studio.html** (or wherever users create projects):

```html
<!-- Before closing </body> -->
<script src="/assets/referral-popup.js"></script>
<script>
    // Initialize referral system when user creates a project
    const referralPopup = new ReferralPopup({
        userId: USER_ID,  // Your current user ID
        projectCount: TOTAL_PROJECTS_FOR_USER,
        apiUrl: '/referral-api.php',
        autoInit: true
    });
</script>
```

### 5. Track Signup Referral

In your **signup/registration page**, capture the `ref` parameter:

```javascript
// Get referral code from URL
const urlParams = new URLSearchParams(window.location.search);
const referralCode = urlParams.get('ref');

// After user signs up, track it
if (referralCode) {
    const formData = new FormData();
    formData.append('action', 'track_signup');
    formData.append('user_id', newUserId);
    formData.append('referral_code', referralCode);
    
    fetch('/referral-api.php', { method: 'POST', body: formData });
}
```

### 6. Award Referral Bonus on First Project

When user **completes their first project**, call:

```javascript
// After creating first project successfully
const formData = new FormData();
formData.append('action', 'mark_referral_completed');
formData.append('user_id', USER_ID);

fetch('/referral-api.php', { method: 'POST', body: formData });
```

This will:
- ✅ Find any pending referral for this user
- ✅ Mark it as completed
- ✅ Award +1 free project to referrer
- ✅ Update their `projects_remaining` balance

---

## Testing Checklist

- [ ] Database tables created successfully
- [ ] `referral-api.php` accessible at `/referral-api.php?action=show_popup`
- [ ] Referral popup shows after creating 1st project
- [ ] Popup can be dismissed with close button or "Maybe Later"
- [ ] Copy link button works
- [ ] WhatsApp share link generates correctly
- [ ] Referral code persists in localStorage
- [ ] New user signup with `?ref=CODE` logs referral
- [ ] First project by referee triggers bonus award
- [ ] Referrer's `projects_remaining` increases by 1

---

## Analytics & Monitoring

View referral performance:

```php
// Get all referrals
SELECT 
    r.referrer_id,
    COUNT(*) as total_referrals,
    SUM(CASE WHEN r.status IN ('completed', 'rewarded') THEN 1 ELSE 0 END) as converted
FROM referrals r
GROUP BY r.referrer_id
ORDER BY converted DESC;

// Get referral by status
SELECT status, COUNT(*) FROM referrals GROUP BY status;

// Most active referrers
SELECT 
    u.email,
    COUNT(r.id) as referral_count,
    u.referral_projects_earned
FROM referrals r
JOIN users u ON r.referrer_id = u.id
WHERE r.status IN ('completed', 'rewarded')
GROUP BY r.referrer_id
ORDER BY referral_count DESC
LIMIT 10;
```

---

## Optional Enhancements

### Leaderboard
```php
// Show top referrers
SELECT u.email, u.referral_projects_earned, 
       COUNT(r.id) as referral_count
FROM users u
LEFT JOIN referrals r ON u.id = r.referrer_id AND r.status = 'rewarded'
WHERE u.referral_projects_earned > 0
GROUP BY u.id
ORDER BY u.referral_projects_earned DESC
LIMIT 20;
```

### Email Notification to Referrer
When `mark_referral_completed` succeeds, send email:
```
Subject: 🎉 Referral Bonus Unlocked!
Your friend just created their first ScanPlay project. 
You now have 1 extra free project! Start creating at scanplay.in
```

### Show Stats in User Profile
```javascript
// Get referral stats
const stats = await referralPopup.getStats();
console.log(`You've earned ${stats.projects_earned} free projects from ${stats.completed} referrals`);
```

---

## Files Modified/Added

- ✅ `/referral-api.php` — NEW backend API
- ✅ `/assets/referral-popup.js` — NEW frontend component
- 📝 `studio.html` — Add initialization script
- 📝 `index.html` (signup) — Track referral parameter
- 📝 API project creation endpoint — Call `mark_referral_completed`

---

## Deployment Steps

1. **Create database migration file** (e.g., `migrations/09_referral_system.sql`)
2. **Run migration** on production database
3. **Push files to GitHub** (referral-api.php, referral-popup.js)
4. **Trigger Hostinger Git deploy webhook**
5. **Test with referral link:** `scanplay.in?ref=TESTCODE`

---

## Troubleshooting

**Popup not showing?**
- Check browser console for errors
- Verify `referral-api.php?action=show_popup&user_id=X` returns `{"show_popup": true}`
- Ensure `project_count` >= 1

**Referral code not generating?**
- Check `users.referral_code` column exists
- Verify `init_referral_code` endpoint works

**Bonus not awarded?**
- Check `referral_code` was properly stored on signup
- Verify `mark_referral_completed` is called after first project
- Check database logs for referral status

---

## Success Metrics to Track

- % of users shown referral popup
- % of popup dismissals vs shares
- Click-through rate on referral links
- Conversion rate (referee → first project)
- Average referrals per active user
- Projects earned via referrals per user
