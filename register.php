<?php 
require_once 'config.php'; 

// Fetch genders for dropdown
$genders = $pdo->query("SELECT GenderID, Gender FROM TGenders ORDER BY GenderID")->fetchAll(PDO::FETCH_ASSOC);

// Fetch states for dropdown
$states = $pdo->query("SELECT StateID, StateName FROM TStates ORDER BY StateName")->fetchAll(PDO::FETCH_ASSOC);

// Fetch neighborhoods for dropdown
$neighborhoods = $pdo->query("SELECT NeighborhoodID, NeighborhoodName, City FROM TNeighborhoods ORDER BY NeighborhoodName")->fetchAll(PDO::FETCH_ASSOC);
?>
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
                    } elseif($_GET['error'] == 'missing_fields') {
                        echo 'Please fill in all required fields';
                    } elseif($_GET['error'] == 'invalid_zipcode') {
                        echo 'Zip code must be exactly 5 digits';
                    } else {
                        echo 'Registration failed. Please try again.';
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="register_process.php" class="auth-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="firstname" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="lastname" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" placeholder="5135551234">
                    <small>Optional - 10 digits, no dashes</small>
                </div>
                
                <div class="form-group">
                    <label>Street Address *</label>
                    <input type="text" name="address_line1" required placeholder="123 Main Street">
                </div>
                
                <div class="form-group">
                    <label>Apartment, Unit, Suite, etc.</label>
                    <input type="text" name="address_line2" placeholder="Apt 4B">
                    <small>Optional</small>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>State *</label>
                        <select name="state" required>
                            <option value="">Select State</option>
                            <?php foreach($states as $state): ?>
                                <option value="<?php echo $state['StateID']; ?>">
                                    <?php echo htmlspecialchars($state['StateName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Zip Code *</label>
                        <input type="text" name="zipcode" id="zipcode" required pattern="[0-9]{5}" maxlength="5" placeholder="45202">
                        <small>5 digits</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Gender *</label>
                    <select name="gender" required>
                        <option value="">Select Gender</option>
                        <?php foreach($genders as $gender): ?>
                            <option value="<?php echo $gender['GenderID']; ?>">
                                <?php echo htmlspecialchars($gender['Gender']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Neighborhood *</label>
                    <select name="neighborhood" required>
                        <option value="">Select Neighborhood</option>
                        <?php foreach($neighborhoods as $neighborhood): ?>
                            <option value="<?php echo $neighborhood['NeighborhoodID']; ?>">
                                <?php 
                                    if ($neighborhood['City']) {
                                        echo htmlspecialchars($neighborhood['NeighborhoodName'] . ', ' . $neighborhood['City']);
                                    } else {
                                        echo htmlspecialchars($neighborhood['NeighborhoodName']);
                                    }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" id="password" required minlength="8">
                    <small>Must be at least 8 characters</small>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="8">
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
                <p><a href="index.php">← Back to home</a></p>
            </div>
        </div>
    </div>

<!-- Terms & Privacy Modals -->
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
            <p>You are responsible for maintaining the confidentiality of your account and password.</p>
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

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.auth-form');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const phone = document.querySelector('input[name="phone"]');
    const zipcode = document.getElementById('zipcode');
    
    // Zip code - only allow numbers, max 5 digits
    if (zipcode) {
        zipcode.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 5);
        });
    }
    
    form.addEventListener('submit', function(e) {
        if (password.value !== confirmPassword.value) {
            e.preventDefault();
            alert('Passwords do not match! Please re-enter your password.');
            confirmPassword.focus();
            return false;
        }
        
        // Validate zip code is exactly 5 digits
        if (zipcode && zipcode.value.length !== 5) {
            e.preventDefault();
            alert('Zip code must be exactly 5 digits.');
            zipcode.focus();
            return false;
        }
        
        // Strip phone formatting
        if (phone && phone.value) {
            phone.value = phone.value.replace(/[^0-9]/g, '');
        }
    });
    
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
