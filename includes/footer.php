    <footer>
        <div class="container">
            <?php
            $footerClinicName = (string) ($headerClinicInfo['clinic_name'] ?? 'Globalife Medical Laboratory & Polyclinic');
            ?>
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($footerClinicName); ?>. All rights reserved.</p>
        </div>
    </footer>
    <?php if (isset($additionalScripts)): ?>
        <script><?php echo $additionalScripts; ?></script>
    <?php endif; ?>
    <script src="birthday-picker.js?v=<?php echo (int) @filemtime(__DIR__ . '/../birthday-picker.js'); ?>"></script>
</body>
</html>





