<?php
/**
 * footer.php
 * Shared page bottom. Mirrors the layout chosen in header.php.
 */

declare(strict_types=1);

$layout = $layout ?? 'site';
?>

<?php if ($layout === 'app'): ?>
    </main>
</div><!-- /.app-layout -->

<a href="profile.php" class="floating-settings-btn" title="Quick Settings">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
</a>

<?php else: ?>
  <footer class="footer">
    <div class="container">
      <div class="footer-brand" style="display: flex; align-items: center; justify-content: center; gap: 8px;">
        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1; font-size: 22px;">work</span>
        Career<span>Studio</span>
      </div>
      <div class="footer-links">
        <a href="index.php">Home</a>
        <a href="jobs.php">Browse Jobs</a>
        <a href="register-student.php">For Students</a>
        <a href="register-recruiter.php">For Recruiters</a>
      </div>
      <p class="footer-note">&copy; <?= date('Y') ?> CareerStudio. Precision in Professional Growth.</p>
    </div>
  </footer>
<?php endif; ?>

</body>
</html>
