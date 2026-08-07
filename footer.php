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
  </div><!-- /.app-shell -->

<?php else: ?>
  <footer class="footer">
    <div class="container">
      <div class="footer-brand">Job<span>Portal</span></div>
      <div class="footer-links">
        <a href="index.php">Home</a>
        <a href="jobs.php">Browse Jobs</a>
        <a href="register-student.php">For Students</a>
        <a href="register-recruiter.php">For Recruiters</a>
      </div>
      <p class="footer-note">&copy; <?= date('Y') ?> JobPortal. Built as a student portfolio project.</p>
    </div>
  </footer>
<?php endif; ?>

</body>
</html>
