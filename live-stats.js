/**
 * live-stats.js
 * Keeps every [data-live="<key>"] element in sync with live-stats.php.
 *
 * Usage in a page:
 *   <span data-live="open_jobs">12</span>
 *   <script src="live-stats.js" defer></script>
 *
 * Polls every 15s, pauses while the tab is hidden so it never hammers MySQL,
 * and briefly flashes any number that actually changed.
 */
(function () {
  var INTERVAL = 15000;
  var timer = null;

  function targets() {
    return document.querySelectorAll('[data-live]');
  }

  function paint(data) {
    targets().forEach(function (el) {
      var key = el.getAttribute('data-live');
      if (!(key in data)) return;

      var next = String(data[key]);
      if (el.textContent.trim() === next) return;

      el.textContent = next;
      el.classList.add('bumped');
      setTimeout(function () {
        el.classList.remove('bumped');
      }, 1200);
    });

    var stamp = document.getElementById('live-updated');
    if (stamp) {
      stamp.textContent = new Date().toLocaleTimeString();
    }
  }

  function refresh() {
    if (document.hidden || !targets().length) return;

    fetch('live-stats.php', { headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) { if (data) paint(data); })
      .catch(function () { /* offline or MySQL down: keep the rendered numbers */ });
  }

  function start() {
    if (timer) clearInterval(timer);
    timer = setInterval(refresh, INTERVAL);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refresh();
  });

  start();
  refresh();
})();
