jQuery(function ($) {
  /* ---------- Copy with fallback ---------- */
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

  /* ---------- View Full toggle ---------- */
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

  /* ---------- Usage modal: open for new ---------- */
  $(document).on("click", ".obs-use", function (e) {
    e.preventDefault();
    var commentId = $(this).data("id");
    var place = $(this).data("place") || "";

    // Reset form
    $("#obs-use-modal-title").text("Log Usage");
    $('#obs-usage-form input[name="action"]').val("obs_log_usage");
    $("#obs-use-comment-id").val(commentId);
    $("#obs-use-usage-id").val("");

    // Reset place dropdown
    $("#obs-place-existing").val("__new__");
    $("#obs-place-new").val("").closest("#obs-new-place-fields").show();

    // Reset date to today
    $("#obs-month").val(new Date().getMonth() + 1);
    $("#obs-year").val(new Date().getFullYear());

    // Clear recipients + note
    $('#obs-usage-form input[name="recipients[]"]').prop("checked", false);
    $("#obs-usage-note").val("");

    $("#obs-use-modal").show();
    $("#obs-place-existing").focus();
  });

  /* ---------- Usage modal: open for edit ---------- */
  $(document).on("click", ".obs-edit-usage", function (e) {
    e.preventDefault();
    var $btn = $(this);

    $("#obs-use-modal-title").text("Edit Usage Entry");
    $('#obs-usage-form input[name="action"]').val("obs_update_usage");
    $("#obs-use-comment-id").val($btn.data("comment-id"));
    $("#obs-use-usage-id").val($btn.data("usage-id"));

    // Place: if it's in the dropdown, select it; otherwise prefill "new"
    var place = $btn.data("place");
    if (
      $(
        '#obs-place-existing option[value="' +
          place.replace(/"/g, '\\"') +
          '"]',
      ).length
    ) {
      $("#obs-place-existing").val(place);
      $("#obs-place-new").val("");
    } else {
      $("#obs-place-existing").val("__new__");
      $("#obs-place-new").val(place);
    }

    $("#obs-month").val($btn.data("month") || "");
    $("#obs-year").val($btn.data("year") || new Date().getFullYear());

    // Recipients checkboxes
    var recs = String($btn.data("recipients") || "")
      .split(",")
      .map(function (s) {
        return s.trim();
      })
      .filter(Boolean);
    $('#obs-usage-form input[name="recipients[]"]').each(function () {
      $(this).prop("checked", recs.indexOf($(this).val()) !== -1);
    });

    $("#obs-usage-note").val($btn.data("note") || "");

    $("#obs-use-modal").show();
  });

  /* ---------- Modal: show/hide "new place" fields ---------- */
  $(document).on("change", "#obs-place-existing", function () {
    if ($(this).val() === "__new__") {
      $("#obs-new-place-fields").show();
      $("#obs-place-new").focus();
    } else {
      $("#obs-new-place-fields").hide();
      $("#obs-place-new").val("");
    }
  });

  /* ---------- Modal: close ---------- */
  $(document).on("click", ".obs-modal-close", function (e) {
    e.preventDefault();
    $("#obs-use-modal").hide();
  });
  $(document).on("keydown", function (e) {
    if (e.key === "Escape") $("#obs-use-modal").hide();
  });

  /* ---------- Inline usage detail toggle on list ---------- */
  $(document).on("click", ".obs-show-usage", function (e) {
    e.preventDefault();
    var id = $(this).data("comment");
    $("#obs-usage-row-" + id).toggle();
  });

  /* ---------- Existing tag append ---------- */
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

  /* ---------- Keyboard-first entry ---------- */
  $("#obs-edit-form").on("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
      e.preventDefault();
      $("#obs-edit-form").find('input[name="save_and_new"]').remove();
      $("#obs-edit-form").submit();
    }
  });

  /* ---------- Bulk action row visibility ---------- */
  $("#obs-bulk-action").on("change", function () {
    var v = $(this).val();
    $("#obs-bulk-tag").toggle(v === "add_tag" || v === "remove_tag");
  });

  /* ---------- Select-all ---------- */
  $("#obs-select-all").on("change", function () {
    $('input[name="ids[]"]').prop("checked", $(this).prop("checked"));
  });

  /* ---------- Duplicate "save anyway" ---------- */
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
