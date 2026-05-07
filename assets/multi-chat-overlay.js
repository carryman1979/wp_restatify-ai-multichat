(function () {
  var CHAT_STORAGE_ID_KEY = 'restatify_ai_multichat_chat_id';
  var CHAT_STORAGE_TOKEN_KEY = 'restatify_ai_multichat_chat_token';
  var CHAT_STORAGE_LAST_ACTIVE_KEY = 'restatify_ai_multichat_chat_last_active';
  var BOOKING_TRIGGER_STORAGE_KEY = 'restatify_ai_multichat_booking_triggers';
  var BOOKING_OPEN_TOKEN = '[[RESTATIFY_BOOKING_OPEN]]';
  var BOOKING_CONFIRMED_TOKEN = '[[RESTATIFY_BOOKING_CONFIRMED]]';
  var BOOKING_CANCELLED_TOKEN = '[[RESTATIFY_BOOKING_CANCELLED]]';
  var handledBookingTriggers = {};

  function loadHandledBookingTriggers() {
    try {
      var raw = window.sessionStorage.getItem(BOOKING_TRIGGER_STORAGE_KEY);
      if (!raw) {
        return {};
      }

      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function storeHandledBookingTriggers() {
    try {
      var keys = Object.keys(handledBookingTriggers);
      if (keys.length > 60) {
        keys.slice(0, keys.length - 60).forEach(function (key) {
          delete handledBookingTriggers[key];
        });
      }

      window.sessionStorage.setItem(BOOKING_TRIGGER_STORAGE_KEY, JSON.stringify(handledBookingTriggers));
    } catch (error) {
      return;
    }
  }

  handledBookingTriggers = loadHandledBookingTriggers();

  function initMultiChatOverlay() {
    var root = document.querySelector('[data-restatify-mco]');
    if (!root) {
      return;
    }

    if (root.getAttribute('data-mco-initialized') === '1') {
      return;
    }

    var chatEnabled = Boolean(window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.chatEnabled);
    var chatConfig = {
      ajaxUrl: (window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.ajaxUrl) || '',
      nonce: (window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.nonce) || '',
      requireConsent: Boolean(window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.requireConsent),
      consentCookieNames: Array.isArray(window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.consentCookieNames)
        ? window.restatifyMultiChatOverlay.consentCookieNames
        : [],
      pollSeconds: (window.restatifyMultiChatOverlay && Number.isFinite(window.restatifyMultiChatOverlay.pollSeconds)) ? window.restatifyMultiChatOverlay.pollSeconds : 8,
      strings: (window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.strings) || {}
    };

    if (!hasConsent(chatConfig)) {
      armConsentWatcher(root, chatConfig);
      return;
    }

    mountOverlay(root, chatConfig, chatEnabled);
  }

  function mountOverlay(root, chatConfig, chatEnabled) {
    var panel = root.querySelector('[data-mco-panel]');
    var toggle = root.querySelector('[data-mco-toggle]');
    var closeButton = root.querySelector('[data-mco-close]');
    var focusButton = root.querySelector('[data-mco-focus]');
    if (!panel || !toggle || !closeButton) {
      return;
    }

    root.style.removeProperty('display');
    root.setAttribute('data-mco-initialized', '1');
    applyChannelIconFallback(root);

    var delayMs = parseInt(root.getAttribute('data-delay-ms') || '0', 10);
    var storageKey = root.getAttribute('data-storage-key') || 'restatify_mco_dismissed_at';
    var dismissHours = 24;
    var autoOpenTimerId = 0;

    if (window.restatifyMultiChatOverlay && Number.isFinite(window.restatifyMultiChatOverlay.dismissHours)) {
      dismissHours = window.restatifyMultiChatOverlay.dismissHours;
    }

    var backdrop = createBackdrop();

    function updateFocusButtonState(enabled) {
      if (!focusButton) {
        return;
      }

      focusButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      focusButton.setAttribute('aria-label', enabled ? 'Shrink chat panel' : 'Expand chat panel');
      focusButton.setAttribute('title', enabled ? 'Shrink' : 'Expand');
      focusButton.textContent = enabled ? '-' : '+';
    }

    function setChatFocusMode(enabled) {
      var isEnabled = Boolean(enabled);
      root.classList.toggle('is-chat-focus', isEnabled);
      backdrop.classList.toggle('is-visible', isEnabled && !panel.hidden);
      updateFocusButtonState(isEnabled);
    }

    function isDismissedWithinWindow() {
      try {
        var raw = window.localStorage.getItem(storageKey);
        if (!raw) {
          return false;
        }

        var dismissedAt = parseInt(raw, 10);
        if (!Number.isFinite(dismissedAt)) {
          return false;
        }

        var ttlMs = dismissHours * 60 * 60 * 1000;
        return Date.now() - dismissedAt < ttlMs;
      } catch (error) {
        return false;
      }
    }

    function markDismissedNow() {
      try {
        window.localStorage.setItem(storageKey, String(Date.now()));
      } catch (error) {
        // Ignore storage errors to avoid blocking UI.
      }
    }

    function clearAutoOpenTimer() {
      if (!autoOpenTimerId) {
        return;
      }

      window.clearTimeout(autoOpenTimerId);
      autoOpenTimerId = 0;
    }

    function openPanel() {
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      root.classList.add('is-open');
      if (root.classList.contains('is-chat-focus')) {
        backdrop.classList.add('is-visible');
      }
    }

    function closePanel(remember) {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      root.classList.remove('is-open');
      setChatFocusMode(false);
      if (remember) {
        markDismissedNow();
        clearAutoOpenTimer();
      }
    }

    toggle.addEventListener('click', function () {
      if (panel.hidden) {
        openPanel();
      } else {
        closePanel(false);
      }
    });

    closeButton.addEventListener('click', function () {
      closePanel(true);
    });

    if (focusButton) {
      focusButton.addEventListener('click', function () {
        setChatFocusMode(!root.classList.contains('is-chat-focus'));
      });
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !panel.hidden) {
        closePanel(false);
      }
    });

    document.addEventListener('click', function (event) {
      if (panel.hidden) {
        return;
      }

      if (!root.contains(event.target)) {
        closePanel(false);
      }
    });

    backdrop.addEventListener('click', function () {
      if (!panel.hidden) {
        closePanel(false);
      }
    });

    if (delayMs > 0 && !isDismissedWithinWindow()) {
      autoOpenTimerId = window.setTimeout(function () {
        autoOpenTimerId = 0;
        if (panel.hidden && !isDismissedWithinWindow()) {
          openPanel();
        }
      }, delayMs);
    }

    initChannelToggle(root);

    updateFocusButtonState(root.classList.contains('is-chat-focus'));

    if (chatEnabled) {
      initNativeChat(root, chatConfig, {
        onBeforeSend: function () {
          if (panel.hidden) {
            openPanel();
          }

          setChatFocusMode(true);
        }
      });
    }
  }

  function applyChannelIconFallback(root) {
    var icons = root.querySelectorAll('[data-channel-fallback]');
    if (!icons || icons.length === 0) {
      return;
    }

    icons.forEach(function (node) {
      var fallback = String(node.getAttribute('data-channel-fallback') || '?');
      var beforeContent = window.getComputedStyle(node, '::before').content;
      var hasIcon = Boolean(beforeContent && beforeContent !== 'none' && beforeContent !== '""' && beforeContent !== "''");

      if (hasIcon) {
        node.textContent = '';
        node.classList.remove('is-fallback');
        return;
      }

      node.textContent = fallback;
      node.classList.add('is-fallback');
    });
  }

  function initChannelToggle(root) {
    var channels = root.querySelector('[data-mco-channels]');
    var toggle = root.querySelector('[data-mco-channels-toggle]');
    if (!channels || !toggle) {
      return;
    }

    var moreLabel = String(toggle.getAttribute('data-label-more') || 'More');
    var lessLabel = String(toggle.getAttribute('data-label-less') || 'Less');

    function update(expanded) {
      channels.classList.toggle('is-expanded', expanded);
      channels.classList.toggle('is-collapsed', !expanded);
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toggle.textContent = expanded ? lessLabel : moreLabel;
    }

    update(channels.classList.contains('is-expanded'));

    toggle.addEventListener('click', function () {
      var expanded = channels.classList.contains('is-expanded');
      update(!expanded);
    });
  }

  function armConsentWatcher(root, chatConfig) {
    if (root.getAttribute('data-mco-consent-watch') === '1') {
      return;
    }

    root.style.display = 'none';
    root.setAttribute('data-mco-consent-watch', '1');

    var intervalId = 0;
    var onConsentUpdate = function () {
      if (!hasConsent(chatConfig)) {
        return;
      }

      cleanup();
      root.removeAttribute('data-mco-consent-watch');
      root.style.removeProperty('display');
      initMultiChatOverlay();
    };

    function cleanup() {
      if (intervalId) {
        window.clearInterval(intervalId);
      }

      window.removeEventListener('CookiebotOnAccept', onConsentUpdate);
      window.removeEventListener('CookiebotOnConsentReady', onConsentUpdate);
      window.removeEventListener('OneTrustGroupsUpdated', onConsentUpdate);
      document.removeEventListener('cmplz_enable_category', onConsentUpdate);
      document.removeEventListener('cmplz_fire_categories', onConsentUpdate);
    }

    intervalId = window.setInterval(onConsentUpdate, 1000);
    window.addEventListener('CookiebotOnAccept', onConsentUpdate);
    window.addEventListener('CookiebotOnConsentReady', onConsentUpdate);
    window.addEventListener('OneTrustGroupsUpdated', onConsentUpdate);
    document.addEventListener('cmplz_enable_category', onConsentUpdate);
    document.addEventListener('cmplz_fire_categories', onConsentUpdate);
  }

  function hasConsent(config) {
    if (!config || !config.requireConsent) {
      return true;
    }

    if (typeof window.restatifyMcoHasConsent === 'function') {
      try {
        if (window.restatifyMcoHasConsent() === true) {
          return true;
        }
      } catch (error) {
        // Ignore custom callback errors and continue with built-in checks.
      }
    }

    if (window.Cookiebot && window.Cookiebot.consent) {
      if (window.Cookiebot.consent.marketing || window.Cookiebot.consent.preferences || window.Cookiebot.consent.statistics) {
        return true;
      }
    }

    if (typeof window.OneTrustActiveGroups === 'string' && /C0002|C0003|C0004/.test(window.OneTrustActiveGroups)) {
      return true;
    }

    return hasConsentCookie(config.consentCookieNames);
  }

  function hasConsentCookie(cookieNames) {
    var defaultCookieNames = [
      'cookieyes-consent',
      '_cky-consent',
      'cookie_notice_accepted=true',
      'complianz_consent_status',
      'cookie_notice_accepted',
      'OptanonConsent',
      'euconsent-v2'
    ];

    var effectiveNames = Array.isArray(cookieNames) ? cookieNames.slice() : [];
    if (effectiveNames.length === 0) {
      effectiveNames = defaultCookieNames;
    }

    if (effectiveNames.length === 0) {
      return false;
    }

    var cookieMap = getCookieMap();
    for (var i = 0; i < effectiveNames.length; i += 1) {
      var rawRule = String(effectiveNames[i] || '').trim();
      if (!rawRule) {
        continue;
      }

      var rule = parseConsentCookieRule(rawRule);
      if (!rule.name) {
        continue;
      }

      if (!Object.prototype.hasOwnProperty.call(cookieMap, rule.name)) {
        continue;
      }

      if (!rule.expectedValue) {
        return true;
      }

      var currentValue = String(cookieMap[rule.name] || '').toLowerCase();
      var expected = String(rule.expectedValue || '').toLowerCase();
      if (currentValue === expected) {
        return true;
      }

      if (currentValue.indexOf(expected) !== -1) {
        return true;
      }
    }

    return false;
  }

  function parseConsentCookieRule(rawRule) {
    var parts = rawRule.split('=');
    var name = String(parts.shift() || '').trim();
    var expectedValue = '';
    if (parts.length > 0) {
      expectedValue = parts.join('=').trim();
    }

    return {
      name: name,
      expectedValue: expectedValue
    };
  }

  function getCookieMap() {
    var map = {};
    var all = String(document.cookie || '');
    if (!all) {
      return map;
    }

    all.split(';').forEach(function (entry) {
      var idx = entry.indexOf('=');
      if (idx < 0) {
        return;
      }

      var key = decodeURIComponent(entry.slice(0, idx).trim());
      var value = decodeURIComponent(entry.slice(idx + 1).trim());
      if (key) {
        map[key] = value;
      }
    });

    return map;
  }

  function initNativeChat(root, config, hooks) {
    var container = root.querySelector('[data-mco-native-chat]');
    if (!container || !config.ajaxUrl || !config.nonce) {
      return;
    }

    var form = container.querySelector('[data-chat-form]');
    var input = container.querySelector('[data-chat-input]');
    var send = container.querySelector('[data-chat-send]');
    var honeypot = container.querySelector('[data-chat-honeypot]');
    var messagesWrap = container.querySelector('[data-chat-messages]');
    var status = container.querySelector('[data-chat-status]');
    if (!form || !input || !send || !messagesWrap || !status) {
      return;
    }

    input.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) {
        return;
      }

      event.preventDefault();
      if (send.disabled) {
        return;
      }

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(send);
        return;
      }

      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });

    var state = {
      id: loadStorage(CHAT_STORAGE_ID_KEY),
      token: loadStorage(CHAT_STORAGE_TOKEN_KEY),
      messages: []
    };

    var resetMinutes = Math.max(0, Number(config.chatResetMinutes || (Number(config.chatResetHours || 0) * 60) || 0));
    if (isChatExpired(resetMinutes)) {
      clearChatSession(state);
    }

    renderMessages(messagesWrap, state.messages);

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var text = String(input.value || '').trim();
      if (!text) {
        showStatus(status, config.strings.emptyMessage || 'Please enter a message first.', true);
        return;
      }

      if (hooks && typeof hooks.onBeforeSend === 'function') {
        hooks.onBeforeSend();
      }

      send.disabled = true;
      showStatus(status, config.strings.sending || 'Sending...', false);

      postToAjax(config.ajaxUrl, {
        action: 'restatify_mco_send_message',
        nonce: config.nonce,
        conversation_id: state.id,
        conversation_token: state.token,
        message: text,
        website: honeypot ? String(honeypot.value || '') : '',
        source_url: window.location.href
      }).then(function (payload) {
        if (!payload || !payload.success || !payload.data || !payload.data.conversation) {
          throw new Error('Invalid response');
        }

        state.id = payload.data.conversation.id || state.id;
        state.token = payload.data.conversation.token || state.token;
        state.messages = Array.isArray(payload.data.conversation.messages) ? payload.data.conversation.messages : [];

        saveStorage(CHAT_STORAGE_ID_KEY, state.id);
        saveStorage(CHAT_STORAGE_TOKEN_KEY, state.token);
        touchChatActivityFromConversation(payload.data.conversation, state.messages);

        input.value = '';
        renderMessages(messagesWrap, state.messages);
        showStatus(status, '', false);
      }).catch(function () {
        showStatus(status, config.strings.sendFailed || 'Message could not be sent. Please try again.', true);
      }).finally(function () {
        send.disabled = false;
      });
    });

    if (state.id && state.token) {
      fetchConversation(config, state, messagesWrap);
    }

    var pollMs = Math.max(3000, Number(config.pollSeconds || 8) * 1000);
    window.setInterval(function () {
      if (!state.id || !state.token) {
        return;
      }

      fetchConversation(config, state, messagesWrap);
    }, pollMs);
  }

  function createBackdrop() {
    var existing = document.querySelector('.restatify-mco__backdrop');
    if (existing) {
      return existing;
    }

    var node = document.createElement('div');
    node.className = 'restatify-mco__backdrop';
    document.body.appendChild(node);
    return node;
  }

  function fetchConversation(config, state, messagesWrap) {
    return postToAjax(config.ajaxUrl, {
      action: 'restatify_mco_fetch_chat',
      nonce: config.nonce,
      conversation_id: state.id,
      conversation_token: state.token
    }).then(function (payload) {
      if (!payload || !payload.success || !payload.data || !payload.data.conversation) {
        if (payload && payload.success === false) {
          clearChatSession(state);
          renderMessages(messagesWrap, state.messages);
        }
        return;
      }

      var fresh = Array.isArray(payload.data.conversation.messages) ? payload.data.conversation.messages : [];
      var hadChanges = fresh.length !== state.messages.length;
      if (fresh.length === state.messages.length) {
        touchChatActivityFromConversation(payload.data.conversation, state.messages);
        return;
      }

      state.messages = fresh;
      if (hadChanges) {
        touchChatActivityFromConversation(payload.data.conversation, fresh);
      }
      renderMessages(messagesWrap, state.messages);
    }).catch(function () {
      return;
    });
  }

  function postToAjax(url, payload) {
    var formData = new FormData();
    Object.keys(payload || {}).forEach(function (key) {
      formData.append(key, payload[key] == null ? '' : String(payload[key]));
    });

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    }).then(function (response) {
      return response.json();
    });
  }

  function renderMessages(container, messages) {
    container.innerHTML = '';
    if (!Array.isArray(messages) || messages.length === 0) {
      return;
    }

    messages.forEach(function (item) {
      var sender = item && item.sender ? String(item.sender) : 'visitor';
      var text = item && item.message ? String(item.message) : '';
      if (!text) {
        return;
      }

      var openBooking = sender !== 'visitor' && text.indexOf(BOOKING_OPEN_TOKEN) !== -1;
      text = text
        .replace(BOOKING_OPEN_TOKEN, '')
        .replace(BOOKING_CONFIRMED_TOKEN, '')
        .replace(BOOKING_CANCELLED_TOKEN, '')
        .trim();

      var bubble = document.createElement('p');
      bubble.className = 'restatify-mco__native-bubble is-' + sender;
      bubble.textContent = text;
      container.appendChild(bubble);

      if (openBooking) {
        var triggerKey = String(item && item.time_gmt ? item.time_gmt : '') + '|' + sender + '|' + text;
        if (handledBookingTriggers[triggerKey]) {
          return;
        }

        handledBookingTriggers[triggerKey] = true;
        storeHandledBookingTriggers();
        document.dispatchEvent(new CustomEvent('restatify:booking-open'));
      }
    });

    container.scrollTop = container.scrollHeight;
  }

  function showStatus(node, text, isError) {
    if (!text) {
      node.hidden = true;
      node.textContent = '';
      node.classList.remove('is-error');
      return;
    }

    node.hidden = false;
    node.textContent = text;
    node.classList.toggle('is-error', Boolean(isError));
  }

  function loadStorage(key) {
    try {
      return window.localStorage.getItem(key) || '';
    } catch (error) {
      return '';
    }
  }

  function saveStorage(key, value) {
    if (!value) {
      return;
    }

    try {
      window.localStorage.setItem(key, String(value));
    } catch (error) {
      // Ignore storage errors to avoid blocking chat.
    }
  }

  function removeStorage(key) {
    try {
      window.localStorage.removeItem(key);
    } catch (error) {
      return;
    }
  }

  function loadStorageNumber(key) {
    var raw = loadStorage(key);
    var parsed = parseInt(raw || '0', 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function touchChatActivity() {
    saveStorage(CHAT_STORAGE_LAST_ACTIVE_KEY, String(Date.now()));
  }

  function touchChatActivityFromConversation(conversation, messages) {
    var updatedRaw = conversation && conversation.updated_at_gmt ? String(conversation.updated_at_gmt) : '';
    var updatedTs = Date.parse(updatedRaw);
    var messageTs = getLatestMessageTimestamp(messages);
    var next = 0;

    if (Number.isFinite(updatedTs) && updatedTs > 0) {
      next = updatedTs;
    }

    if (messageTs > next) {
      next = messageTs;
    }

    if (next <= 0) {
      touchChatActivity();
      return;
    }

    var current = loadStorageNumber(CHAT_STORAGE_LAST_ACTIVE_KEY);
    if (!current || next > current) {
      saveStorage(CHAT_STORAGE_LAST_ACTIVE_KEY, String(next));
    }
  }

  function getLatestMessageTimestamp(messages) {
    if (!Array.isArray(messages) || messages.length === 0) {
      return 0;
    }

    var latest = 0;
    messages.forEach(function (msg) {
      if (!msg || !msg.time_gmt) {
        return;
      }

      var ts = Date.parse(String(msg.time_gmt));
      if (Number.isFinite(ts) && ts > latest) {
        latest = ts;
      }
    });

    return latest;
  }

  function isChatExpired(resetMinutes) {
    if (resetMinutes <= 0) {
      return false;
    }

    var lastActive = loadStorageNumber(CHAT_STORAGE_LAST_ACTIVE_KEY);
    if (!lastActive) {
      return false;
    }

    var ttlMs = resetMinutes * 60 * 1000;
    return Date.now() - lastActive >= ttlMs;
  }

  function clearChatSession(state) {
    state.id = '';
    state.token = '';
    state.messages = [];

    removeStorage(CHAT_STORAGE_ID_KEY);
    removeStorage(CHAT_STORAGE_TOKEN_KEY);
    removeStorage(CHAT_STORAGE_LAST_ACTIVE_KEY);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMultiChatOverlay, { once: true });
  } else {
    initMultiChatOverlay();
  }
})();
