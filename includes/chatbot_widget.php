<?php
/**
 * Community Toolkit — Global Widget Include
 * Includes: AI Chatbot CSS/JS, Voice Input JS
 * Add <?php require_once 'includes/chatbot_widget.php'; ?> before </body> on any page.
 * Widgets only render if the user is logged in.
 */
if (!empty($_SESSION['user_id'])):
    $__isAdmin = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false);
    $__base    = $__isAdmin ? '../' : '';
?>
    <link rel="stylesheet" href="<?php echo $__base; ?>css/ai_chatbot.css">
    <script src="<?php echo $__base; ?>js/ai_chatbot.js"></script>
    <script src="<?php echo $__base; ?>js/voice_input.js"></script>
<?php endif; ?>
