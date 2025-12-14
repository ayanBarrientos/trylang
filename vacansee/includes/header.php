<?php
/**
 * Shared header helpers for VACANSEE.
 * Call renderNavbar() from any page that has loaded the base CSS.
 */

if (!function_exists('renderNavbar')) {
    /**
     * Render the public navbar.
     *
     * @param string $active    One of: home, about, features, contact
     * @param string $basePath  Optional prefix for asset/anchor paths (e.g., "../")
     */
    function renderNavbar($active = 'home', $basePath = '')
    {
        $base = rtrim($basePath, '/');
        if ($base !== '') {
            $base .= '/';
        }
        ?>
        <nav class="navbar">
            <div class="container nav-container">
                <div class="logo-container">
                    <img src="<?php echo $base; ?>assets/images/UM-Tagum-College-1950-removebg-preview.png" alt="UM Logo" class="logo">
                    <div class="logo-text">
                        <h1 class="university-name">UNIVERSITY OF MINDANAO</h1>
                        <h2 class="campus-name">Visayan Campus</h2>
                    </div>
                </div>
                
                <div class="nav-menu" id="navMenu">
                    <a href="<?php echo $base; ?>#home" class="nav-link <?php echo $active === 'home' ? 'active' : ''; ?>">Home</a>
                    <a href="<?php echo $base; ?>#about" class="nav-link <?php echo $active === 'about' ? 'active' : ''; ?>">About</a>
                    <a href="<?php echo $base; ?>#features" class="nav-link <?php echo $active === 'features' ? 'active' : ''; ?>">Features</a>
                    <a href="<?php echo $base; ?>#contact" class="nav-link <?php echo $active === 'contact' ? 'active' : ''; ?>">Contact</a>
                    <button class="btn-login" onclick="window.location.href='<?php echo $base; ?>login.php'">Login</button>
                </div>
                
                <button class="menu-toggle" onclick="document.getElementById('navMenu').classList.toggle('active')">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </nav>
        <?php
    }
}
