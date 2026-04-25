// ===== FŐOLDAL FUNKCIONALITÁS =====
// XAMPP + RACKHOST kész, sötét téma támogatással!
// MINDEN FÜGGVÉNY JAVÍTVA, HIÁNYZÓ FÜGGVÉNYEKKEL KIEGÉSZÍTVE!

document.addEventListener('DOMContentLoaded', function() {
    // Mobil menü toggle
    setupMobileMenu();
    
    // Időpontfoglalás űrlap
    setupBookingForm();
    
    // Értékelés űrlap
    setupReviewForm();
    
    // Automatikus dátum beállítás
    setMinDateForBooking();
    
    // Sötét téma inicializálás
    initDarkMode();
    
    // Oldalspecifikus inicializálások
    initPageSpecific();
});

// ===== MENÜ =====
function setupMobileMenu() {
    const menuToggle = document.getElementById('menuToggle');
    const navLinks = document.getElementById('navLinks');
    
    if (menuToggle && navLinks) {
        menuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            navLinks.classList.toggle('active');
        });
        
        document.addEventListener('click', (e) => {
            if (!menuToggle.contains(e.target) && !navLinks.contains(e.target)) {
                navLinks.classList.remove('active');
            }
        });
    }
}

// ===== SÖTÉT TÉMA =====
function initDarkMode() {
    const themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;
    
    themeToggle.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Egyszerű link követés - a PHP kezeli a sütit
        window.location.href = this.href;
    });
}

// ===== IDŐPONTFOGLALÁS =====
function setupBookingForm() {
    const bookingForm = document.getElementById('bookingForm');
    if (!bookingForm) return;
    
    bookingForm.addEventListener('submit', function(e) {
        const serviceType = document.getElementById('serviceType')?.value;
        const bookingDate = document.getElementById('bookingDate')?.value;
        const bookingTime = document.getElementById('bookingTime')?.value;
        
        if (!serviceType || !bookingDate || !bookingTime) {
            e.preventDefault();
            alert('Kérjük, töltse ki az összes mezőt!');
            return false;
        }
        
        // Ha minden OK, a form elküldődik
        return true;
    });
}

// ===== ÉRTÉKELÉS =====
function setupReviewForm() {
    const reviewForm = document.getElementById('reviewForm');
    if (!reviewForm) return;
    
    reviewForm.addEventListener('submit', function(e) {
        const reviewerName = document.getElementById('reviewerName')?.value;
        const reviewRating = document.getElementById('reviewRating')?.value;
        const reviewText = document.getElementById('reviewText')?.value;
        
        if (!reviewerName || !reviewRating || !reviewText) {
            e.preventDefault();
            alert('Kérjük, töltse ki az összes mezőt!');
            return false;
        }
        
        return true;
    });
}

// ===== DÁTUM BEÁLLÍTÁS =====
function setMinDateForBooking() {
    const bookingDateInput = document.getElementById('bookingDate');
    if (bookingDateInput) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        
        const year = tomorrow.getFullYear();
        const month = String(tomorrow.getMonth() + 1).padStart(2, '0');
        const day = String(tomorrow.getDate()).padStart(2, '0');
        const formattedDate = `${year}-${month}-${day}`;
        
        bookingDateInput.min = formattedDate;
        
        // Alapértelmezett dátum beállítása ha üres
        if (!bookingDateInput.value) {
            bookingDateInput.value = formattedDate;
        }
    }
}

// ===== REFERENCIA SZŰRŐ ===== (referenciak.php)
function initReferenceFilters() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    const searchInput = document.getElementById('referenceSearch');
    
    if (filterButtons.length) {
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                filterButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                const cards = document.querySelectorAll('.reference-card');
                
                cards.forEach(card => {
                    if (filter === 'all' || card.dataset.category === filter) {
                        card.style.display = 'grid';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
    }
    
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const cards = document.querySelectorAll('.reference-card');
            
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                card.style.display = text.includes(searchTerm) ? 'grid' : 'none';
            });
        });
    }
}

// ===== JELSZÓ ERŐSSÉG JELZŐ ===== (register.php)
function initPasswordStrength() {
    const passwordInput = document.getElementById('password');
    const strengthBar = document.getElementById('passwordStrength');
    const strengthText = document.getElementById('passwordStrengthText');
    
    if (passwordInput && strengthBar) {
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            const levels = [
                { class: '', text: 'Adj meg jelszót' },
                { class: 'strength-weak', text: 'Gyenge' },
                { class: 'strength-medium', text: 'Közepes' },
                { class: 'strength-strong', text: 'Erős' },
                { class: 'strength-strong', text: 'Nagyon erős' }
            ];
            
            let level = 0;
            if (password.length >= 6) {
                if (strength <= 2) level = 1;
                else if (strength <= 4) level = 2;
                else level = 3;
                if (strength >= 5 && password.length >= 10) level = 4;
            }
            
            strengthBar.className = 'strength-bar-fill';
            if (level > 0) {
                strengthBar.classList.add(levels[level].class);
            }
            
            if (strengthText) {
                strengthText.textContent = levels[level].text;
                if (level >= 3) strengthText.style.color = '#10b981';
                else if (level === 2) strengthText.style.color = '#f59e0b';
                else if (level === 1) strengthText.style.color = '#ef4444';
                else strengthText.style.color = '#64748b';
            }
        });
    }
}

// ===== JELSZÓ EGYEZÉS ELLENŐRZÉS ===== (register.php)
function initPasswordMatch() {
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    
    if (passwordInput && confirmInput) {
        function checkMatch() {
            if (confirmInput.value.length > 0) {
                confirmInput.style.borderColor = passwordInput.value === confirmInput.value ? '#10b981' : '#ef4444';
            } else {
                confirmInput.style.borderColor = '#e2e8f0';
            }
        }
        
        passwordInput.addEventListener('input', checkMatch);
        confirmInput.addEventListener('input', checkMatch);
    }
}

// ===== CHART INICIALIZÁLÁS ===== (admin.php)
function initBookingChart() {
    const canvas = document.getElementById('bookingsChart');
    if (!canvas || typeof Chart === 'undefined') return;
    
    const ctx = canvas.getContext('2d');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'],
            datasets: [{
                label: 'Foglalások',
                data: [12, 19, 8, 15, 22, 18, 10],
                borderColor: '#3498db',
                backgroundColor: 'rgba(52,152,219,0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#3498db'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
}

// ===== PROFILKÉP ELŐNÉZET ===== (profil.php)
function initAvatarPreview() {
    const avatarInput = document.getElementById('avatar');
    const avatarPreview = document.getElementById('avatarPreview');
    const avatarContainer = document.querySelector('.profile-avatar img');
    
    if (avatarInput && avatarContainer) {
        avatarInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    avatarContainer.src = e.target.result;
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
}

// ===== PROFIL TABOK ===== (profil.php)
function initProfileTabs() {
    const tabBtns = document.querySelectorAll('.profile-tab-btn');
    
    if (tabBtns.length) {
        tabBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                tabBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const tabId = this.dataset.tab;
                document.querySelectorAll('.profile-tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(tabId)?.classList.add('active');
            });
        });
    }
}

// ===== ADMIN TABOK ===== (admin.php)
function initAdminTabs() {
    const menuLinks = document.querySelectorAll('.admin-menu a');
    
    if (menuLinks.length) {
        menuLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                menuLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
                
                const tabId = this.dataset.tab;
                document.querySelectorAll('.admin-tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                document.getElementById(tabId)?.classList.add('active');
            });
        });
    }
}

// ===== ÉRTESÍTÉSI RENDSZER ===== (admin.php)
function initNotifications() {
    const notiBtn = document.getElementById('notificationBtn');
    const notiDropdown = document.getElementById('notificationsDropdown');
    
    if (notiBtn && notiDropdown) {
        notiBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notiDropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', function(e) {
            if (!notiBtn.contains(e.target) && !notiDropdown.contains(e.target)) {
                notiDropdown.classList.remove('show');
            }
        });
    }
    
    // Mark all as read
    const markAllBtn = document.getElementById('markAllRead');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            const badge = document.getElementById('notificationCount');
            if (badge) badge.textContent = '0';
        });
    }
}

// ===== KERESÉS TÁBLÁZATBAN ===== (admin.php)
function initTableSearch() {
    const searchConfigs = [
        { input: 'bookingSearch', table: 'bookingsTable' },
        { input: 'serviceSearch', table: 'servicesTable' },
        { input: 'reviewSearch', table: 'reviewsTable' },
        { input: 'userSearch', table: 'usersTable' }
    ];
    
    searchConfigs.forEach(config => {
        const input = document.getElementById(config.input);
        const table = document.getElementById(config.table);
        
        if (input && table) {
            input.addEventListener('keyup', function() {
                const term = this.value.toLowerCase();
                const rows = table.getElementsByTagName('tr');
                
                for (let row of rows) {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(term) ? '' : 'none';
                }
            });
        }
    });
}

// ===== KIJELENTKEZÉS =====
function initLogout() {
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
            window.location.href = 'logout.php';
        });
    }
}

// ===== OLDALSPECIFIKUS INICIALIZÁLÁS =====
function initPageSpecific() {
    // Főoldal
    if (document.querySelector('.hero')) {
        // Nincs speciális funkció
    }
    
    // Referencia oldal
    if (document.querySelector('.references-filter')) {
        initReferenceFilters();
    }
    
    // Regisztráció oldal
    if (document.getElementById('registerForm')) {
        initPasswordStrength();
        initPasswordMatch();
    }
    
    // Admin oldal
    if (document.querySelector('.admin-container')) {
        initAdminTabs();
        initNotifications();
        initTableSearch();
        initBookingChart();
        initLogout();
    }
    
    // Profil oldal
    if (document.querySelector('.profile-tabs')) {
        initProfileTabs();
        initAvatarPreview();
    }
}

