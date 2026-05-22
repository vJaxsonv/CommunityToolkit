<?php
/**
 * admin_auth.php
 * Include this at the very top of every admin page.
 * Redirects anyone who is not a logged-in admin back to the home page.
 */

// config.php starts the session, so require it first
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: ../index.php');
    exit;
}
?>
