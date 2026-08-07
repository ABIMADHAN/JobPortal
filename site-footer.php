<?php
/**
 * site-footer.php
 * Shared bottom of the public "CareerStudio" pages. Closes <body>/<html>.
 */

declare(strict_types=1);
?>
  <!-- Footer -->
  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="footer-col footer-col-wide">
        <div class="headline-md text-primary" style="display: flex; align-items: center; gap: 8px;">
          <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">work</span>
          CareerStudio
        </div>
        <p class="body-sm text-variant" style="max-width: 280px; margin-top: 16px;">
          &copy; <?= date('Y') ?> CareerStudio AI. Precision in Professional Growth.
        </p>
      </div>

      <div class="footer-col">
        <h4 class="label-sm footer-heading">Explore</h4>
        <a class="body-sm footer-link" href="jobs.php">Browse Jobs</a>
        <a class="body-sm footer-link" href="companies.php">Companies</a>
        <a class="body-sm footer-link" href="internships.php">Internships</a>
        <a class="body-sm footer-link" href="remote.php">Remote Jobs</a>
      </div>

      <div class="footer-col">
        <h4 class="label-sm footer-heading">Resources</h4>
        <a class="body-sm footer-link" href="resources.php">Career Hub</a>
        <a class="body-sm footer-link" href="resources.php#skills">Skills in Demand</a>
        <a class="body-sm footer-link" href="resources.php#guides">Guides</a>
      </div>

      <div class="footer-col footer-col-wide">
        <h4 class="label-sm footer-heading">Subscribe</h4>
        <p class="body-sm text-variant" style="margin-bottom: 8px;">Get the latest job alerts and career insights
          delivered to your inbox.</p>
        <form class="subscribe-form" action="register-student.php" method="get">
          <input class="body-sm" name="email" placeholder="Email address" type="email">
          <button class="btn btn-sm btn-dark" type="submit">Subscribe</button>
        </form>
      </div>
    </div>
  </footer>
</body>

</html>
