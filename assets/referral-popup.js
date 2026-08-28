/**
 * ScanPlay Referral Popup System
 * Displays referral incentive popup and handles sharing
 */

class ReferralPopup {
    constructor(options = {}) {
        this.apiUrl = options.apiUrl || '/api.php';
        this.userId = options.userId;
        this.projectCount = options.projectCount || 0;
        this.autoInit = options.autoInit !== false;
        
        if (this.autoInit && this.userId) {
            this.init();
        }
    }
    
    /**
     * Initialize referral system
     */
    async init() {
        // Generate referral code if needed
        await this.ensureReferralCode();
        
        // Check if should show popup
        await this.checkAndShowPopup();
    }
    
    /**
     * Ensure user has referral code
     */
    async ensureReferralCode() {
        try {
            const formData = new FormData();
            formData.append('action', 'init_referral_code');
            formData.append('user_id', this.userId);
            
            const response = await fetch(this.apiUrl, { method: 'POST', body: formData });
            const data = await response.json();
            
            this.referralCode = data.referral_code;
            localStorage.setItem(`referral_code_${this.userId}`, this.referralCode);
        } catch (error) {
            console.error('Failed to initialize referral code:', error);
        }
    }
    
    /**
     * Check if popup should be shown
     */
    async checkAndShowPopup() {
        try {
            const response = await fetch(
                `${this.apiUrl}?action=show_popup&user_id=${this.userId}&project_count=${this.projectCount}`
            );
            const data = await response.json();
            
            if (data.show_popup) {
                this.showPopup();
            }
        } catch (error) {
            console.error('Failed to check popup status:', error);
        }
    }
    
    /**
     * Display referral popup
     */
    showPopup() {
        const referralCode = this.referralCode || localStorage.getItem(`referral_code_${this.userId}`) || 'SCANPLAY';
        const referralLink = `https://scanplay.in?ref=${referralCode}`;
        
        // Create popup HTML
        const popupHTML = `
            <div class="referral-popup-overlay" id="referralPopupOverlay">
                <div class="referral-popup-card">
                    <button class="referral-popup-close" id="referralPopupClose">×</button>
                    
                    <div class="referral-popup-content">
                        <div class="referral-popup-icon">🎉</div>
                        <h2 class="referral-popup-title">You're Crushing It!</h2>
                        <p class="referral-popup-subtitle">Help a friend and unlock more projects</p>
                        
                        <div class="referral-popup-rewards">
                            <div class="reward-box">
                                <span class="reward-icon">📦</span>
                                <span class="reward-text">Get 1 free project<br/>per referral</span>
                            </div>
                        </div>
                        
                        <div class="referral-link-box">
                            <input 
                                type="text" 
                                class="referral-link-input" 
                                id="referralLinkInput" 
                                value="${referralLink}" 
                                readonly
                            />
                            <button class="referral-link-copy" id="referralLinkCopy">Copy</button>
                        </div>
                        
                        <p class="referral-popup-sharetext">Share with friends:</p>
                        
                        <div class="referral-share-buttons">
                            <button class="referral-share-btn referral-share-whatsapp" id="referralShareWhatsapp">
                                <span>💬</span> WhatsApp
                            </button>
                            <button class="referral-share-btn referral-share-telegram" id="referralShareTelegram">
                                <span>📱</span> Telegram
                            </button>
                            <button class="referral-share-btn referral-share-sms" id="referralShareSMS">
                                <span>📧</span> SMS
                            </button>
                        </div>
                        
                        <button class="referral-popup-maybe" id="referralPopupMaybeLater">Maybe Later</button>
                    </div>
                </div>
            </div>
        `;
        
        // Add to DOM
        document.body.insertAdjacentHTML('beforeend', popupHTML);
        
        // Add styles
        this.injectStyles();
        
        // Bind events
        this.bindPopupEvents(referralLink, referralCode);
    }
    
    /**
     * Bind popup interaction events
     */
    bindPopupEvents(referralLink, referralCode) {
        // Close button
        document.getElementById('referralPopupClose').addEventListener('click', () => {
            this.closePopup('dismissed');
        });
        
        // Maybe later button
        document.getElementById('referralPopupMaybeLater').addEventListener('click', () => {
            this.closePopup('dismissed');
        });
        
        // Copy link
        document.getElementById('referralLinkCopy').addEventListener('click', () => {
            const input = document.getElementById('referralLinkInput');
            input.select();
            document.execCommand('copy');
            this.showToast('Link copied! 📋');
            this.logAction('copied');
        });
        
        // WhatsApp share
        document.getElementById('referralShareWhatsapp').addEventListener('click', () => {
            const text = encodeURIComponent(
                `🎬 Check out ScanPlay! Turn your photos into videos instantly!\n\n` +
                `Use my referral link and get a free project: ${referralLink}\n\n` +
                `#ScanPlay #AR #QRCode`
            );
            window.open(`https://wa.me/?text=${text}`, '_blank');
            this.logAction('referred');
        });
        
        // Telegram share
        document.getElementById('referralShareTelegram').addEventListener('click', () => {
            const text = encodeURIComponent(
                `🎬 Check out ScanPlay! Turn your photos into videos instantly!\n\n` +
                `Use my referral link and get a free project: ${referralLink}`
            );
            window.open(`https://t.me/share/url?url=${referralLink}&text=${text}`, '_blank');
            this.logAction('referred');
        });
        
        // SMS share
        document.getElementById('referralShareSMS').addEventListener('click', () => {
            const text = encodeURIComponent(
                `Check out ScanPlay! Get free projects: ${referralLink}`
            );
            window.open(`sms:?body=${text}`, '_blank');
            this.logAction('referred');
        });
        
        // Close on overlay click
        document.getElementById('referralPopupOverlay').addEventListener('click', (e) => {
            if (e.target.id === 'referralPopupOverlay') {
                this.closePopup('dismissed');
            }
        });
    }
    
    /**
     * Close popup
     */
    closePopup(action = 'dismissed') {
        const popup = document.getElementById('referralPopupOverlay');
        if (popup) {
            popup.style.animation = 'referralPopupFadeOut 0.3s ease-out';
            setTimeout(() => popup.remove(), 300);
        }
        this.logAction(action);
    }
    
    /**
     * Show toast notification
     */
    showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'referral-toast';
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }
    
    /**
     * Log popup action
     */
    async logAction(action) {
        try {
            const formData = new FormData();
            formData.append('action', 'log_popup_action');
            formData.append('user_id', this.userId);
            formData.append('action', action);
            
            await fetch(this.apiUrl, { method: 'POST', body: formData });
        } catch (error) {
            console.error('Failed to log popup action:', error);
        }
    }
    
    /**
     * Inject popup styles
     */
    injectStyles() {
        if (document.getElementById('referralPopupStyles')) return;
        
        const styles = `
            <style id="referralPopupStyles">
                /* Overlay */
                .referral-popup-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.5);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 9999;
                    animation: referralPopupFadeIn 0.3s ease-out;
                }
                
                @keyframes referralPopupFadeIn {
                    from {
                        opacity: 0;
                    }
                    to {
                        opacity: 1;
                    }
                }
                
                @keyframes referralPopupFadeOut {
                    from {
                        opacity: 1;
                    }
                    to {
                        opacity: 0;
                    }
                }
                
                @keyframes referralPopupSlideUp {
                    from {
                        transform: translateY(30px);
                        opacity: 0;
                    }
                    to {
                        transform: translateY(0);
                        opacity: 1;
                    }
                }
                
                /* Card */
                .referral-popup-card {
                    background: white;
                    border-radius: 16px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    max-width: 420px;
                    width: 90%;
                    max-height: 90vh;
                    overflow-y: auto;
                    animation: referralPopupSlideUp 0.4s ease-out;
                    position: relative;
                }
                
                .referral-popup-close {
                    position: absolute;
                    top: 12px;
                    right: 12px;
                    background: none;
                    border: none;
                    font-size: 32px;
                    color: #999;
                    cursor: pointer;
                    padding: 0;
                    width: 40px;
                    height: 40px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: color 0.2s;
                }
                
                .referral-popup-close:hover {
                    color: #333;
                }
                
                /* Content */
                .referral-popup-content {
                    padding: 40px 24px 24px;
                    text-align: center;
                }
                
                .referral-popup-icon {
                    font-size: 64px;
                    margin-bottom: 16px;
                }
                
                .referral-popup-title {
                    margin: 0 0 8px 0;
                    font-size: 24px;
                    font-weight: 700;
                    color: #1a1a1a;
                }
                
                .referral-popup-subtitle {
                    margin: 0 0 24px 0;
                    font-size: 15px;
                    color: #666;
                }
                
                /* Rewards */
                .referral-popup-rewards {
                    margin: 24px 0;
                }
                
                .reward-box {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    border-radius: 12px;
                    padding: 16px;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                
                .reward-icon {
                    font-size: 32px;
                }
                
                .reward-text {
                    font-size: 14px;
                    font-weight: 600;
                    line-height: 1.4;
                }
                
                /* Link box */
                .referral-link-box {
                    display: flex;
                    gap: 8px;
                    margin: 24px 0;
                }
                
                .referral-link-input {
                    flex: 1;
                    padding: 10px 12px;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    font-size: 12px;
                    font-family: monospace;
                    background: #f5f5f5;
                    color: #333;
                    cursor: text;
                }
                
                .referral-link-copy {
                    padding: 10px 16px;
                    background: #333;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.2s;
                }
                
                .referral-link-copy:hover {
                    background: #000;
                }
                
                /* Share text */
                .referral-popup-sharetext {
                    margin: 16px 0 12px 0;
                    font-size: 13px;
                    color: #999;
                }
                
                /* Share buttons */
                .referral-share-buttons {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                    margin-bottom: 16px;
                }
                
                .referral-share-btn {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    padding: 12px 16px;
                    border: none;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                
                .referral-share-whatsapp {
                    background: #25D366;
                    color: white;
                }
                
                .referral-share-whatsapp:hover {
                    background: #20BA5A;
                }
                
                .referral-share-telegram {
                    background: #0088cc;
                    color: white;
                }
                
                .referral-share-telegram:hover {
                    background: #0077B3;
                }
                
                .referral-share-sms {
                    background: #FF9500;
                    color: white;
                }
                
                .referral-share-sms:hover {
                    background: #E68900;
                }
                
                /* Maybe later */
                .referral-popup-maybe {
                    width: 100%;
                    padding: 12px 16px;
                    background: #f0f0f0;
                    color: #333;
                    border: none;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.2s;
                }
                
                .referral-popup-maybe:hover {
                    background: #e0e0e0;
                }
                
                /* Toast */
                .referral-toast {
                    position: fixed;
                    bottom: 24px;
                    right: 24px;
                    background: #333;
                    color: white;
                    padding: 12px 16px;
                    border-radius: 8px;
                    font-size: 14px;
                    z-index: 10000;
                    animation: referralPopupSlideUp 0.3s ease-out;
                    transition: opacity 0.3s;
                }
                
                /* Mobile responsiveness */
                @media (max-width: 480px) {
                    .referral-popup-card {
                        width: 95%;
                    }
                    
                    .referral-popup-content {
                        padding: 32px 16px 16px;
                    }
                    
                    .referral-popup-title {
                        font-size: 20px;
                    }
                    
                    .referral-share-buttons {
                        flex-direction: column;
                    }
                    
                    .referral-toast {
                        bottom: 16px;
                        right: 16px;
                        left: 16px;
                    }
                }
            </style>
        `;
        
        document.head.insertAdjacentHTML('beforeend', styles);
    }
    
    /**
     * Get referral stats
     */
    async getStats() {
        try {
            const response = await fetch(
                `${this.apiUrl}?action=get_referral_stats&user_id=${this.userId}`
            );
            return await response.json();
        } catch (error) {
            console.error('Failed to fetch referral stats:', error);
            return null;
        }
    }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ReferralPopup;
}
