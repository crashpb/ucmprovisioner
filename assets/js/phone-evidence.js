/* Read-only, owner-scoped retained evidence. Never infer device application. */
(function () {
  'use strict';
  function stamp(value) {
    var number = Number(value);
    return number > 0 && Number.isFinite(number) ? new Date(number * 1000).toISOString() : 'time unknown';
  }
  function state(value) { return value === true ? 'confirmed' : value === false ? 'not confirmed' : 'unknown'; }
  function hashRecord(record, field) {
    return record && /^[a-f0-9]{64}$/.test(String(record.hash || ''))
      ? record.hash.slice(0, 12) + ' · ' + stamp(record[field]) : 'unknown';
  }
  function summary(result) {
    var presentation = result.evidence ? result.desired : null;
    result = result.evidence || result;
    var attempt = result.latest_attempt || {}, at = stamp(attempt.finished || attempt.started);
    var lines = [
      'Desired: ' + hashRecord(result.desired, 'planned_at'),
      'Published: ' + hashRecord(result.files, 'applied_at'),
      'PBX active: ' + state(result.pbx) + ' · ' + at,
      'Notification: ' + state(result.notification) + ' · ' + at,
      'Device applied: unknown — physical verification required',
      'Source: owner build receipts; read ' + stamp(result.read_at)
    ];
    if (presentation) {
      lines.unshift('Effective phone label: ' + String(presentation.phone_label || 'unknown'));
      (presentation.accounts || []).forEach(function (account) {
        lines.push('Account ' + account.account + ': label ' + account.label + ' · caller ' + account.display_name);
      });
    }
    return lines.join('\n');
  }
  if (typeof module !== 'undefined' && module.exports) { module.exports = {summary:summary}; return; }
  if (window.ucmpPhoneEvidenceLoaded) return;
  window.ucmpPhoneEvidenceLoaded = true;
  var $ = window.jQuery;
  $(document).on('click', '.ucm-read-evidence', function () {
    var button = $(this), cell = button.closest('.ucm-phone-evidence');
    var site = Number(cell.attr('data-site')), phone = Number(cell.attr('data-phone'));
    if (button.prop('disabled') || site < 1 || phone < 1) return;
    button.prop('disabled', true);
    function current() {
      return $.contains(document, cell[0]) && Number(cell.attr('data-site')) === site && Number(cell.attr('data-phone')) === phone;
    }
    window.ucmpAjax({data:{command:'operatorworkflow', op:'explain', site_id:site, phone_id:phone}})
      .done(function (response) {
        if (!current()) return;
        var result = response && response.result || {};
        cell.find('.ucm-evidence-values').text(result.ok && Number(result.site_id) === site && Number(result.phone_id) === phone
          ? summary(result) : 'Evidence unavailable — no success inferred.');
      }).fail(function () {
        if (current()) cell.find('.ucm-evidence-values').text('Evidence unavailable — no success inferred.');
      }).always(function () { if (current()) button.prop('disabled', false); });
  });
})();
