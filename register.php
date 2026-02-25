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
                    if($_GET['error'] == 'username') {
                        echo 'Username already taken';
                    } elseif($_GET['error'] == 'email') {
                        echo 'Email already registered';
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
                    <label>Username</label>
                    <input type="text" name="username" required minlength="3">
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="8">
                    <small>Must be at least 8 characters</small>
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" required placeholder="513-555-0123">
                </div>
                
                <div class="form-group">
                    <label>Street Address</label>
                    <input type="text" name="address" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" required value="Cincinnati">
                    </div>
                    
                    <div class="form-group">
                        <label>State</label>
                        <select name="state_id" required>
                            <option value="">Select State</option>
                            <option value="1">Alabama</option>
                            <option value="2">Alaska</option>
                            <option value="3">Arizona</option>
                            <option value="4">Arkansas</option>
                            <option value="5">California</option>
                            <option value="6">Colorado</option>
                            <option value="7">Connecticut</option>
                            <option value="8">Delaware</option>
                            <option value="9">Florida</option>
                            <option value="10">Georgia</option>
                            <option value="11">Hawaii</option>
                            <option value="12">Idaho</option>
                            <option value="13">Illinois</option>
                            <option value="14">Indiana</option>
                            <option value="15">Iowa</option>
                            <option value="16">Kansas</option>
                            <option value="17">Kentucky</option>
                            <option value="18">Louisiana</option>
                            <option value="19">Maine</option>
                            <option value="20">Maryland</option>
                            <option value="21">Massachusetts</option>
                            <option value="22">Michigan</option>
                            <option value="23">Minnesota</option>
                            <option value="24">Mississippi</option>
                            <option value="25">Missouri</option>
                            <option value="26">Montana</option>
                            <option value="27">Nebraska</option>
                            <option value="28">Nevada</option>
                            <option value="29">New Hampshire</option>
                            <option value="30">New Jersey</option>
                            <option value="31">New Mexico</option>
                            <option value="32">New York</option>
                            <option value="33">North Carolina</option>
                            <option value="34">North Dakota</option>
                            <option value="35" selected>Ohio</option>
                            <option value="36">Oklahoma</option>
                            <option value="37">Oregon</option>
                            <option value="38">Pennsylvania</option>
                            <option value="39">Rhode Island</option>
                            <option value="40">South Carolina</option>
                            <option value="41">South Dakota</option>
                            <option value="42">Tennessee</option>
                            <option value="43">Texas</option>
                            <option value="44">Utah</option>
                            <option value="45">Vermont</option>
                            <option value="46">Virginia</option>
                            <option value="47">Washington</option>
                            <option value="48">West Virginia</option>
                            <option value="49">Wisconsin</option>
                            <option value="50">Wyoming</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Zip Code</label>
                    <input type="text" name="zip" required pattern="[0-9]{5}" placeholder="45202">
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
            <span class="close" onclick="closeModal('termsModal')">&times;</span>
            <h2>Terms of Service</h2>
            <div class="modal-body">
                <p><strong>Last Updated:</strong> February 2026</p>
                
                <h3>1. Acceptance of Terms</h3>
                <p>By accessing and using Community Toolkit, you accept and agree to be bound by the terms and provision of this agreement.</p>
                
                <h3>2. Use License</h3>
                <p>Permission is granted to temporarily use Community Toolkit for personal, non-commercial transitory viewing only.</p>
                
                <h3>3. User Responsibilities</h3>
                <p>As a user, you agree to:</p>
                <ul>
                    <li>Provide accurate information when creating listings</li>
                    <li>Treat borrowed items with care and return them on time</li>
                    <li>Communicate respectfully with other users</li>
                    <li>Report any issues or damages immediately</li>
                </ul>
                
                <h3>4. Rental Agreements</h3>
                <p>All rental agreements are made directly between users. Community Toolkit acts as a platform to facilitate connections but is not party to rental agreements.</p>
                
                <h3>5. Liability</h3>
                <p>Community Toolkit is not responsible for:</p>
                <ul>
                    <li>Loss or damage to items</li>
                    <li>Disputes between users</li>
                    <li>Quality or condition of listed items</li>
                </ul>
                
                <h3>6. Account Termination</h3>
                <p>We reserve the right to terminate accounts that violate these terms or engage in fraudulent activity.</p>
                
                <h3>7. Changes to Terms</h3>
                <p>We reserve the right to modify these terms at any time. Continued use of the service constitutes acceptance of modified terms.</p>
            </div>
        </div>
    </div>
    
    <!-- Privacy Policy Modal -->
    <div id="privacyModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('privacyModal')">&times;</span>
            <h2>Privacy Policy</h2>
            <div class="modal-body">
                <p><strong>Last Updated:</strong> February 2026</p>
                
                <h3>1. Information We Collect</h3>
                <p>We collect information you provide directly to us, including:</p>
                <ul>
                    <li>Name, email address, and phone number</li>
                    <li>Mailing address and location data</li>
                    <li>Item listings and descriptions</li>
                    <li>Rental transaction history</li>
                    <li>Reviews and ratings</li>
                </ul>
                
                <h3>2. How We Use Your Information</h3>
                <p>We use the information we collect to:</p>
                <ul>
                    <li>Provide and maintain our services</li>
                    <li>Process rental transactions</li>
                    <li>Send notifications about rentals</li>
                    <li>Improve user experience</li>
                    <li>Prevent fraud and abuse</li>
                </ul>
                
                <h3>3. Information Sharing</h3>
                <p>We share your information with:</p>
                <ul>
                    <li>Other users as necessary to facilitate rentals</li>
                    <li>Service providers who assist in operations</li>
                    <li>Law enforcement when required by law</li>
                </ul>
                <p>We do NOT sell your personal information to third parties.</p>
                
                <h3>4. Location Data</h3>
                <p>We use your location to show you items near you and calculate distances. You can disable location services in your device settings.</p>
                
                <h3>5. Data Security</h3>
                <p>We implement security measures to protect your personal information, including password hashing and secure database storage.</p>
                
                <h3>6. Your Rights</h3>
                <p>You have the right to:</p>
                <ul>
                    <li>Access your personal data</li>
                    <li>Correct inaccurate information</li>
                    <li>Request deletion of your account</li>
                    <li>Opt out of marketing communications</li>
                </ul>
                
                <h3>7. Contact Us</h3>
                <p>For privacy concerns, contact us at: privacy@communitytoolkit.com</p>
            </div>
        </div>
    </div>
    
    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>

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
