</div><!-- /container-fluid -->

<footer class="footer mt-auto py-3 bg-light border-top">
  <div class="container-fluid text-center text-muted small">
    <?= htmlspecialchars(setting('site_name', 'Network Monitor')) ?> &mdash;
    <a href="monitor.php" class="text-muted text-decoration-none" title="Run checks manually (web trigger)">
      <i class="bi bi-play-circle"></i> Run checks now
    </a>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
// Auto-refresh dashboard every 60 s if on index page
if (window.location.pathname.endsWith('index.php') || window.location.pathname.endsWith('/')) {
    setTimeout(() => location.reload(), 60000);
}
</script>
</body>
</html>
