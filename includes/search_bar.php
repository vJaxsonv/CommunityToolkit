<div class="live-search">
    <div class="live-search-wrap">
        <input type="text" id="liveSearch" class="live-search-input" placeholder="Search items near you...">
        <button type="button" id="clearSearchBtn" class="live-search-clear">&times;</button>
    </div>
    <div id="searchResults" class="live-search-results"></div>
</div>

<script>
const searchInput = document.getElementById("liveSearch");
const searchResults = document.getElementById("searchResults");
const clearSearchBtn = document.getElementById("clearSearchBtn");

let searchTimeout;
const HISTORY_KEY = "recent_item_searches";
const MAX_HISTORY = 6;

function getSearchHistory() {
    try {
        return JSON.parse(localStorage.getItem(HISTORY_KEY)) || [];
    } catch {
        return [];
    }
}

function saveSearchTerm(term) {
    const cleanTerm = term.trim();
    if (!cleanTerm) return;

    let history = getSearchHistory();

    history = history.filter(item => item.toLowerCase() !== cleanTerm.toLowerCase());
    history.unshift(cleanTerm);

    if (history.length > MAX_HISTORY) {
        history = history.slice(0, MAX_HISTORY);
    }

    localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
}

function clearSearchHistory() {
    localStorage.removeItem(HISTORY_KEY);
}

function updateClearButton() {
    clearSearchBtn.style.display = searchInput.value.trim() ? "block" : "none";
}

function hideResults() {
    searchResults.style.display = "none";
    searchResults.innerHTML = "";
}

function showHistory() {
    const history = getSearchHistory();

    if (!history.length) {
        hideResults();
        return;
    }

    searchResults.innerHTML = `
    <div class="live-search-history-header">
        <span>Recent searches</span>
        <button type="button" class="live-search-clear-history" id="clearHistoryBtn">Clear</button>
    </div>
`;

    history.forEach(term => {
        const item = document.createElement("div");
        item.className = "live-search-history-item";
        item.innerHTML = `
            <span style="font-size:16px;">&#128338;</span>
            <span class="live-search-history-text">${term}</span>
        `;

        item.addEventListener("click", function () {
            searchInput.value = term;
            updateClearButton();
            runSearch(term);
        });

        searchResults.appendChild(item);
    });

    searchResults.style.display = "block";

    const clearHistoryBtn = document.getElementById("clearHistoryBtn");
    if (clearHistoryBtn) {
        clearHistoryBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            clearSearchHistory();
            hideResults();
        });
    }
}

function runSearch(query) {
    if (query.length < 2) {
        showHistory();
        return;
    }

    fetch("/search_items_ajax.php?q=" + encodeURIComponent(query))
        .then(response => response.json())
        .then(data => {
            searchResults.innerHTML = "";

            if (!data.length) {
                searchResults.innerHTML = '<div class="live-search-no-results">No items found</div>';
                searchResults.style.display = "block";
                return;
            }

            data.forEach(item => {
                const link = document.createElement("a");
                link.href = "/item_detail.php?id=" + item.ListingID;
                link.className = "live-search-item";

                const mediaUrl = item.PhotoURL && item.PhotoURL.trim() !== ""
                    ? item.PhotoURL
                    : "/uploads/default.png";

                const ext = mediaUrl.split('?')[0].split('.').pop().toLowerCase();
                const videoExts = ['mp4', 'webm', 'ogg', 'mov'];
                const isVideo = videoExts.includes(ext);

                const priceText = item.PricePerDay !== null && item.PricePerDay !== ""
                    ? `$${parseFloat(item.PricePerDay).toFixed(2)}/day`
                    : "Price unavailable";

                const mediaHtml = isVideo
                    ? `
                        <video autoplay muted loop playsinline preload="metadata" class="live-search-thumb">
                            <source src="${mediaUrl}" type="video/${ext === 'mov' ? 'mp4' : ext}">
                        </video>
                    `
                    : `<img src="${mediaUrl}" alt="${item.Title}" class="live-search-thumb">`;

                link.innerHTML = `
                    ${mediaHtml}
                    <div class="live-search-info">
                        <div class="live-search-title">${item.Title}</div>
                        <div class="live-search-meta">
                            ${item.CategoryName || "Uncategorized"} • ${priceText}
                        </div>
                    </div>
                `;

                link.addEventListener("click", function () {
                    saveSearchTerm(query);
                });

                searchResults.appendChild(link);
            });

            searchResults.style.display = "block";
        })
        .catch(error => {
            console.error("Search error:", error);
            searchResults.innerHTML = '<div class="no-results">Search failed</div>';
            searchResults.style.display = "block";
        });
}

if (searchInput && searchResults && clearSearchBtn) {
    searchInput.addEventListener("input", function () {
        clearTimeout(searchTimeout);
        const query = this.value.trim();

        updateClearButton();

        searchTimeout = setTimeout(() => {
            runSearch(query);
        }, 250);
    });

    searchInput.addEventListener("focus", function () {
        if (!this.value.trim()) {
            showHistory();
        }
    });

    searchInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
            e.preventDefault();
            const query = this.value.trim();

            if (query) {
                saveSearchTerm(query);
                window.location.href = "/home.php?search=" + encodeURIComponent(query);
            }
        }
    });

    clearSearchBtn.addEventListener("click", function () {
        searchInput.value = "";
        updateClearButton();
        searchInput.focus();
        showHistory();
    });

    document.addEventListener("click", function (e) {
        const searchBox = document.querySelector(".live-search");
        if (searchBox && !searchBox.contains(e.target)) {
            hideResults();
        }
    });

    updateClearButton();
}
</script>