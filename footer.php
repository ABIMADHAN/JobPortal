<?php
/**
 * footer.php
 * Shared page bottom. Mirrors the layout chosen in header.php.
 */

declare(strict_types=1);

$layout = $layout ?? 'site';
?>

<?php if ($layout === 'app'): ?>
    </div><!-- /.app-main -->
  </div><!-- /.app-body -->
</div><!-- /.app-shell -->

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
