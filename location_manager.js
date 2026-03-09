// Location Detection System
// Add this script to index.php and home.php

const LocationManager = {
    // Check if we have stored location
    hasStoredLocation: function() {
        return localStorage.getItem('user_latitude') && localStorage.getItem('user_longitude');
    },
    
    // Get stored location
    getStoredLocation: function() {
        return {
            latitude: parseFloat(localStorage.getItem('user_latitude')),
            longitude: parseFloat(localStorage.getItem('user_longitude')),
            source: localStorage.getItem('location_source') || 'unknown'
        };
    },
    
    // Store location
    storeLocation: function(lat, lng, source) {
        localStorage.setItem('user_latitude', lat);
        localStorage.setItem('user_longitude', lng);
        localStorage.setItem('location_source', source);
        localStorage.setItem('location_timestamp', Date.now());
    },
    
    // Request browser geolocation
    requestBrowserLocation: function() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation not supported'));
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    this.storeLocation(lat, lng, 'browser');
                    
                    // Send to server to store in session
                    fetch('store_location.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ latitude: lat, longitude: lng, source: 'browser' })
                    });
                    
                    resolve({ latitude: lat, longitude: lng, source: 'browser' });
                },
                (error) => {
                    console.log('Geolocation error:', error.message);
                    reject(error);
                },
                {
                    enableHighAccuracy: false,
                    timeout: 10000,
                    maximumAge: 300000 // Cache for 5 minutes
                }
            );
        });
    },
    
    // Initialize location detection
    initialize: async function() {
        // Check if location is stale (older than 24 hours)
        const timestamp = localStorage.getItem('location_timestamp');
        const isStale = !timestamp || (Date.now() - parseInt(timestamp)) > 86400000;
        
        // If no location or stale, request new one
        if (!this.hasStoredLocation() || isStale) {
            try {
                const location = await this.requestBrowserLocation();
                console.log('Location obtained:', location);
                return location;
            } catch (error) {
                console.log('Could not get browser location, will use fallback');
                return null;
            }
        } else {
            return this.getStoredLocation();
        }
    },
    
    // Show location prompt with custom UI
    showLocationPrompt: function() {
        const modal = document.createElement('div');
        modal.innerHTML = `
            <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;" id="locationModal">
                <div style="background: white; border-radius: 16px; padding: 30px; max-width: 400px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                    <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-map-marker-alt" style="font-size: 36px; color: white;"></i>
                    </div>
                    <h2 style="margin: 0 0 10px 0; color: #333;">Find Items Near You</h2>
                    <p style="color: #666; margin-bottom: 25px; line-height: 1.5;">
                        Enable location to see available items in your area. We'll only use this to show nearby listings.
                    </p>
                    <button onclick="LocationManager.acceptLocation()" style="width: 100%; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; margin-bottom: 10px;">
                        Enable Location
                    </button>
                    <button onclick="LocationManager.declineLocation()" style="width: 100%; padding: 15px; background: transparent; color: #666; border: none; font-size: 14px; cursor: pointer;">
                        Not Now
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    },
    
    acceptLocation: async function() {
        document.getElementById('locationModal')?.remove();
        try {
            await this.requestBrowserLocation();
            // Reload page to show location-based results
            window.location.reload();
        } catch (error) {
            alert('Unable to access your location. Please check your browser settings.');
        }
    },
    
    declineLocation: function() {
        document.getElementById('locationModal')?.remove();
        localStorage.setItem('location_prompt_dismissed', Date.now());
    },
    
    // Check if we should show prompt (not dismissed recently)
    shouldShowPrompt: function() {
        const dismissed = localStorage.getItem('location_prompt_dismissed');
        if (!dismissed) return true;
        
        // Show again after 7 days
        return (Date.now() - parseInt(dismissed)) > 604800000;
    }
};

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', async function() {
    // Only prompt if not logged in OR logged in but no location stored
    const isLoggedIn = document.body.dataset.loggedIn === 'true';
    const hasAccountLocation = document.body.dataset.hasAccountLocation === 'true';
    
    if (!LocationManager.hasStoredLocation() && LocationManager.shouldShowPrompt()) {
        // If not logged in, or logged in but no account address, show prompt
        if (!isLoggedIn || !hasAccountLocation) {
            LocationManager.showLocationPrompt();
        }
    } else {
        // Silently refresh location in background if stale
        LocationManager.initialize();
    }
});
