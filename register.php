<?php 
require_once 'config.php'; 

// Fetch genders for dropdown
$genders = $pdo->query("SELECT GenderID, Gender FROM TGenders ORDER BY GenderID")->fetchAll(PDO::FETCH_ASSOC);

// Fetch states for dropdown
$states = $pdo->query("SELECT StateID, StateName FROM TStates ORDER BY StateName")->fetchAll(PDO::FETCH_ASSOC);

// State abbreviation mapping for display
$stateAbbreviations = [
    'Ohio' => 'OH',
    'Kentucky' => 'KY',
    'Indiana' => 'IN'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Community Toolkit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-logo">
                <a href="index.php" class="logo-link">
                    <img src="images/Community.png" alt="Community Toolkit" style="height: 60px; width: auto; max-width: 100%;">
                </a>
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
                    } elseif($_GET['error'] == 'password_length') {
                        echo 'Password must be at least 8 characters';
                    } elseif($_GET['error'] == 'password_number') {
                        echo 'Password must include at least one number (0-9)';
                    } elseif($_GET['error'] == 'password_special') {
                        echo 'Password must include at least one special character (!@#$%^&*)';
                    } elseif($_GET['error'] == 'missing_fields') {
                        echo 'Please fill in all required fields';
                        if (isset($_GET['missing'])) {
                            echo '<br><small>Missing: ' . htmlspecialchars($_GET['missing']) . '</small>';
                        }
                    } elseif($_GET['error'] == 'invalid_zipcode') {
                        echo 'Zip code must be exactly 5 digits';
                    } elseif($_GET['error'] == 'terms_not_agreed') {
                        echo 'You must read and agree to the Terms of Use to create an account.';
                    } elseif($_GET['error'] == 'inappropriate_content') {
                        echo 'Your submission contains inappropriate content. Please review and resubmit.';
                    } elseif($_GET['error'] == 'database') {
                        echo 'Database error. Please try again or contact support.';
                    } else {
                        echo 'Registration failed. Please try again.';
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="register_process.php" class="auth-form" enctype="multipart/form-data">
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
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" id="password" required minlength="8">
                        <small style="display: block; margin-top: 5px; color: #666; font-size: 12px; line-height: 1.4;">
                            Must be 8+ characters with at least one number (0-9) and one special character (!@#$%^&*)
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="tel" name="phone" id="phone" placeholder="(555) 123-4567" required>
                </div>
                
                <div class="form-group">
                    <label>Street Address *</label>
                    <input type="text" name="address_line1" placeholder="123 Main St" required>
                </div>
                
                <div class="form-group">
                    <label>Apt / Unit (Optional)</label>
                    <input type="text" name="address_line2" placeholder="Apt 4B">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>State *</label>
                        <select name="state" id="stateSelect" required onchange="loadCities()">
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
                        <input type="text" name="zipcode" id="zipcode" placeholder="45202" required maxlength="5" pattern="[0-9]{5}">
                    </div>
                </div>
                
                <div class="form-group" id="cityContainer" style="display: none;">
                    <label>City *</label>
                    <select name="city" id="citySelect" onchange="loadNeighborhoods()">
                        <option value="">Select City</option>
                    </select>
                </div>
                
                <div class="form-group" id="neighborhoodContainer" style="display: none;">
                    <label>Neighborhood</label>
                    <select name="neighborhood" id="neighborhoodSelect">
                        <option value="">Select Neighborhood</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Gender *</label>
                    <select name="gender" required>
                        <option value="">Select Gender</option>
                        <?php foreach($genders as $gender): ?>
                            <option value="<?php echo $gender['GenderID']; ?>"><?php echo htmlspecialchars($gender['Gender']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Bio (Optional)</label>
                    <textarea name="bio" rows="4" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; min-height: 100px; max-height: 300px; font-family: inherit; font-size: 14px;" placeholder="Tell us about yourself..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Profile Photo (Optional)</label>

                    <!-- Hidden real file input -->
                    <input type="file" name="profile_photo" id="profilePhotoInput"
                           accept="image/jpeg,image/png,image/gif,image/webp">

                    <div class="photo-upload-zone" id="profileUploadZone">

                        <!-- Default state -->
                        <div id="profileUploadInner">
                            <!-- Drag & drop instruction (desktop only) -->
                            <div class="photo-drop-area">
                                <i class="fas fa-camera"></i>
                                <p><strong>Drag &amp; drop your photo here</strong></p>
                                <p style="font-size:12px; color:#9ca3af;">or use the button below</p>
                            </div>
                            <!-- Browse button — always visible -->
                            <div class="photo-browse-row">
                                <button type="button" class="photo-browse-btn" id="profileBrowseBtn">
                                    <i class="fas fa-folder-open"></i> Choose Photo
                                </button>
                                <span class="photo-browse-hint">JPG, PNG, GIF, WEBP · Max 5MB</span>
                            </div>
                        </div>

                        <!-- Preview state (hidden until file selected) -->
                        <div class="photo-preview-circle" id="profilePreviewWrap" style="display:none;">
                            <img id="profilePreview" src="" alt="Preview">
                            <button type="button" class="photo-remove-btn" id="profileRemoveBtn">
                                <i class="fas fa-times"></i> Remove photo
                            </button>
                        </div>

                    </div>
                </div>
                

                <!-- ── Terms & Conditions ── -->
                <div class="terms-box">
                    <div class="terms-scroll">
                        <h4>Terms of Use &amp; Community Guidelines</h4>

                        <p><strong>1. Acceptance of Terms</strong><br>
                        By creating an account on Community Toolkit ("the Platform"), you confirm that you are at least 18 years of age and agree to be bound by these Terms of Use. If you do not agree, you may not register or use the Platform.</p>

                        <p><strong>2. Legal Use Only</strong><br>
                        The Platform may only be used to list, rent, or borrow lawful items and to conduct legal transactions. You may not list, offer, or exchange any item that is illegal to own, sell, or transfer under applicable local, state, or federal law — including but not limited to controlled substances, weapons requiring special licensure, stolen property, or counterfeit goods. Violations will result in immediate account suspension and may be reported to appropriate authorities.</p>

                        <p><strong>3. User Responsibility</strong><br>
                        You are solely responsible for the accuracy of your listings, the condition of items you rent out, and any transactions you enter into through the Platform. Community Toolkit serves as a peer-to-peer marketplace only and is not a party to any rental agreement between users. We are not responsible for the condition, safety, legality, or fitness of any item listed or exchanged.</p>

                        <p><strong>4. Data Retention Policy</strong><br>
                        When you delete your account, deactivate a listing, or remove personal information, that content will no longer be visible on the Platform or accessible through your account. However, certain records — including account registration data, listing history, transaction records, and communications — are retained in our secure database for legal, compliance, and dispute resolution purposes. This retained data is not displayed publicly and is not accessible to other users. Address information, listing details, and other personal data submitted during registration or use of the Platform are stored in our database in accordance with this policy.</p>

                        <p><strong>5. Account &amp; Listing Removal</strong><br>
                        Community Toolkit reserves the right to suspend, deactivate, or permanently remove any account or listing at our sole discretion, including for violations of these Terms, abusive behavior, fraudulent activity, or listings of prohibited items. Removed content may be retained in our database per Section 4 but will not be visible to any users.</p>

                        <p><strong>6. Content Standards</strong><br>
                        All content you submit — including your name, bio, listing titles, descriptions, and photos — must be accurate, appropriate, and lawful. Obscene, harassing, defamatory, or deceptive content is strictly prohibited. The Platform employs automated content moderation and reserves the right to remove any content that violates these standards.</p>

                        <p><strong>7. Limitation of Liability</strong><br>
                        Community Toolkit is provided "as is" without warranties of any kind. To the fullest extent permitted by law, Community Toolkit and its operators shall not be liable for any damages arising from your use of the Platform, transactions between users, or the condition or legality of any listed item.</p>

                        <p><strong>8. Changes to Terms</strong><br>
                        We reserve the right to update these Terms at any time. Continued use of the Platform after changes are posted constitutes your acceptance of the revised Terms.</p>

                        <p style="color:#6b7280; font-size:12px; margin-top:12px;">Last updated: March 2026 &nbsp;·&nbsp; Community Toolkit, Cincinnati/Tri-state Area</p>
                    </div>

                    <label class="terms-checkbox-label">
                        <input type="checkbox" name="agree_terms" id="agreeTerms" required>
                        <span>I have read and agree to the Terms of Use, Community Guidelines, and Data Retention Policy. I confirm I am at least 18 years old and will only use this platform for lawful purposes.</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="width:100%;padding:12px;font-size:15px;font-weight:700;">Create Account</button>

                <div style="margin-top:28px;text-align:center;">
                    <p style="margin:0 0 10px 0;color:#666;font-size:14px;">Already have an account?</p>
                    <a href="login.php" style="display:block;width:100%;text-align:center;padding:12px;border-radius:8px;font-size:15px;font-weight:700;border:2px solid #667eea;color:#667eea;text-decoration:none;transition:all 0.2s;box-sizing:border-box;" onmouseover="this.style.background='#667eea';this.style.color='white'" onmouseout="this.style.background='transparent';this.style.color='#667eea'">Sign In</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Crop Modal ── -->
    <div class="crop-modal-overlay" id="cropModalOverlay">
        <div class="crop-modal">
            <div class="crop-modal-header">
                <i class="fas fa-crop-alt"></i> Position Your Photo
            </div>
            <div class="crop-modal-body">
                <div class="crop-container">
                    <img id="cropImage" src="" alt="Crop">
                </div>
                <p class="crop-hint">
                    <i class="fas fa-arrows-alt"></i> Drag to reposition &nbsp;·&nbsp;
                    <i class="fas fa-search-plus"></i> Scroll or pinch to zoom
                </p>
            </div>
            <div class="crop-modal-footer">
                <button type="button" class="crop-btn-cancel" id="cropCancelBtn">
                    Cancel
                </button>
                <button type="button" class="crop-btn-confirm" id="cropConfirmBtn">
                    <i class="fas fa-check"></i> Use This Photo
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <script>
        // ── Profile photo: drag & drop + browse + circular crop ──
        const uploadZone    = document.getElementById('profileUploadZone');
        const photoInput    = document.getElementById('profilePhotoInput');
        const browseBtn     = document.getElementById('profileBrowseBtn');
        const uploadInner   = document.getElementById('profileUploadInner');
        const previewWrap   = document.getElementById('profilePreviewWrap');
        const previewImg    = document.getElementById('profilePreview');
        const removeBtn     = document.getElementById('profileRemoveBtn');

        // Crop modal elements
        const cropOverlay   = document.getElementById('cropModalOverlay');
        const cropImage     = document.getElementById('cropImage');
        const cropCancelBtn = document.getElementById('cropCancelBtn');
        const cropConfirmBtn = document.getElementById('cropConfirmBtn');
        let cropper = null;

        // Browse button opens file picker
        browseBtn.addEventListener('click', () => photoInput.click());

        // File chosen via picker
        photoInput.addEventListener('change', function() {
            if (this.files[0]) openCropper(this.files[0]);
        });

        // Drag & drop (desktop)
        uploadZone.addEventListener('dragover', e => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });
        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });
        uploadZone.addEventListener('drop', e => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            const file = e.dataTransfer.files[0];
            if (file) openCropper(file);
        });

        // Remove selected photo
        removeBtn.addEventListener('click', () => clearPhoto());

        function openCropper(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                cropImage.src = e.target.result;
                cropOverlay.classList.add('active');
                // Destroy previous cropper instance if exists
                if (cropper) { cropper.destroy(); cropper = null; }
                cropper = new Cropper(cropImage, {
                    aspectRatio: 1,          // Square crop = circle display
                    viewMode: 1,             // Restrict crop box to canvas
                    dragMode: 'move',        // Move image, not crop box
                    cropBoxMovable: false,   // Crop box stays centered
                    cropBoxResizable: false, // Fixed size crop box
                    guides: false,
                    center: true,
                    highlight: false,
                    background: true,
                    autoCropArea: 0.85,      // Crop box fills 85% of canvas
                    responsive: true,
                    restore: false,
                });
            };
            reader.readAsDataURL(file);
        }

        // Cancel — close modal, reset input
        cropCancelBtn.addEventListener('click', () => {
            cropOverlay.classList.remove('active');
            if (cropper) { cropper.destroy(); cropper = null; }
            photoInput.value = '';
        });

        // Confirm — get cropped canvas, convert to Blob, inject into input
        cropConfirmBtn.addEventListener('click', () => {
            if (!cropper) return;

            const canvas = cropper.getCroppedCanvas({
                width: 400,
                height: 400,
                imageSmoothingQuality: 'high',
            });

            canvas.toBlob(blob => {
                // Create a File from the blob
                const croppedFile = new File([blob], 'profile_photo.jpg', { type: 'image/jpeg' });

                // Inject into the file input
                const dt = new DataTransfer();
                dt.items.add(croppedFile);
                photoInput.files = dt.files;

                // Show circular preview
                previewImg.src = canvas.toDataURL('image/jpeg');
                uploadInner.style.display = 'none';
                previewWrap.style.display = 'flex';

                // Close modal
                cropOverlay.classList.remove('active');
                if (cropper) { cropper.destroy(); cropper = null; }
            }, 'image/jpeg', 0.92);
        });

        function clearPhoto() {
            previewImg.src = '';
            previewWrap.style.display = 'none';
            uploadInner.style.display = 'block';
            photoInput.value = '';
        }

        // Phone number formatting — hard-coded (555) 123-4567 style
        document.getElementById('phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '').substring(0, 10);
            let formatted = '';

            if (value.length === 0) {
                formatted = '';
            } else if (value.length <= 3) {
                formatted = '(' + value;
            } else if (value.length <= 6) {
                formatted = '(' + value.substring(0, 3) + ') ' + value.substring(3);
            } else {
                formatted = '(' + value.substring(0, 3) + ') ' + value.substring(3, 6) + '-' + value.substring(6);
            }

            e.target.value = formatted;
        });
        
        // Zip code - only numbers
        document.getElementById('zipcode').addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0, 5);
            if (e.target.value.length === 5) {
                lookupByZip(e.target.value);
            }
        });

        async function lookupByZip(zip) {
            try {
                const response = await fetch(`get_location_by_zip.php?zip=${zip}`);
                const data = await response.json();

                if (!data.found) return;

                // --- Auto-select State ---
                const stateSelect = document.getElementById('stateSelect');
                stateSelect.value = data.state_id;

                // --- Populate and show City dropdown ---
                const citySelect    = document.getElementById('citySelect');
                const cityContainer = document.getElementById('cityContainer');
                citySelect.innerHTML = '<option value="">Select City</option>';
                data.cities.forEach(city => {
                    const opt = document.createElement('option');
                    opt.value = city.CityID;
                    opt.textContent = city.CityName;
                    if (city.CityID == data.city_id) opt.selected = true;
                    citySelect.appendChild(opt);
                });
                cityContainer.style.display = 'block';
                citySelect.required = true;

                // --- Populate Neighborhood dropdown or auto-select ---
                const neighborhoodSelect    = document.getElementById('neighborhoodSelect');
                const neighborhoodContainer = document.getElementById('neighborhoodContainer');

                // Remove any existing auto_neighborhood hidden input
                const existingHidden = document.getElementById('autoNeighborhood');
                if (existingHidden) existingHidden.remove();

                if (data.has_neighborhoods) {
                    neighborhoodSelect.innerHTML = '<option value="">Select Neighborhood</option>';
                    data.neighborhoods.forEach(n => {
                        const opt = document.createElement('option');
                        opt.value = n.NeighborhoodID;
                        opt.textContent = n.NeighborhoodName;
                        if (n.NeighborhoodID == data.neighborhood_id) opt.selected = true;
                        neighborhoodSelect.appendChild(opt);
                    });
                    neighborhoodContainer.style.display = 'block';
                    neighborhoodSelect.required = true;
                } else {
                    // City has only one neighborhood — auto-select silently
                    neighborhoodContainer.style.display = 'none';
                    neighborhoodSelect.required = false;
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type  = 'hidden';
                    hiddenInput.id    = 'autoNeighborhood';
                    hiddenInput.name  = 'auto_neighborhood';
                    hiddenInput.value = data.city_id;
                    document.querySelector('form').appendChild(hiddenInput);
                }

            } catch (error) {
                console.error('ZIP lookup error:', error);
            }
        }
        
        // Load cities when state is selected
        async function loadCities() {
            const stateId = document.getElementById('stateSelect').value;
            const cityContainer = document.getElementById('cityContainer');
            const citySelect = document.getElementById('citySelect');
            const neighborhoodContainer = document.getElementById('neighborhoodContainer');
            const neighborhoodSelect = document.getElementById('neighborhoodSelect');
            
            // Reset city and neighborhood
            citySelect.innerHTML = '<option value="">Select City</option>';
            neighborhoodSelect.innerHTML = '<option value="">Select Neighborhood</option>';
            neighborhoodContainer.style.display = 'none';
            
            if (!stateId) {
                cityContainer.style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch(`get_cities.php?state_id=${stateId}`);
                const cities = await response.json();
                
                cities.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city.CityID;
                    option.textContent = city.CityName;
                    citySelect.appendChild(option);
                });
                
                cityContainer.style.display = 'block';
                citySelect.required = true;
            } catch (error) {
                console.error('Error loading cities:', error);
            }
        }
        
        // Load neighborhoods when city is selected
        async function loadNeighborhoods() {
            const cityId = document.getElementById('citySelect').value;
            const neighborhoodContainer = document.getElementById('neighborhoodContainer');
            const neighborhoodSelect = document.getElementById('neighborhoodSelect');
            
            // Reset neighborhoods
            neighborhoodSelect.innerHTML = '<option value="">Select Neighborhood</option>';
            
            if (!cityId) {
                neighborhoodContainer.style.display = 'none';
                neighborhoodSelect.required = false;
                return;
            }
            
            try {
                const response = await fetch(`get_neighborhoods.php?city_id=${cityId}`);
                const neighborhoods = await response.json();
                
                if (neighborhoods.length === 0) {
                    // No neighborhoods for this city - hide dropdown and auto-select the city itself
                    neighborhoodContainer.style.display = 'none';
                    neighborhoodSelect.required = false;
                    
                    // Create a hidden input with the city as the neighborhood
                    let hiddenInput = document.getElementById('autoNeighborhood');
                    if (!hiddenInput) {
                        hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.id = 'autoNeighborhood';
                        hiddenInput.name = 'auto_neighborhood';
                        document.querySelector('form').appendChild(hiddenInput);
                    }
                    hiddenInput.value = cityId;
                } else {
                    // Has neighborhoods - show dropdown
                    neighborhoods.forEach(neighborhood => {
                        const option = document.createElement('option');
                        option.value = neighborhood.NeighborhoodID;
                        option.textContent = neighborhood.NeighborhoodName;
                        neighborhoodSelect.appendChild(option);
                    });
                    
                    neighborhoodContainer.style.display = 'block';
                    neighborhoodSelect.required = true;
                    
                    // Remove auto neighborhood if it exists
                    const hiddenInput = document.getElementById('autoNeighborhood');
                    if (hiddenInput) {
                        hiddenInput.remove();
                    }
                }
            } catch (error) {
                console.error('Error loading neighborhoods:', error);
            }
        }
        
        // Form validation on submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.auth-form');
            const password = document.querySelector('input[name="password"]');
            const confirmPassword = document.querySelector('input[name="confirm_password"]');
            const phone = document.querySelector('input[name="phone"]');
            const zipcode = document.getElementById('zipcode');
            
            form.addEventListener('submit', function(e) {
                console.log('Form submit triggered');
                
                // Check if state is selected but city is not
                const stateSelect = document.getElementById('stateSelect');
                const citySelect = document.getElementById('citySelect');
                const neighborhoodSelect = document.getElementById('neighborhoodSelect');
                const autoNeighborhood = document.getElementById('autoNeighborhood');
                
                console.log('State:', stateSelect.value);
                console.log('City:', citySelect.value);
                console.log('Neighborhood:', neighborhoodSelect.value);
                console.log('Auto Neighborhood:', autoNeighborhood ? autoNeighborhood.value : 'none');
                
                if (stateSelect.value && !citySelect.value) {
                    e.preventDefault();
                    alert('Please select a city.');
                    citySelect.focus();
                    return false;
                }
                
                // Check if city is selected but no neighborhood (and no auto neighborhood)
                if (citySelect.value && !neighborhoodSelect.value && !autoNeighborhood) {
                    e.preventDefault();
                    alert('Please wait for neighborhoods to load or select a neighborhood.');
                    return false;
                }
                
                // Check password complexity
                const passwordValue = password.value;
                const hasNumber = /\d/.test(passwordValue);
                const hasSpecial = /[!@#$%^&*]/.test(passwordValue);
                
                console.log('Password has number:', hasNumber);
                console.log('Password has special:', hasSpecial);
                
                if (!hasNumber || !hasSpecial) {
                    e.preventDefault();
                    alert('Password must include at least one number (0-9) and one special character (!@#$%^&*)');
                    password.focus();
                    return false;
                }
                
                // Check passwords match
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
                
                console.log('Form validation passed, submitting...');
            });
            
            // Real-time password match indicator
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
