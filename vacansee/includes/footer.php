<?php
/**
 * Shared footer helper for VACANSEE public pages.
 * Call renderFooter() to output the public footer.
 */

if (!function_exists('renderFooter')) {
    /**
     * Render the public footer.
     *
     * @param string $basePath Optional prefix for asset/anchor paths (e.g., "../").
     */
    function renderFooter($basePath = '')
    {
        $base = rtrim($basePath, '/');
        if ($base !== '') {
            $base .= '/';
        }
        ?>
        <footer class="footer" style="background: var(--bg-light); color: var(--text-dark); padding: 1.5rem 0;">
            <div class="container" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <p style="margin: 0;">&copy; <?php echo date('Y'); ?> VACANSEE - University of Mindanao Visayan Campus. All rights reserved.</p>
                <p class="version" style="margin: 0; color: var(--text-light);">Version 1.0.0</p>
            </div>
        </footer>
        <?php
    }
}
