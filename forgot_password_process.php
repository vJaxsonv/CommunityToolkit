<?php
require_once 'config.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit;
}

$email = trim($_POST['email'] ?? '');

// Validate email
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: forgot_password.php?error=invalid_email');
    exit;
}

try {
    // Check if user exists
    $stmt = $pdo->prepare("SELECT UserID, FirstName, Email FROM TUsers WHERE Email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // SECURITY: Don't reveal if email exists or not (prevent email enumeration)
    // Always show success message, but only send email if user exists
    if ($user) {
        // Generate secure random token
        $token = bin2hex(random_bytes(32)); // 64 character token
        
        // Set expiration time (1 hour from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database
        $stmt = $pdo->prepare("
            INSERT INTO TPasswordResets (UserID, Token, ExpiresAt) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user['UserID'], $token, $expiresAt]);
        
        // Create reset link
        $resetLink = "https://thecommunitytoolkit.com/reset_password.php?token=" . $token;
        
        // Email subject
        $subject = "Password Reset - Community Toolkit";
        
        // Email body (HTML)
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                .button { display: inline-block; padding: 15px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; }
                .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #999; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Password Reset Request</h1>
                </div>
                <div class='content'>
                    <p>Hi " . htmlspecialchars($user['FirstName']) . ",</p>
                    
                    <p>We received a request to reset your password for your Community Toolkit account.</p>
                    
                    <p>Click the button below to reset your password:</p>
                    
                    <p style='text-align: center;'>
                        <a href='" . $resetLink . "' class='button'>Reset My Password</a>
                    </p>
                    
                    <p>Or copy and paste this link into your browser:</p>
                    <p style='word-break: break-all; background: #fff; padding: 10px; border-radius: 4px; font-size: 12px;'>" . $resetLink . "</p>
                    
                    <p><strong>This link will expire in 1 hour.</strong></p>
                    
                    <p>If you didn't request this password reset, you can safely ignore this email. Your password will remain unchanged.</p>
                    
                    <p>Thanks,<br>The Community Toolkit Team</p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply to this message.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Plain text version (fallback)
        $plainMessage = "Hi " . $user['FirstName'] . ",\n\n";
        $plainMessage .= "We received a request to reset your password for your Community Toolkit account.\n\n";
        $plainMessage .= "Click this link to reset your password:\n";
        $plainMessage .= $resetLink . "\n\n";
        $plainMessage .= "This link will expire in 1 hour.\n\n";
        $plainMessage .= "If you didn't request this password reset, you can safely ignore this email.\n\n";
        $plainMessage .= "Thanks,\nThe Community Toolkit Team";
        
        // Email headers
        $headers = "From: Community Toolkit <noreply@thecommunitytoolkit.com>\r\n";
        $headers .= "Reply-To: noreply@thecommunitytoolkit.com\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"boundary123\"\r\n";
        
        // Create multipart email (HTML + plain text)
        $emailBody = "--boundary123\r\n";
        $emailBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $emailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $emailBody .= $plainMessage . "\r\n\r\n";
        $emailBody .= "--boundary123\r\n";
        $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
        $emailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $emailBody .= $message . "\r\n\r\n";
        $emailBody .= "--boundary123--";
        
        // Send email
        $emailSent = mail($user['Email'], $subject, $emailBody, $headers);
        
        // Log if email failed (for debugging)
        if (!$emailSent) {
            error_log("Password reset email failed to send to: " . $user['Email']);
        }
    }
    
    // ALWAYS redirect to success (security - don't reveal if email exists)
    header('Location: forgot_password.php?success=1');
    exit;
    
} catch (PDOException $e) {
    error_log("Password reset error: " . $e->getMessage());
    header('Location: forgot_password.php?error=database');
    exit;
}
?>
