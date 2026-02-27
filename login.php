<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-logo">
                <i class="fas fa-tools"></i>
                <h1>Community Toolkit</h1>
            </div>
            
            <h2>Welcome back</h2>
            <p class="subtitle">Sign in to your account to continue</p>
            
            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    Invalid email or password. Please try again.
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['registered'])): ?>
                <div class="alert alert-success">
                    Registration successful! Please log in.
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['logout'])): ?>
                <div class="alert alert-success">
                    You have been logged out successfully.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login_process.php" class="auth-form">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required autofocus>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember">
                        Remember me
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-full">Sign In</button>
            </form>
            
            <div class="auth-divider">
                <span>Or continue with</span>
            </div>
            
            <div class="social-login">
                <button class="btn btn-social btn-google">
                    <i class="fab fa-google"></i>
                    Google
                </button>
                <button class="btn btn-social btn-facebook">
                    <i class="fab fa-facebook-f"></i>
                    Facebook
                </button>
            </div>
            
            <div class="auth-footer">
                <p>Don't have an account? <a href="register.php">Sign up</a></p>
                <p><a href="index.html">← Back to home</a></p>
                <p class="text-sm">
                    <a href="#" onclick="openModal('termsModal'); return false;">Terms of Service</a> • 
                    <a href="#" onclick="openModal('privacyModal'); return false;">Privacy Policy</a>
                </p>
            </div>
        </div>
    </div>

<!-- Terms of Service Modal helloooo-->
<div id="termsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Terms of Service</h2>
            <span class="close" onclick="closeModal('termsModal')">&times;</span>
        </div>
        <div class="modal-body">
            <h3>1. Acceptance of Terms</h3>
            <p>By accessing and using Community Toolkit, you accept and agree to be bound by the terms and provision of this agreement.</p>
            
            <h3>2. Use of Service</h3>
            <p>Community Toolkit is a platform for sharing and renting tools within your local community. You agree to use this service responsibly and legally.</p>
            
            <h3>3. User Accounts</h3>
            <p>You are responsible for maintaining the confidentiality of your account and password. You agree to accept responsibility for all activities that occur under your account.</p>
            
            <h3>4. Rental Agreements</h3>
            <p>All rental agreements are between individual users. Community Toolkit facilitates connections but is not party to rental agreements.</p>
            
            <h3>5. Liability</h3>
            <p>Community Toolkit is not responsible for damages, injuries, or losses resulting from tool rentals. Users assume all risks.</p>
            
            <h3>6. Prohibited Activities</h3>
            <p>You may not use this service for any illegal activities, fraud, or harassment of other users.</p>
            
            <p><em>Last updated: February 2026</em></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="closeModal('termsModal')">I Understand</button>
        </div>
    </div>
</div>

<!-- Privacy Policy Modal -->
<div id="privacyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Privacy Policy</h2>
            <span class="close" onclick="closeModal('privacyModal')">&times;</span>
        </div>
        <div class="modal-body">
            <h3>Information We Collect</h3>
            <p>We collect information you provide when creating an account, including name, email, phone number, and address.</p>
            
            <h3>How We Use Your Information</h3>
            <p>Your information is used to:</p>
            <ul>
                <li>Facilitate tool rentals between users</li>
                <li>Communicate about rental transactions</li>
                <li>Improve our service</li>
                <li>Ensure platform security</li>
            </ul>
            
            <h3>Information Sharing</h3>
            <p>We share your name and general location with other users when you list items or request rentals. We never sell your personal information to third parties.</p>
            
            <h3>Data Security</h3>
            <p>We use industry-standard security measures to protect your data, including password encryption and secure connections.</p>
            
            <h3>Your Rights</h3>
            <p>You can request access to, correction of, or deletion of your personal data at any time by contacting us.</p>
            
            <h3>Contact Us</h3>
            <p>For privacy concerns, email: privacy@communitytoolkit.com</p>
            
            <p><em>Last updated: February 2026</em></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="closeModal('privacyModal')">I Understand</button>
        </div>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}
</script>
</html>
</html>

<!-- Terms of Service Modal -->
<div id="termsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Terms of Service</h2>
            <span class="close" onclick="closeModal('termsModal')">&times;</span>
        </div>
        <div class="modal-body">
            <h3>1. Acceptance of Terms</h3>
            <p>By accessing and using Community Toolkit, you accept and agree to be bound by the terms and provision of this agreement.</p>
            
            <h3>2. Use of Service</h3>
            <p>Community Toolkit is a platform for sharing and renting tools within your local community. You agree to use this service responsibly and legally.</p>
            
            <h3>3. User Accounts</h3>
            <p>You are responsible for maintaining the confidentiality of your account and password. You agree to accept responsibility for all activities that occur under your account.</p>
            
            <h3>4. Rental Agreements</h3>
            <p>All rental agreements are between individual users. Community Toolkit facilitates connections but is not party to rental agreements.</p>
            
            <h3>5. Liability</h3>
            <p>Community Toolkit is not responsible for damages, injuries, or losses resulting from tool rentals. Users assume all risks.</p>
            
            <h3>6. Prohibited Activities</h3>
            <p>You may not use this service for any illegal activities, fraud, or harassment of other users.</p>
            
            <p><em>Last updated: February 2026</em></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="closeModal('termsModal')">I Understand</button>
        </div>
    </div>
</div>

<!-- Privacy Policy Modal -->
<div id="privacyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Privacy Policy</h2>
            <span class="close" onclick="closeModal('privacyModal')">&times;</span>
        </div>
        <div class="modal-body">
            <h3>Information We Collect</h3>
            <p>We collect information you provide when creating an account, including name, email, phone number, and address.</p>
            
            <h3>How We Use Your Information</h3>
            <p>Your information is used to:</p>
            <ul>
                <li>Facilitate tool rentals between users</li>
                <li>Communicate about rental transactions</li>
                <li>Improve our service</li>
                <li>Ensure platform security</li>
            </ul>
            
            <h3>Information Sharing</h3>
            <p>We share your name and general location with other users when you list items or request rentals. We never sell your personal information to third parties.</p>
            
            <h3>Data Security</h3>
            <p>We use industry-standard security measures to protect your data, including password encryption and secure connections.</p>
            
            <h3>Your Rights</h3>
            <p>You can request access to, correction of, or deletion of your personal data at any time by contacting us.</p>
            
            <h3>Contact Us</h3>
            <p>For privacy concerns, email: privacy@communitytoolkit.com</p>
            
            <p><em>Last updated: February 2026</em></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="closeModal('privacyModal')">I Understand</button>
        </div>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}
</script>
</body>
</html>
