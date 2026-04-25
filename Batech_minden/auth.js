// ===== AUTH FUNKCIONALITÁS =====

document.addEventListener('DOMContentLoaded', function() {

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', handleLogin);
    }

    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', handleRegister);
    }

    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
});

function handleLogin(e) {
    e.preventDefault();

    const email = document.getElementById('email')?.value;
    const password = document.getElementById('password')?.value;

    if (!email || !password) {
        alert('Kérjük, töltse ki az összes mezőt!');
        return false;
    }

    this.submit();
}

function handleRegister(e) {
    e.preventDefault();

    const password = document.getElementById('password')?.value;
    const confirmPassword = document.getElementById('confirm_password')?.value ||
                            document.getElementById('confirmPassword')?.value;

    if (password && confirmPassword && password !== confirmPassword) {
        alert('A jelszavak nem egyeznek!');
        return false;
    }

    this.submit();
}

function handleLogout(e) {
    e.preventDefault();
    window.location.href = 'logout.php';
}

// ===== ÉRTESÍTÉSI RENDSZER =====
function initNotificationSystem() {
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    const markAllRead = document.getElementById('markAllRead');

    if (notificationBtn && notificationsDropdown) {
        notificationBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notificationsDropdown.classList.toggle('show');
        });

        document.addEventListener('click', (e) => {
            if (!notificationBtn.contains(e.target) &&
                !notificationsDropdown.contains(e.target)) {
                notificationsDropdown.classList.remove('show');
            }
        });
    }

    if (markAllRead) {
        markAllRead.addEventListener('click', function() {
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
        });
    }
}

// ===== KERESÉS =====
function initSearchFunctionality() {
    const searchConfig = {
        'bookingSearch': 'bookingsTable',
        'serviceSearch': 'servicesTable',
        'reviewSearch': 'reviewsTable',
        'userSearch': 'usersTable'
    };

    Object.entries(searchConfig).forEach(([inputId, targetId]) => {
        const input = document.getElementById(inputId);
        const target = document.getElementById(targetId);

        if (input && target) {
            input.addEventListener('keyup', debounce(function() {
                const searchTerm = this.value.toLowerCase();
                Array.from(target.getElementsByTagName('tr')).forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
                });
            }, 300));
        }
    });
}

// ===== SEGÉDFÜGGVÉNYEK =====
function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function showMessage(message, type = 'info') {
    const existingToast = document.querySelector('.toast-message');
    if (existingToast) existingToast.remove();

    const colors = { success: '#10b981', error: '#ef4444', info: '#3498db' };

    const toast = document.createElement('div');
    toast.className = `message ${type} toast-message`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed; bottom: 20px; right: 20px;
        background: ${colors[type] ?? '#64748b'};
        color: white; padding: 1rem 1.5rem;
        border-radius: 8px; z-index: 10000;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        animation: slideIn 0.3s ease;
    `;

    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function getStatusText(status) {
    const statusMap = {
        'pending':   'Függőben',
        'confirmed': 'Elfogadva',
        'completed': 'Teljesítve',
        'cancelled': 'Lemondva'
    };
    return statusMap[status] ?? 'Függőben';
}

const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
`;
document.head.appendChild(style);
