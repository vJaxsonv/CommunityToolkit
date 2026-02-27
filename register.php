<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Community Toolkit</title>
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
            
            <h2>Create your account</h2>
            <p class="subtitle">Join your community and start sharing today</p>
            
            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    <?php 
                    if($_GET['error'] == 'email') {
                        echo 'Email already registered';
                    } elseif($_GET['error'] == 'password_mismatch') {
                        echo 'Passwords do not match';
                    } else {
                        echo 'Registration failed. Please try again.';
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="register_process.php" class="auth-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="firstname" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="lastname" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" id="password" required minlength="8">
                    <small>Must be at least 8 characters</small>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="8">
                    <small>Re-enter your password</small>
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" placeholder="513-555-0123">
                    <small>Optional - for rental communications</small>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" required>
                        I agree to the <a href="#" onclick="openModal('termsModal'); return false;">Terms of Service</a> and <a href="#" onclick="openModal('privacyModal'); return false;">Privacy Policy</a>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-full">Create Account</button>
            </form>
            
            <div class="auth-footer">
                <p>Already have an account? <a href="login.php">Sign in</a></p>
                <p><a href="index.html">← Back to home</a></p>
            </div>
        </div>
    </div>

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
            <p>We collect information you provide when creating an account, including name, email, and phone number.</p>
            
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

// Password match validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.auth-form');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    
    form.addEventListener('submit', function(e) {
        if (password.value !== confirmPassword.value) {
            e.preventDefault();
            alert('Passwords do not match! Please re-enter your password.');
            confirmPassword.focus();
            return false;
        }
    });
    
    // Real-time feedback
    confirmPassword.addEventListener('input', function() {
        if (this.value && password.value !== this.value) {
            this.style.borderColor = '#dc3545';
        } else {
            this.style.borderColor = '#ddd';
        }
    });
});
</script>
</body>
</html>
