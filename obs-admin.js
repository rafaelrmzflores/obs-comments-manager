jQuery(function ($) {
  // Copy full comment text
  $(document).on("click", ".bsc-copy", function (e) {
    e.preventDefault();
    var text = $(this).data("full");
    navigator.clipboard.writeText(text).then(function () {
      var $btn = $(e.target);
      var orig = $btn.text();
      $btn.text(bscData.copiedMsg);
      setTimeout(function () {
        $btn.text(orig);
      }, 1500);
    });
  });

  // Open "Log Use" modal
  $(document).on("click", ".bsc-use", function (e) {
    e.preventDefault();
    $("#bsc-use-id").val($(this).data("id"));
    $("#bsc-use-modal").show();
  });

  // Click to add existing tag
  $(document).on("click", ".bsc-add-tag", function (e) {
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
});
