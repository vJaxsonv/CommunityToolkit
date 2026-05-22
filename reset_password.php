<?php 
require_once 'config.php';

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: login.php?error=invalid_token');
    exit;
}

// Verify token is valid and not expired
$stmt = $pdo->prepare("
    SELECT pr.ResetID, pr.UserID, pr.ExpiresAt, pr.UsedAt, u.Email, u.FirstName
    FROM TPasswordResets pr
    INNER JOIN TUsers u ON pr.UserID = u.UserID
    WHERE pr.Token = ? AND pr.UsedAt IS NULL
");
$stmt->execute([$token]);
$reset = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if token is invalid or already used
if (!$reset) {
    header('Location: login.php?error=invalid_token');
    exit;
}

// Check if token is expired
if (strtotime($reset['ExpiresAt']) < time()) {
    header('Location: login.php?error=expired_token');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Community Toolkit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            background: linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)),
                        url('https://images.pexels.com/photos/1249611/pexels-photo-1249611.jpeg?auto=compress&cs=tinysrgb&w=1920') center/cover fixed;
            background-color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
        }
        
        .reset-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 420px;
            padding: 35px 35px 30px 35px;
        }
        
        .reset-header {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .reset-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        .reset-logo img {
            height: 80px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }
        
        .reset-header h2 {
            font-size: 20px;
            color: #333;
            margin: 0 0 10px 0;
            font-weight: 500;
        }
        
        .reset-header p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }
        
        .user-info {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .user-info p {
            font-size: 13px;
            color: #666;
            margin: 0;
        }
        
        .user-info strong {
            color: #333;
        }
        
        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
        }
        
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #555;
            font-size: 13px;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .password-toggle {
            position: relative;
        }
        
        .password-toggle input {
            padding-right: 45px;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            padding: 5px;
            font-size: 18px;
        }
        
        .password-toggle-btn:hover {
            color: #667eea;
        }
        
        .password-requirements {
            font-size: 12px;
            color: #666;
            margin-top: 8px;
            line-height: 1.5;
        }
        
        .btn-reset {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-reset:active {
            transform: translateY(0);
        }
        
        @media (max-width: 600px) {
            .reset-card {
                padding: 40px 30px;
            }
            
            .reset-logo img {
                height: 60px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="reset-header">
            <div class="reset-logo">
                <a href="index.php">
                    <img src="images/Community.png" alt="Community Toolkit">
                </a>
            </div>
            <h2>Reset Your Password</h2>
            <p>Enter your new password below</p>
        </div>
        
        <div class="user-info">
            <p>Resetting password for <strong><?php echo htmlspecialchars($reset['Email']); ?></strong></p>
        </div>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                if($_GET['error'] == 'password_mismatch') {
                    echo 'Passwords do not match.';
                } elseif($_GET['error'] == 'password_length') {
                    echo 'Password must be at least 8 characters.';
                } else {
                    echo 'An error occurred. Please try again.';
                }
                ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="reset_password_process.php">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <div class="form-group">
                <label for="password">New Password</label>
                <div class="password-toggle">
                    <input type="password" id="password" name="password" placeholder="Enter new password" required>
                    <button type="button" class="password-toggle-btn" onclick="togglePassword('password', 'toggleIcon1')">
                        <i class="fas fa-eye" id="toggleIcon1"></i>
                    </button>
                </div>
                <div class="password-requirements">
                    Must be at least 8 characters long
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <div class="password-toggle">
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                    <button type="button" class="password-toggle-btn" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                        <i class="fas fa-eye" id="toggleIcon2"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" class="btn-reset">
                <i class="fas fa-key"></i>
                Reset Password
            </button>
        </form>
    </div>
    
    <script>
        function togglePassword(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = document.getElementById(iconId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
