-- ScanPlay Referral System Database Schema
-- Migration: 09_referral_system.sql

ALTER TABLE users ADD COLUMN referrer_id TEXT;
ALTER TABLE users ADD COLUMN referral_code TEXT UNIQUE;
ALTER TABLE users ADD COLUMN referral_projects_earned INTEGER DEFAULT 0;

CREATE TABLE IF NOT EXISTS referrals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  referrer_id TEXT NOT NULL,
  referee_id TEXT NOT NULL,
  referral_code TEXT,
  status TEXT DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME,
  UNIQUE(referrer_id, referee_id),
  FOREIGN KEY(referrer_id) REFERENCES users(id),
  FOREIGN KEY(referee_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS referral_popups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT NOT NULL,
  shown_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  dismissed_at DATETIME,
  action TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

-- Add indices for common queries
CREATE INDEX IF NOT EXISTS idx_referrals_referrer ON referrals(referrer_id);
CREATE INDEX IF NOT EXISTS idx_referrals_referee ON referrals(referee_id);
CREATE INDEX IF NOT EXISTS idx_referrals_status ON referrals(status);
CREATE INDEX IF NOT EXISTS idx_referral_code ON users(referral_code);
