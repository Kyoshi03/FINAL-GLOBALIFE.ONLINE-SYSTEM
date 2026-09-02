<?php
if (!isset($pageTitle)) {
    $pageTitle = "Globalife Medical Laboratory & Polyclinic";
}
$currentUser = isLoggedIn() ? getCurrentUser() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="main.css">
    <?php if (isset($additionalStyles)): ?>
        <style><?php echo $additionalStyles; ?></style>
    <?php endif; ?>
    <style>
    /* Enhanced Professional Header */
    header {
        background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        color: #fff;
        padding: 0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        position: sticky;
        top: 0;
        z-index: 1000;
        transition: all 0.3s ease;
    }
    header.scrolled {
        padding: 5px 0;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.15);
    }
    .header-flex {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        padding: 18px 20px;
        max-width: 1400px;
        margin: 0 auto;
    }
    .logo-section {
        display: flex;
        align-items: center;
        gap: 15px;
        text-decoration: none;
        color: inherit;
        transition: transform 0.2s;
    }
    .logo-section:hover {
        transform: scale(1.02);
    }
    .logo-img {
        width: 55px;
        height: 55px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
        border: 3px solid rgba(255, 255, 255, 0.3);
        background: #fff;
        transition: all 0.3s ease;
    }
    .logo-img:hover {
        transform: scale(1.05) rotate(5deg);
        box-shadow: 0 6px 20px rgba(255, 255, 255, 0.3);
    }
    header h1 {
        font-size: 1.3rem;
        margin: 0;
        font-weight: 700;
        letter-spacing: -0.5px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    nav {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    nav a {
        color: rgba(255, 255, 255, 0.95);
        text-decoration: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        position: relative;
        white-space: nowrap;
    }
    nav a::before {
        content: "";
        position: absolute;
        bottom: 5px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 2px;
        background: #90e0ef;
        transition: width 0.3s ease;
    }
    nav a:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-2px);
    }
    nav a:hover::before {
        width: 70%;
    }
    nav a.active {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }
    nav a.active::before {
        width: 70%;
    }
    .login-btn, .logout-btn {
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        color: #fff;
        padding: 10px 24px;
        border-radius: 25px;
        font-weight: 600;
        text-decoration: none;
        border: 2px solid rgba(255, 255, 255, 0.3);
        transition: all 0.3s ease;
        cursor: pointer;
        font-size: 0.95rem;
        white-space: nowrap;
    }
    .login-btn:hover, .logout-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }
    .logout-btn {
        background: rgba(217, 4, 41, 0.2);
        border-color: rgba(217, 4, 41, 0.4);
    }
    .logout-btn:hover {
        background: rgba(217, 4, 41, 0.3);
        border-color: rgba(217, 4, 41, 0.6);
    }
    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 16px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 25px;
        margin-right: 10px;
    }
    .user-avatar {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-weight: 700;
        font-size: 0.9rem;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }
    .user-name {
        font-size: 0.9rem;
        font-weight: 600;
    }
    .mobile-menu-toggle {
        display: none;
        background: none;
        border: none;
        color: #fff;
        font-size: 1.8rem;
        cursor: pointer;
        padding: 5px 10px;
        border-radius: 5px;
        transition: background 0.2s;
    }
    .mobile-menu-toggle:hover {
        background: rgba(255, 255, 255, 0.1);
    }
    @media (max-width: 900px) {
        .mobile-menu-toggle {
            display: block;
        }
        nav {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            flex-direction: column;
            align-items: stretch;
            padding: 20px;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            transform: translateY(-100%);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        nav.active {
            transform: translateY(0);
            opacity: 1;
            visibility: visible;
        }
        nav a {
            padding: 12px 20px;
            border-radius: 8px;
        }
        header h1 {
            font-size: 1.1rem;
        }
        .logo-img {
            width: 45px;
            height: 45px;
        }
        .user-info {
            display: none;
        }
    }
    </style>
</head>
<body>
    <header id="mainHeader">
        <div class="header-flex">
            <a href="<?php echo isLoggedIn() ? dashboardForRole($currentUser['role']) : 'index.php'; ?>" class="logo-section">
                <img src="globalife.png" alt="Clinic Logo" class="logo-img">
                <h1>Globalife Medical Laboratory & Polyclinic</h1>
            </a>
            <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">☰</button>
            <nav id="mainNav">
                <?php if (isLoggedIn()): ?>
                    <?php if ($currentUser['role'] === 'admin'): ?>
                        <a href="admin.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin.php' ? 'active' : ''; ?>">Dashboard</a>
                        <a href="receptionist.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'receptionist.php' ? 'active' : ''; ?>">Reception Desk</a>
                        <a href="admin_accounts.php">Accounts</a>
                        <a href="view_appointments.php">Appointments</a>
                        <a href="#">Settings</a>
                    <?php elseif ($currentUser['role'] === 'doctor'): ?>
                        <a href="nurse.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'nurse.php' ? 'active' : ''; ?>">Dashboard</a>
                        <a href="view_appointments.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">Appointments</a>
                        <a href="nurse_patients.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'nurse_patients.php' ? 'active' : ''; ?>">Patient List</a>
                    <?php elseif ($currentUser['role'] === 'patient'): ?>
                        <div class="user-info">
                            <div class="user-avatar"><?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?></div>
                            <span class="user-name"><?php echo htmlspecialchars(explode(' ', $currentUser['full_name'])[0]); ?></span>
                        </div>
                        <a href="patients.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'patients.php' ? 'active' : ''; ?>">Dashboard</a>
                        <a href="#">My Appointment</a>
                        <a href="#">Book Appointment</a>
                        <a href="#">Doctor</a>
                    <?php endif; ?>
                    <form action="logout.php" method="post" style="display:inline;">
                        <button type="submit" class="logout-btn">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="index.php">Home</a>
                    <a href="#about">About</a>
                    <a href="#services">Services</a>
                    <a href="#contact">Contact</a>
                    <a href="login.php" class="login-btn">Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <script>
    function toggleMobileMenu() {
        const nav = document.getElementById('mainNav');
        nav.classList.toggle('active');
    }
    document.addEventListener('click', function(event) {
        const nav = document.getElementById('mainNav');
        const toggle = document.querySelector('.mobile-menu-toggle');
        if (!nav.contains(event.target) && !toggle.contains(event.target)) {
            nav.classList.remove('active');
        }
    });
    window.addEventListener('scroll', function() {
        const header = document.getElementById('mainHeader');
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });
    </script>
