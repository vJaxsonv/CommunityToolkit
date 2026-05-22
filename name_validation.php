<?php
/**
 * Name Validation Functions
 * Include this in your validation files or at the top of form processing scripts
 * 
 * Allowed characters in names:
 * - Letters (any language/script)
 * - Spaces
 * - Periods (.)
 * - Hyphens (-)
 * - Apostrophes (')
 * - Commas (,)
 * 
 * NOT allowed:
 * - Numbers (0-9)
 * - Other punctuation or symbols
 */

/**
 * Validate a name field
 * 
 * @param string $name The name to validate
 * @param string $fieldName The field name for error messages
 * @param bool $required Whether the field is required
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validateName($name, $fieldName = 'Name', $required = true) {
    $name = trim($name);
    
    // Check if empty
    if (empty($name)) {
        if ($required) {
            return [
                'valid' => false,
                'error' => "$fieldName is required"
            ];
        }
        return ['valid' => true, 'error' => null];
    }
    
    // Check length (reasonable limits)
    if (mb_strlen($name) > 100) {
        return [
            'valid' => false,
            'error' => "$fieldName must be less than 100 characters"
        ];
    }
    
    // Check for valid characters
    // Pattern allows: Unicode letters, spaces, periods, hyphens, apostrophes, commas
    if (!preg_match("/^[\p{L}\s.',-]+$/u", $name)) {
        return [
            'valid' => false,
            'error' => "$fieldName can only contain letters, spaces, periods, hyphens, apostrophes, and commas"
        ];
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate first and last name together
 * 
 * @param string $firstName
 * @param string $lastName
 * @return array ['valid' => bool, 'errors' => array]
 */
function validateFullName($firstName, $lastName) {
    $errors = [];
    
    $firstNameResult = validateName($firstName, 'First name', true);
    if (!$firstNameResult['valid']) {
        $errors['firstname'] = $firstNameResult['error'];
    }
    
    $lastNameResult = validateName($lastName, 'Last name', true);
    if (!$lastNameResult['valid']) {
        $errors['lastname'] = $lastNameResult['error'];
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Sanitize name for database storage
 * Removes any invalid characters while preserving valid ones
 * 
 * @param string $name
 * @return string
 */
function sanitizeName($name) {
    $name = trim($name);
    // Remove any characters that aren't letters, spaces, or allowed punctuation
    $name = preg_replace("/[^\p{L}\s.',-]/u", '', $name);
    // Remove multiple spaces
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

// Example usage in registration or profile update:
/*
// In register_process.php or profile_update.php:

$firstName = $_POST['firstname'] ?? '';
$lastName = $_POST['lastname'] ?? '';

// Sanitize first
$firstName = sanitizeName($firstName);
$lastName = sanitizeName($lastName);

// Then validate
$validation = validateFullName($firstName, $lastName);

if (!$validation['valid']) {
    // Handle errors
    foreach ($validation['errors'] as $field => $error) {
        echo $error . "<br>";
    }
    exit;
}

// Proceed with database insert/update
*/
?>
