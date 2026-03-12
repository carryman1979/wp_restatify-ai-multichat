(function () {
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
    if (!sendBtn && !deleteBtn && !modeBtn) {
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
})();
