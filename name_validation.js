/* Name Validation Script
   Add this to any page with name input fields (register.php, profile.php, etc.)
   
   Allowed characters:
   - Letters (any language/script)
   - Spaces
   - Periods (.)
   - Hyphens (-)
   - Apostrophes (')
   - Commas (,)
   
   Not allowed:
   - Numbers (0-9)
   - Other punctuation or symbols
*/

function validateName(input) {
    // Regex: allows letters (any unicode), spaces, periods, hyphens, apostrophes, commas
    // ^ = start, $ = end, \p{L} = any unicode letter, \s = space
    const namePattern = /^[\p{L}\s.',-]+$/u;
    
    const value = input.value.trim();
    
    if (value === '') {
        return true; // Allow empty for optional fields
    }
    
    if (!namePattern.test(value)) {
        return false;
    }
    
    return true;
}

function setupNameValidation(inputElement, errorElementId) {
    inputElement.addEventListener('blur', function() {
        const errorElement = document.getElementById(errorElementId);
        
        if (!validateName(this)) {
            this.style.borderColor = '#ff4444';
            if (errorElement) {
                errorElement.textContent = 'Name can only contain letters, spaces, periods, hyphens, apostrophes, and commas';
                errorElement.style.color = '#ff4444';
                errorElement.style.fontSize = '13px';
                errorElement.style.marginTop = '4px';
            }
        } else {
            this.style.borderColor = '';
            if (errorElement) {
                errorElement.textContent = '';
            }
        }
    });
    
    // Real-time validation - prevent invalid characters from being typed
    inputElement.addEventListener('input', function(e) {
        const cursorPosition = this.selectionStart;
        const originalValue = this.value;
        
        // Remove any characters that aren't letters, spaces, or allowed punctuation
        this.value = this.value.replace(/[^\p{L}\s.',-]/gu, '');
        
        // Restore cursor position if characters were removed
        if (originalValue !== this.value) {
            const diff = originalValue.length - this.value.length;
            this.setSelectionRange(cursorPosition - diff, cursorPosition - diff);
        }
    });
}

// Auto-setup for common name field IDs
document.addEventListener('DOMContentLoaded', function() {
    // First Name
    const firstNameInput = document.getElementById('firstname') || document.getElementById('FirstName');
    if (firstNameInput) {
        const errorDiv = document.createElement('div');
        errorDiv.id = 'firstname-error';
        firstNameInput.parentNode.appendChild(errorDiv);
        setupNameValidation(firstNameInput, 'firstname-error');
    }
    
    // Last Name
    const lastNameInput = document.getElementById('lastname') || document.getElementById('LastName');
    if (lastNameInput) {
        const errorDiv = document.createElement('div');
        errorDiv.id = 'lastname-error';
        lastNameInput.parentNode.appendChild(errorDiv);
        setupNameValidation(lastNameInput, 'lastname-error');
    }
});
