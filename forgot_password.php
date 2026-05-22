<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Community Toolkit</title>
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
            line-height: 1.5;
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
        
        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }
        
        .alert-info {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #90caf9;
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
        
        .back-login {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-login a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }
        
        .back-login a:hover {
            text-decoration: underline;
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
            <h2>Forgot Your Password?</h2>
            <p>Enter your email address and we'll send you a link to reset your password.</p>
        </div>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                if($_GET['error'] == 'email_not_found') {
                    echo 'No account found with that email address.';
                } else {
                    echo 'An error occurred. Please try again.';
                }
                ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                Password reset link sent! Check your email.
            </div>
        <?php endif; ?>
        
        <form method="POST" action="forgot_password_process.php">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="your.email@example.com" required autofocus>
            </div>
            
            <button type="submit" class="btn-reset">
                <i class="fas fa-paper-plane"></i>
                Send Reset Link
            </button>
        </form>
        
        <div class="back-login">
            <a href="login.php">
                <i class="fas fa-arrow-left"></i>
                Back to Login
            </a>
        </div>
    </div>
</body>
</html>
