jQuery(function ($) {
  'use strict';
  $(document).on('click', '.cig-upload-button', function (e) {
    e.preventDefault();
    var $input = $(this).closest('div').find('.cig-image-url');
    var frame = wp.media({
      title: 'Select Image',
      multiple: false,
      library: { type: 'image' }
    });
    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      $input.val(attachment.url);
    });
    frame.open();
  });
});