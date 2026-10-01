jQuery(function ($) {
  function initReview(root) {
   var $root   = $(root);
  var mode    = $root.data('mode');

  // Two ways data can arrive:
  //   1. Front-end shortcode: inline JSON block inside the wrapper
  //   2. Admin page: wp_localize_script payload (obsReview.comments)
  var comments = [];
  if (typeof obsReview !== 'undefined' && Array.isArray(obsReview.comments)) {
      comments = obsReview.comments;
  } else {
      var $data = $root.find('.obs-review-data');
      try { comments = JSON.parse($data.text() || '[]'); } catch (e) { comments = []; }
  }
  if (!comments.length) return;

    var idx = 0;

    var $quote = $root.find(".obs-review-quote");
    var $author = $root.find(".obs-review-author");
    var $country = $root.find(".obs-review-country");
    var $usage = $root.find(".obs-review-usage");
    var $tags = $root.find(".obs-review-tags");
    var $current = $root.find(".obs-review-current");
    var $featureBtn = $root.find(".obs-review-feature-btn");
    var $featureLabel = $root.find(".obs-review-feature-label");
    var $logWrap = $root.find(".obs-review-log");

    function render() {
      var c = comments[idx];
      $quote.text(c.text);
      $author.text(c.author ? "— " + c.author : "");
      $country.text(c.countryName || "");
      $usage.text(
        c.uses
          ? "(" + c.uses + " time" + (c.uses === 1 ? "" : "s") + " used)"
          : "(never used)",
      );
      $tags.html("");
      if (c.tags) {
        c.tags.split(",").forEach(function (t) {
          t = t.trim();
          if (t) $tags.append('<span class="obs-review-tag">' + t + "</span>");
        });
      }
      $current.text(idx + 1);
      if ($featureBtn.length) {
        $featureBtn.toggleClass("is-featured", !!c.featured);
        $featureLabel.text(c.featured ? "Featured" : "Feature");
      }
      if ($logWrap.length) {
        $logWrap.show();
        $root.find(".obs-review-log-msg").text("");
      }
      // Update the "Edit in admin" link if present
      var $editLink = $root.find('#obs-review-edit-link');
      if ($editLink.length && typeof obsReview !== 'undefined' && obsReview.editUrlBase) {
          $editLink.attr('href', obsReview.editUrlBase + c.id);
      }
    }

    function go(n) {
      idx = (n + comments.length) % comments.length;
      render();
    }

    $root.find(".obs-review-next").on("click", function () {
      go(idx + 1);
    });
    $root.find(".obs-review-prev").on("click", function () {
      go(idx - 1);
    });
    $root.find(".obs-review-random").on("click", function () {
      go(Math.floor(Math.random() * comments.length));
    });

    // Keyboard arrows when the review block is in view (only when a comment is focused or page has a single review)
    $(document).on("keydown", function (e) {
      if (
        e.target.tagName === "INPUT" ||
        e.target.tagName === "TEXTAREA" ||
        e.target.tagName === "SELECT"
      )
        return;
      if (e.key === "ArrowRight") {
        go(idx + 1);
      }
      if (e.key === "ArrowLeft") {
        go(idx - 1);
      }
    });

    // Feature toggle
    $featureBtn.on("click", function () {
      var c = comments[idx];
      $.post(
        obsReview.ajaxUrl,
        {
          action: "obs_review_toggle_featured",
          nonce: obsReview.nonce,
          id: c.id,
        },
        function (res) {
          if (res && res.success) {
            c.featured = res.data.featured;
            render();
          }
        },
      );
    });

    // Log usage — show/hide new place field
    $root.find(".obs-review-place").on("change", function () {
      if ($(this).val() === "__new__") {
        $root.find(".obs-review-place-new").show().focus();
      } else {
        $root.find(".obs-review-place-new").hide().val("");
      }
    });

    $root.find(".obs-review-log-save").on("click", function () {
      var c = comments[idx];
      var place = $root.find(".obs-review-place").val();
      if (place === "__new__")
        place = $root.find(".obs-review-place-new").val().trim();
      if (!place) {
        alert("Choose or enter a place.");
        return;
      }

      var recs = $root
        .find(".obs-review-rec:checked")
        .map(function () {
          return this.value;
        })
        .get();

      $.post(
        obsReview.ajaxUrl,
        {
          action: "obs_review_log_usage",
          nonce: obsReview.nonce,
          id: c.id,
          place: place,
          month: $root.find(".obs-review-month").val(),
          year: $root.find(".obs-review-year").val(),
          recipients: recs,
        },
        function (res) {
          if (res && res.success) {
            c.uses = res.data.uses;
            $root.find(".obs-review-log-msg").text("Logged ✓");
            render();
          } else {
            $root.find(".obs-review-log-msg").text("Error");
          }
        },
      );
    });

    render();
  }

  $(".obs-review").each(function () {
    initReview(this);
  });
});
