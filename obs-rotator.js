(function () {
  "use strict";

  function initRotator(root) {
    var items = root.querySelectorAll(".obs-rotator-item");
    var dots = root.querySelectorAll(".obs-rotator-dot");
    var intervalMs = parseInt(root.getAttribute("data-interval"), 10) || 0;
    var transition = root.getAttribute("data-transition") || "fade";

    if (items.length <= 1) return;

    var current = 0;
    var timer = null;
    var paused = false;

    root.classList.add("obs-transition-" + transition);

    function show(index) {
      if (index === current) return;
      items[current].classList.remove("is-active");
      items[index].classList.add("is-active");
      if (dots.length) {
        dots[current].classList.remove("is-active");
        dots[index].classList.add("is-active");
      }
      current = index;
    }

    function next() {
      show((current + 1) % items.length);
    }

    function start() {
      if (intervalMs <= 0) return;
      stop();
      timer = setInterval(function () {
        if (!paused) next();
      }, intervalMs);
    }

    function stop() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }

    // Dot navigation
    for (var i = 0; i < dots.length; i++) {
      (function (idx) {
        dots[idx].addEventListener("click", function () {
          show(idx);
          start(); // reset timer
        });
      })(i);
    }

    // Pause on hover (desktop) and on focus-within (keyboard/touch)
    root.addEventListener("mouseenter", function () {
      paused = true;
    });
    root.addEventListener("mouseleave", function () {
      paused = false;
    });
    root.addEventListener("focusin", function () {
      paused = true;
    });
    root.addEventListener("focusout", function () {
      paused = false;
    });

    // Pause when tab is hidden (saves CPU)
    document.addEventListener("visibilitychange", function () {
      paused = document.hidden;
    });

    start();
  }

  function boot() {
    var nodes = document.querySelectorAll(".obs-rotator");
    for (var i = 0; i < nodes.length; i++) initRotator(nodes[i]);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
