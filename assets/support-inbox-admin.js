(function () {
  function initConversationFilters() {
    var filters = Array.prototype.slice.call(document.querySelectorAll('[data-mco-conversation-filter]'));
    if (!filters.length) {
      return;
    }

    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-mco-conversation-row]'));
    if (!rows.length) {
      return;
    }

    function setActiveButton(activeButton) {
      filters.forEach(function (button) {
        var isActive = button === activeButton;
        button.classList.toggle('button-primary', isActive);
        if (!isActive) {
          button.classList.remove('button-primary');
          button.classList.add('button');
        }
      });
    }

    function applyFilter(value) {
      rows.forEach(function (row) {
        var state = String(row.getAttribute('data-mco-conversation-state') || 'none');
        var visible = value === 'all' || state === value;
        row.style.display = visible ? '' : 'none';
      });
    }

    filters.forEach(function (button) {
      button.addEventListener('click', function () {
        var value = String(button.getAttribute('data-mco-conversation-filter') || 'all');
        setActiveButton(button);
        applyFilter(value);
      });
    });
  }

  function getConfig() {
    if (!window.restatifyMcoSupportInbox || typeof window.restatifyMcoSupportInbox !== 'object') {
      return null;
    }

    return window.restatifyMcoSupportInbox;
  }

  function findConversationRow(id) {
    return document.querySelector('[data-mco-conversation-row="' + id.replace(/"/g, '') + '"]');
  }

  function removeConversationFromList(id) {
    if (!id) {
      return;
    }

    var row = findConversationRow(id);
    if (row) {
      row.remove();
    }
  }

  function isOpenConversation(id) {
    var detail = document.getElementById('restatify-mco-conversation-detail');
    if (!detail || !id) {
      return false;
    }

    return detail.getAttribute('data-mco-conversation-detail') === id;
  }

  function submitAction(config, payload) {
    var data = new FormData();
    Object.keys(payload || {}).forEach(function (key) {
      data.append(key, payload[key] == null ? '' : String(payload[key]));
    });

    return fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    }).then(function (response) {
      return response.text();
    }).then(function (raw) {
      try {
        return JSON.parse(raw);
      } catch (error) {
        return null;
      }
    });
  }

  function showError(config, payload) {
    if (payload && payload.data && payload.data.message) {
      window.alert(String(payload.data.message));
      return;
    }

    window.alert(config.strings.genericError || 'Action failed. Please refresh and try again.');
  }

  function onClick(event) {
    var config = getConfig();
    if (!config) {
      return;
    }

    var sendBtn = event.target.closest('[data-mco-support-send]');
    var deleteBtn = event.target.closest('[data-mco-support-delete]');
    var modeBtn = event.target.closest('[data-mco-support-ai-save]');
    var openBookingBtn = event.target.closest('[data-mco-support-open-booking]');
    if (!sendBtn && !deleteBtn && !modeBtn && !openBookingBtn) {
      return;
    }

    var payload = {
      nonce: config.nonce
    };

    if (sendBtn) {
      var textarea = document.getElementById('restatify-mco-support-reply');
      if (!textarea) {
        return;
      }

      var message = String(textarea.value || '').trim();
      if (!message) {
        return;
      }

      payload.action = 'restatify_mco_support_reply';
      payload.conversation_id = sendBtn.getAttribute('data-conversation-id') || '';
      payload.message = message;
    }

    if (deleteBtn) {
      if (!window.confirm(config.strings.deleteConfirm || 'Delete this conversation permanently?')) {
        return;
      }

      payload.action = 'restatify_mco_delete_conversation';
      payload.conversation_id = deleteBtn.getAttribute('data-conversation-id') || '';
    }

    if (modeBtn) {
      var modeSelect = document.getElementById('restatify-mco-ai-mode');
      if (!modeSelect) {
        return;
      }

      payload.action = 'restatify_mco_set_ai_mode';
      payload.conversation_id = modeBtn.getAttribute('data-conversation-id') || '';
      payload.ai_mode = modeSelect.value || 'visitor';
    }

    if (openBookingBtn) {
      payload.action = 'restatify_mco_support_reply';
      payload.conversation_id = openBookingBtn.getAttribute('data-conversation-id') || '';
      payload.message = '[[RESTATIFY_BOOKING_OPEN]] ' + (config.strings.openBookingAtClient || 'I opened the booking tool for you. Please choose a slot and confirm your reservation.');
    }

    submitAction(config, payload)
      .then(function (result) {
        if (!result || !result.success) {
          showError(config, result);
          return;
        }

        if (deleteBtn) {
          var deletedId = deleteBtn.getAttribute('data-conversation-id') || '';
          removeConversationFromList(deletedId);

          if (isOpenConversation(deletedId)) {
            window.location.href = config.supportPageUrl;
          }

          return;
        }

        window.location.reload();
      })
      .catch(function () {
        showError(config, null);
      });
  }

  document.addEventListener('click', onClick);
  document.addEventListener('DOMContentLoaded', initConversationFilters);
})();
