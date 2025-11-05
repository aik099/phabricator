/**
 * @provides javelin-behavior-diffusion-copy-fix-message
 * @requires javelin-behavior
 *           javelin-dom
 *           javelin-stratcom
 *           javelin-util
 */

JX.behavior('diffusion-copy-fix-message', function(config) {
  var zc_client,
      sigil = 'copy-fix-message',
      copy_link = JX.DOM.scry(document.body, 'a', sigil).pop();

  JX.Stratcom.listen(
    'click',
    sigil,
    function (e) {
      e.kill();

      var node_data = JX.Stratcom.getData(copy_link);

      navigator.clipboard
        .writeText(node_data['fix-message'])
        .then(() => {
          copy_link.innerHTML += ' <strong>(copied)</strong>';

          setTimeout(function () {
            copy_link.innerHTML = copy_link.innerHTML.replace(/ <strong>\(copied\)<\/strong>$/, '');
          }, 2000);
        });
    });
});
