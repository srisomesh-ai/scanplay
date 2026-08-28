<?php
/**
 * ScanPlay Referral System API
 * Handles referral tracking, rewards, and analytics
 */

require_once 'config.php';

header('Content-Type: application/json');

// Helper function to generate referral code
function generateReferralCode($user_id) {
    return strtoupper(substr(hash('sha256', $user_id . time()), 0, 8));
}

// Route handler
$action = $_REQUEST['action'] ?? null;

switch ($action) {
    
    case 'init_referral_code':
        /**
         * Initialize referral code for new/existing user
         * POST: user_id
         */
        $user_id = $_POST['user_id'] ?? null;
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id required']);
            exit;
        }
        
        $stmt = $db->prepare('SELECT referral_code FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user && $user['referral_code']) {
            // Already has code
            echo json_encode(['referral_code' => $user['referral_code']]);
        } else {
            // Generate new code
            $ref_code = generateReferralCode($user_id);
            $stmt = $db->prepare('UPDATE users SET referral_code = ? WHERE id = ?');
            $stmt->execute([$ref_code, $user_id]);
            echo json_encode(['referral_code' => $ref_code]);
        }
        break;

    case 'track_signup':
        /**
         * Track signup with referral code
         * POST: user_id, referral_code (optional)
         */
        $user_id = $_POST['user_id'] ?? null;
        $referral_code = $_POST['referral_code'] ?? null;
        
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id required']);
            exit;
        }
        
        if ($referral_code) {
            // Find referrer by code
            $stmt = $db->prepare('SELECT id FROM users WHERE referral_code = ?');
            $stmt->execute([$referral_code]);
            $referrer = $stmt->fetch();
            
            if ($referrer) {
                $referrer_id = $referrer['id'];
                
                // Check if referral already exists
                $stmt = $db->prepare('SELECT id FROM referrals WHERE referrer_id = ? AND referee_id = ?');
                $stmt->execute([$referrer_id, $user_id]);
                
                if (!$stmt->fetch()) {
                    // Create new referral record
                    $stmt = $db->prepare(
                        'INSERT INTO referrals (referrer_id, referee_id, referral_code, status) 
                         VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$referrer_id, $user_id, $referral_code, 'pending']);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Referral tracked',
                        'referrer_id' => $referrer_id
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Referral already exists']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid referral code']);
            }
        } else {
            echo json_encode(['success' => true, 'message' => 'No referral code']);
        }
        break;

    case 'mark_referral_completed':
        /**
         * Mark referral as completed when referee creates first project
         * POST: user_id
         */
        $user_id = $_POST['user_id'] ?? null;
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id required']);
            exit;
        }
        
        // Find pending referrals for this user
        $stmt = $db->prepare('SELECT referrer_id FROM referrals WHERE referee_id = ? AND status = ?');
        $stmt->execute([$user_id, 'pending']);
        $referral = $stmt->fetch();
        
        if ($referral) {
            $referrer_id = $referral['referrer_id'];
            
            // Update referral status
            $stmt = $db->prepare('UPDATE referrals SET status = ?, completed_at = CURRENT_TIMESTAMP WHERE referee_id = ? AND status = ?');
            $stmt->execute(['completed', $user_id, 'pending']);
            
            // Award bonus project to referrer
            $stmt = $db->prepare('UPDATE users SET projects_remaining = projects_remaining + 1, referral_projects_earned = referral_projects_earned + 1 WHERE id = ?');
            $stmt->execute([$referrer_id]);
            
            // Mark as rewarded
            $stmt = $db->prepare('UPDATE referrals SET status = ? WHERE referrer_id = ? AND referee_id = ?');
            $stmt->execute(['rewarded', $referrer_id, $user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Referral completed, bonus awarded',
                'referrer_id' => $referrer_id
            ]);
        } else {
            echo json_encode(['success' => true, 'message' => 'No pending referral']);
        }
        break;

    case 'get_referral_stats':
        /**
         * Get referral statistics for user
         * GET: user_id
         */
        $user_id = $_GET['user_id'] ?? null;
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id required']);
            exit;
        }
        
        // Get completed referrals
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM referrals WHERE referrer_id = ? AND status IN (?, ?)');
        $stmt->execute([$user_id, 'completed', 'rewarded']);
        $completed = $stmt->fetch()['count'];
        
        // Get pending referrals
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM referrals WHERE referrer_id = ? AND status = ?');
        $stmt->execute([$user_id, 'pending']);
        $pending = $stmt->fetch()['count'];
        
        // Get referral projects earned
        $stmt = $db->prepare('SELECT referral_projects_earned FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $earned = $user['referral_projects_earned'] ?? 0;
        
        // Get referral link
        $stmt = $db->prepare('SELECT referral_code FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $ref_code = $user['referral_code'] ?? null;
        
        echo json_encode([
            'completed' => $completed,
            'pending' => $pending,
            'projects_earned' => $earned,
            'referral_code' => $ref_code,
            'referral_link' => 'https://scanplay.in?ref=' . ($ref_code ?? 'NOCODE')
        ]);
        break;

    case 'show_popup':
        /**
         * Check if should show referral popup for user
         * GET: user_id, project_count
         */
        $user_id = $_GET['user_id'] ?? null;
        $project_count = (int)($_GET['project_count'] ?? 0);
        
        if (!$user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id required']);
            exit;
        }
        
        $show_popup = false;
        
        // Show after 1 project created
        if ($project_count >= 1) {
            // Check if already shown today
            $stmt = $db->prepare(
                'SELECT COUNT(*) as count FROM referral_popups 
                 WHERE user_id = ? AND DATE(shown_at) = DATE(?)'
            );
            $stmt->execute([$user_id, date('Y-m-d')]);
            $shown_today = $stmt->fetch()['count'];
            
            // Show max 1 time per day
            if ($shown_today < 1) {
                $show_popup = true;
                
                // Log popup impression
                $stmt = $db->prepare('INSERT INTO referral_popups (user_id) VALUES (?)');
                $stmt->execute([$user_id]);
            }
        }
        
        echo json_encode(['show_popup' => $show_popup]);
        break;

    case 'log_popup_action':
        /**
         * Log user action on referral popup
         * POST: user_id, action (clicked, dismissed, referred)
         */
        $user_id = $_POST['user_id'] ?? null;
        $action_type = $_POST['action'] ?? null;
        
        if (!$user_id || !$action_type) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id and action required']);
            exit;
        }
        
        $stmt = $db->prepare(
            'UPDATE referral_popups SET action = ?, dismissed_at = CURRENT_TIMESTAMP 
             WHERE user_id = ? AND dismissed_at IS NULL ORDER BY shown_at DESC LIMIT 1'
        );
        $stmt->execute([$action_type, $user_id]);
        
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
