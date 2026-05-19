(function () {
  function initLiveDebugOverlay() {
    var config = window.restatifyMultiChatOverlayLiveDebug || {};
    if (!config || !config.enabled || !config.ajaxUrl || !config.nonce) {
      return;
    }

    var root = document.createElement('section');
    root.className = 'restatify-mco-live-debug';

    var copyLabel = String((config.strings && config.strings.copyButtonLabel) || 'Copy');
    var copiedLabel = String((config.strings && config.strings.copyButtonCopiedLabel) || 'Copied');

    var title = document.createElement('div');
    title.className = 'restatify-mco-live-debug__title';
    title.textContent = String((config.strings && config.strings.debugTitle) || 'Live Debug');
    root.appendChild(title);

    var grid = document.createElement('div');
    grid.className = 'restatify-mco-live-debug__grid';

    var col1 = document.createElement('div');
    col1.className = 'restatify-mco-live-debug__col';
    var col1Heading = document.createElement('div');
    col1Heading.className = 'restatify-mco-live-debug__heading';
    col1Heading.textContent = String((config.strings && config.strings.session1Title) || 'Session 1');
    var col1Body = document.createElement('pre');
    col1Body.className = 'restatify-mco-live-debug__body';
    col1Body.textContent = String((config.strings && config.strings.debugWaiting) || 'Warte auf Konversationsdaten...');
    var col1CopyButton = createCopyButton(col1Body, copyLabel, copiedLabel);
    col1Heading.appendChild(col1CopyButton);
    col1.appendChild(col1Heading);
    col1.appendChild(col1Body);

    var col2 = document.createElement('div');
    col2.className = 'restatify-mco-live-debug__col';
    var col2Heading = document.createElement('div');
    col2Heading.className = 'restatify-mco-live-debug__heading';
    col2Heading.textContent = String((config.strings && config.strings.session2Title) || 'Session 2');
    var col2Body = document.createElement('pre');
    col2Body.className = 'restatify-mco-live-debug__body';
    col2Body.textContent = String((config.strings && config.strings.debugWaiting) || 'Warte auf Konversationsdaten...');
    var col2CopyButton = createCopyButton(col2Body, copyLabel, copiedLabel);
    col2Heading.appendChild(col2CopyButton);
    col2.appendChild(col2Heading);
    col2.appendChild(col2Body);

    grid.appendChild(col1);
    grid.appendChild(col2);
    root.appendChild(grid);

    var logWrap = document.createElement('div');
    logWrap.className = 'restatify-mco-live-debug__log';
    var logHeading = document.createElement('div');
    logHeading.className = 'restatify-mco-live-debug__heading';
    logHeading.textContent = String((config.strings && config.strings.logTitle) || 'Aktives Log');
    var logBody = document.createElement('pre');
    logBody.className = 'restatify-mco-live-debug__body is-log';
    logBody.textContent = String((config.strings && config.strings.debugWaiting) || 'Warte auf Konversationsdaten...');
    var logCopyButton = createCopyButton(logBody, copyLabel, copiedLabel);
    logHeading.appendChild(logCopyButton);
    logWrap.appendChild(logHeading);
    logWrap.appendChild(logBody);
    root.appendChild(logWrap);

    var staticSection = document.createElement('div');
    staticSection.className = 'restatify-mco-live-debug__static';

    var languageSection = document.createElement('div');
    languageSection.className = 'restatify-mco-live-debug__language';
    var languageHeading = document.createElement('div');
    languageHeading.className = 'restatify-mco-live-debug__heading';
    languageHeading.textContent = 'Sprache';
    var languageBody = document.createElement('pre');
    languageBody.className = 'restatify-mco-live-debug__body';
    languageBody.textContent = 'Warte auf Sprachdaten...';
    languageSection.appendChild(languageHeading);
    languageSection.appendChild(languageBody);

    var bookingSection = document.createElement('div');
    bookingSection.className = 'restatify-mco-live-debug__booking';
    var bookingHeading = document.createElement('div');
    bookingHeading.className = 'restatify-mco-live-debug__heading';
    bookingHeading.textContent = 'Buchungsdaten';
    var bookingBody = document.createElement('pre');
    bookingBody.className = 'restatify-mco-live-debug__body';
    bookingBody.textContent = 'Warte auf Buchungsdaten...';
    bookingSection.appendChild(bookingHeading);
    bookingSection.appendChild(bookingBody);

    staticSection.appendChild(languageSection);
    staticSection.appendChild(bookingSection);
    root.appendChild(staticSection);

    document.body.appendChild(root);

    var state = {
      conversationId: '',
      timerId: 0,
      pollMs: Math.max(1000, Number(config.pollMs || 2000))
    };

    function createCopyButton(targetElement, defaultLabel, successLabel) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'restatify-mco-live-debug__copy-btn';
      button.textContent = defaultLabel;

      button.addEventListener('click', function () {
        var text = targetElement && typeof targetElement.textContent === 'string' ? targetElement.textContent : '';
        copyTextToClipboard(text).then(function () {
          button.classList.add('is-copied');
          button.textContent = successLabel;
          window.setTimeout(function () {
            button.classList.remove('is-copied');
            button.textContent = defaultLabel;
          }, 1200);
        }).catch(function () {
          button.classList.remove('is-copied');
          button.textContent = defaultLabel;
        });
      });

      return button;
    }

    function copyTextToClipboard(text) {
      var value = String(text || '');
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(value);
      }

      return new Promise(function (resolve, reject) {
        try {
          var temp = document.createElement('textarea');
          temp.value = value;
          temp.setAttribute('readonly', 'readonly');
          temp.style.position = 'fixed';
          temp.style.left = '-9999px';
          temp.style.top = '0';
          document.body.appendChild(temp);
          temp.select();
          temp.setSelectionRange(0, temp.value.length);

          var successful = document.execCommand('copy');
          document.body.removeChild(temp);
          if (successful) {
            resolve();
            return;
          }

          reject(new Error('copy_failed'));
        } catch (error) {
          reject(error);
        }
      });
    }

    function render(data) {
      if (!data || typeof data !== 'object') {
        return;
      }

      var session1 = data.session1 || {};
      var session2 = data.session2 || {};
      var log = data.log || {};
      var language = session1.language || {};
      var recognizedBookingData = session1.recognized_booking_data || {};

      // Session 1: Display logs + status
      var session1Lines = [];
      session1Lines.push(String(session1.status_line || '-'));
      session1Lines.push('');
      session1Lines.push('━━━ Session 1 Debug Log ━━━');
      
      var session1Logs = Array.isArray(session1.debug_logs) ? session1.debug_logs : [];
      if (session1Logs.length > 0) {
        session1Lines = session1Lines.concat(session1Logs);
      } else {
        session1Lines.push('(keine Session 1 Aktivität)');
      }
      
      session1Lines.push('');
      session1Lines.push('current_session: ' + String(session1.current_session || '-'));
      session1Lines.push('booking_flow_active: ' + (session1.booking_flow_active ? 'true' : 'false'));
      session1Lines.push('clarification_attempts: ' + String(session1.clarification_attempts || 0));
      session1Lines.push('letzte Anfrage: ' + String(session1.last_request_at || '-'));

      session1Lines.push('');
      session1Lines.push('━━━ Sprache ━━━');
      session1Lines.push('language_code: ' + String(language.code || '-'));
      session1Lines.push('language_candidate: ' + String(language.candidate || '-'));
      session1Lines.push('last_detected: ' + String(language.last_detected || '-'));
      session1Lines.push('last_confidence: ' + String(language.last_confidence != null ? language.last_confidence : '-'));
      session1Lines.push('switch_votes: ' + String(language.switch_votes != null ? language.switch_votes : '-'));
      session1Lines.push('switch_reason: ' + String(language.switch_reason || '-'));
      session1Lines.push('lock_until_gmt: ' + String(language.lock_until_gmt || '-'));
      session1Lines.push('last_detected_at_gmt: ' + String(language.last_detected_at_gmt || '-'));

      session1Lines.push('');
      session1Lines.push('━━━ Booking Basisdaten erkannt ━━━');
      session1Lines.push('collected_fields:');
      session1Lines.push(stringifyDebugObject(recognizedBookingData.collected_fields));
      session1Lines.push('partial_prefill:');
      session1Lines.push(stringifyDebugObject(recognizedBookingData.partial_prefill));
      col1Body.textContent = session1Lines.join('\n');

      // Update static language section
      var langLines = [];
      langLines.push('code: ' + String(language.code || '-'));
      langLines.push('candidate: ' + String(language.candidate || '-'));
      langLines.push('last_detected: ' + String(language.last_detected || '-'));
      langLines.push('confidence: ' + String(language.last_confidence != null ? language.last_confidence : '-'));
      langLines.push('switch_votes: ' + String(language.switch_votes != null ? language.switch_votes : '-'));
      langLines.push('switch_reason: ' + String(language.switch_reason || '-'));
      langLines.push('lock_until: ' + String(language.lock_until_gmt || '-'));
      langLines.push('detected_at: ' + String(language.last_detected_at_gmt || '-'));
      languageBody.textContent = langLines.join('\n');

      // Update static booking section
      var bookingLines = [];
      bookingLines.push('collected_fields:');
      bookingLines.push(stringifyDebugObject(recognizedBookingData.collected_fields));
      bookingLines.push('');
      bookingLines.push('partial_prefill:');
      bookingLines.push(stringifyDebugObject(recognizedBookingData.partial_prefill));
      bookingBody.textContent = bookingLines.join('\n');

      var timeline = Array.isArray(session2.timeline) ? session2.timeline : [];
      col2Body.textContent = timeline.length > 0 ? timeline.join('\n') : '-';

      var lines = Array.isArray(log.lines) ? log.lines : [];
      logBody.textContent = lines.length > 0 ? lines.join('\n') : '-';
    }

    function stringifyDebugObject(value) {
      if (!value || typeof value !== 'object') {
        return '-';
      }

      try {
        var text = JSON.stringify(value, null, 2);
        return String(text || '-');
      } catch (error) {
        return '-';
      }
    }

    function renderError(message) {
      var text = String(message || 'Debug update failed');
      col1Body.textContent = text;
      col2Body.textContent = text;
      logBody.textContent = text;
    }

    function postToAjax(url, payload, timeoutMs) {
      var formData = new FormData();
      Object.keys(payload || {}).forEach(function (key) {
        formData.append(key, payload[key] == null ? '' : String(payload[key]));
      });

      var timeout = Math.max(1000, Number(timeoutMs || 10000));
      var controller = null;
      var timerId = 0;
      var requestOptions = {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      };

      if (typeof AbortController !== 'undefined') {
        controller = new AbortController();
        requestOptions.signal = controller.signal;
        timerId = window.setTimeout(function () {
          controller.abort();
        }, timeout);
      }

      return fetch(url, requestOptions).then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        return response.json();
      }).finally(function () {
        if (timerId) {
          window.clearTimeout(timerId);
        }
      });
    }

    function fetchDebugState() {
      if (!state.conversationId) {
        return;
      }

      postToAjax(config.ajaxUrl, {
        action: 'restatify_mco_live_debug_status',
        nonce: config.nonce,
        conversation_id: state.conversationId
      }, 10000).then(function (payload) {
        if (!payload || payload.success !== true || !payload.data) {
          throw new Error('Invalid debug response');
        }

        render(payload.data);
      }).catch(function (error) {
        renderError(error && error.message ? error.message : 'Debug update failed');
      });
    }

    function restartPolling() {
      if (state.timerId) {
        window.clearInterval(state.timerId);
      }

      state.timerId = window.setInterval(fetchDebugState, state.pollMs);
    }

    function onConversationState(event) {
      var detail = event && event.detail ? event.detail : {};
      var nextId = String(detail.conversationId || '');
      if (nextId === '') {
        return;
      }

      if (nextId !== state.conversationId) {
        state.conversationId = nextId;
      }

      fetchDebugState();
    }

    document.addEventListener('restatify:mco-conversation-state', onConversationState);

    restartPolling();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLiveDebugOverlay, { once: true });
  } else {
    initLiveDebugOverlay();
  }
})();
