jQuery(function ($) {
  /* ---------- Copy with fallback (Improvement #1) ---------- */
  function copyText(text, $btn) {
    function success() {
      var orig = $btn.text();
      $btn.text(obsData.copiedMsg);
      setTimeout(function () {
        $btn.text(orig);
      }, 1500);
    }
    function fallback() {
      var $ta = $("<textarea>")
        .val(text)
        .css({ position: "fixed", top: "-1000px", left: "-1000px" })
        .appendTo("body");
      $ta[0].select();
      $ta[0].setSelectionRange(0, text.length);
      var ok = false;
      try {
        ok = document.execCommand("copy");
      } catch (e) {
        ok = false;
      }
      $ta.remove();
      if (ok) {
        success();
      } else {
        alert(obsData.failedMsg);
      }
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(success).catch(fallback);
    } else {
      fallback();
    }
  }

  $(document).on("click", ".obs-copy", function (e) {
    e.preventDefault();
    var $target = $("#" + $(this).data("target"));
    var full = $target.data("full") || $target.text();
    copyText(full, $(this));
  });

  /* ---------- View Full toggle (Improvement #2) ---------- */
  $(document).on("click", ".obs-toggle", function (e) {
    e.preventDefault();
    var $q = $("#" + $(this).data("target"));
    var full = $q.data("full") || "";
    if ($q.data("expanded")) {
      $q.text(full.substring(0, 200) + "…");
      $q.data("expanded", false);
      $(this).text("View Full");
    } else {
      $q.text(full);
      $q.data("expanded", true);
      $(this).text("Collapse");
    }
  });

  /* ---------- Log Use modal ---------- */
  $(document).on("click", ".obs-use", function (e) {
    e.preventDefault();
    $("#obs-use-id").val($(this).data("id"));
    $("#obs-use-modal").show();
    $('#obs-use-modal input[name="usage_where"]').focus();
  });
  $(document).on("click", ".obs-modal-close", function (e) {
    e.preventDefault();
    $("#obs-use-modal").hide();
  });
  $(document).on("keydown", function (e) {
    if (e.key === "Escape") $("#obs-use-modal").hide();
  });

  /* ---------- Click existing tag to append ---------- */
  $(document).on("click", ".obs-add-tag", function (e) {
    e.preventDefault();
    var tag = $(this).data("tag");
    var $input = $("#tags");
    var current = $input
      .val()
      .split(",")
      .map(function (s) {
        return s.trim();
      })
      .filter(Boolean);
    if (current.indexOf(tag) === -1) {
      current.push(tag);
      $input.val(current.join(", "));
    }
  });

  /* ---------- Keyboard-first entry (Improvement #7) ---------- */
  // Ctrl/Cmd + Enter saves from anywhere in the form
  $("#obs-edit-form").on("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
      e.preventDefault();
      // Submit as primary save (not "Save & Add Another")
      $("#obs-edit-form").find('input[name="save_and_new"]').remove();
      $("#obs-edit-form").submit();
    }
  });

  /* ---------- Bulk action row visibility ---------- */
  $("#obs-bulk-action").on("change", function () {
    var v = $(this).val();
    $("#obs-bulk-tag").toggle(v === "add_tag" || v === "remove_tag");
    $("#obs-bulk-where").toggle(v === "mark_used");
  });

  /* ---------- Select-all checkbox ---------- */
  $("#obs-select-all").on("change", function () {
    $('input[name="ids[]"]').prop("checked", $(this).prop("checked"));
  });

  /* ---------- Duplicate "save anyway" link ---------- */
  $("#obs-force-save").on("click", function (e) {
    e.preventDefault();
    $("#obs-force").val("1");
    $("#obs-edit-form").submit();
  });

  /* ---------- Tag rename prefill ---------- */
  $(document).on("click", ".obs-prefill-rename", function (e) {
    e.preventDefault();
    var tag = $(this).data("tag");
    $('select[name="old_tag"]').val(tag);
    $('input[name="new_tag"]').val(tag).focus().select();
    $("html, body").animate({ scrollTop: 0 }, 200);
  });
});
