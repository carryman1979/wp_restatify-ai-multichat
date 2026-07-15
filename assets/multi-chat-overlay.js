(function () {
  var CHAT_STORAGE_ID_KEY = 'restatify_ai_multichat_chat_id';
  var CHAT_STORAGE_TOKEN_KEY = 'restatify_ai_multichat_chat_token';
  var CHAT_STORAGE_LAST_ACTIVE_KEY = 'restatify_ai_multichat_chat_last_active';
  var BOOKING_TRIGGER_STORAGE_KEY = 'restatify_ai_multichat_booking_triggers';
  var BOOKING_OPEN_TOKEN = '[[RESTATIFY_BOOKING_OPEN]]';
  var BOOKING_PREFILL_TOKEN = '[[RESTATIFY_BOOKING_PREFILL]]';
  var CONTACT_FORM_OPEN_TOKEN = '[[RESTATIFY_CONTACT_FORM_OPEN]]';
  var CONTACT_FORM_PAYLOAD_TOKEN = '[[RESTATIFY_CONTACT_FORM_PAYLOAD]]';
  var BOOKING_CONFIRMED_TOKEN = '[[RESTATIFY_BOOKING_CONFIRMED]]';
  var BOOKING_CANCELLED_TOKEN = '[[RESTATIFY_BOOKING_CANCELLED]]';
  var handledBookingTriggers = {};
  var markdownRenderer = window.RestatifyMcoMarkdown;

  if (!markdownRenderer || typeof markdownRenderer.renderMarkdownToHtml !== 'function' || typeof markdownRenderer.stripEmoji !== 'function') {
    throw new Error('RestatifyMcoMarkdown runtime is missing.');
  }

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
      liveUpdatesWsUrl: (window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.liveUpdatesWsUrl) || '',
      requireConsent: Boolean(window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.requireConsent),
      consentCookieNames: Array.isArray(window.restatifyMultiChatOverlay && window.restatifyMultiChatOverlay.consentCookieNames)
        ? window.restatifyMultiChatOverlay.consentCookieNames
        : [],
      pollSeconds: (window.restatifyMultiChatOverlay && Number.isFinite(window.restatifyMultiChatOverlay.pollSeconds)) ? window.restatifyMultiChatOverlay.pollSeconds : 8,
      chatSendRetryMaxAttempts: (window.restatifyMultiChatOverlay && Number.isFinite(window.restatifyMultiChatOverlay.chatSendRetryMaxAttempts)) ? window.restatifyMultiChatOverlay.chatSendRetryMaxAttempts : 3,
      chatSendRetryWaitMs: (window.restatifyMultiChatOverlay && Number.isFinite(window.restatifyMultiChatOverlay.chatSendRetryWaitMs)) ? window.restatifyMultiChatOverlay.chatSendRetryWaitMs : 500,
      chatSendTimeoutMs: (window.restatifyMultiChatOverlay && Number.isFinite(window.restatifyMultiChatOverlay.chatSendTimeoutMs)) ? window.restatifyMultiChatOverlay.chatSendTimeoutMs : 20000,
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
    var mobileViewport = window.matchMedia('(max-width: 980px)');

    function isMobileViewport() {
      return Boolean(mobileViewport && mobileViewport.matches);
    }

    function updateFocusButtonState(enabled) {
      if (!focusButton) {
        return;
      }

      if (isMobileViewport()) {
        focusButton.setAttribute('hidden', 'hidden');
        focusButton.setAttribute('aria-hidden', 'true');
        return;
      }

      focusButton.removeAttribute('hidden');
      focusButton.removeAttribute('aria-hidden');

      focusButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      focusButton.setAttribute('aria-label', enabled ? 'Shrink chat panel' : 'Expand chat panel');
      focusButton.setAttribute('title', enabled ? 'Shrink' : 'Expand');
      focusButton.textContent = enabled ? '-' : '+';
    }

    function setChatFocusMode(enabled) {
      if (isMobileViewport()) {
        root.classList.remove('is-chat-focus');
        backdrop.classList.remove('is-visible');
        updateFocusButtonState(false);
        return;
      }

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
        if (isMobileViewport()) {
          return;
        }

        setChatFocusMode(!root.classList.contains('is-chat-focus'));
      });
    }

    if (mobileViewport) {
      if (typeof mobileViewport.addEventListener === 'function') {
        mobileViewport.addEventListener('change', function () {
          setChatFocusMode(false);
        });
      } else if (typeof mobileViewport.addListener === 'function') {
        mobileViewport.addListener(function () {
          setChatFocusMode(false);
        });
      }
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

          if (!isMobileViewport()) {
            setChatFocusMode(true);
          }
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
    var compactViewport = window.matchMedia('(max-width: 980px)');

    function isCompactToggle() {
      return Boolean(compactViewport && compactViewport.matches);
    }

    function update(expanded) {
      channels.classList.toggle('is-expanded', expanded);
      channels.classList.toggle('is-collapsed', !expanded);
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      if (isCompactToggle()) {
        toggle.textContent = expanded ? '<<' : '>>';
        toggle.setAttribute('aria-label', expanded ? lessLabel : moreLabel);
      } else {
        toggle.textContent = expanded ? lessLabel : moreLabel;
        toggle.setAttribute('aria-label', expanded ? lessLabel : moreLabel);
      }
    }

    update(channels.classList.contains('is-expanded'));

    toggle.addEventListener('click', function () {
      var expanded = channels.classList.contains('is-expanded');
      update(!expanded);
    });

    if (compactViewport) {
      if (typeof compactViewport.addEventListener === 'function') {
        compactViewport.addEventListener('change', function () {
          update(channels.classList.contains('is-expanded'));
        });
      } else if (typeof compactViewport.addListener === 'function') {
        compactViewport.addListener(function () {
          update(channels.classList.contains('is-expanded'));
        });
      }
    }
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
      messages: [],
      ws: null,
      wsConnected: false,
      wsReconnectTimer: 0,
      wsReconnectBackoffMs: 1000
    };

    function clearWsReconnectTimer() {
      if (!state.wsReconnectTimer) {
        return;
      }

      window.clearTimeout(state.wsReconnectTimer);
      state.wsReconnectTimer = 0;
    }

    function closeLiveUpdatesSocket() {
      clearWsReconnectTimer();
      state.wsConnected = false;
      if (!state.ws) {
        return;
      }

      try {
        state.ws.onopen = null;
        state.ws.onmessage = null;
        state.ws.onerror = null;
        state.ws.onclose = null;
        state.ws.close();
      } catch (error) {
        // Ignore close errors.
      }

      state.ws = null;
    }

    function buildLiveUpdatesWsUrl() {
      if (!state.id || !state.token) {
        return '';
      }

      var raw = String(config.liveUpdatesWsUrl || '').trim();
      if (!raw) {
        return '';
      }

      if (raw.indexOf('http://') === 0) {
        raw = 'ws://' + raw.slice('http://'.length);
      } else if (raw.indexOf('https://') === 0) {
        raw = 'wss://' + raw.slice('https://'.length);
      }

      if (raw.indexOf('ws://') !== 0 && raw.indexOf('wss://') !== 0) {
        return '';
      }

      try {
        var wsUrl = new URL(raw);
        wsUrl.searchParams.set('conversation_id', state.id);
        wsUrl.searchParams.set('conversation_token', state.token);
        return wsUrl.toString();
      } catch (error) {
        return '';
      }
    }

    function scheduleLiveUpdatesReconnect() {
      if (!state.id || !state.token || state.wsReconnectTimer) {
        return;
      }

      var delay = Math.min(30000, Math.max(1000, state.wsReconnectBackoffMs));
      state.wsReconnectTimer = window.setTimeout(function () {
        state.wsReconnectTimer = 0;
        connectLiveUpdates();
      }, delay);
      state.wsReconnectBackoffMs = Math.min(30000, delay * 2);
    }

    function connectLiveUpdates() {
      if (!state.id || !state.token) {
        return;
      }

      if (state.ws && (state.ws.readyState === WebSocket.OPEN || state.ws.readyState === WebSocket.CONNECTING)) {
        return;
      }

      var wsUrl = buildLiveUpdatesWsUrl();
      if (!wsUrl) {
        return;
      }

      clearWsReconnectTimer();

      var socket;
      try {
        socket = new WebSocket(wsUrl);
      } catch (error) {
        scheduleLiveUpdatesReconnect();
        return;
      }

      state.ws = socket;
      socket.onopen = function () {
        state.wsConnected = true;
        state.wsReconnectBackoffMs = 1000;
      };

      socket.onmessage = function (event) {
        var payload = null;
        try {
          payload = JSON.parse(String(event.data || ''));
        } catch (error) {
          return;
        }

        if (!payload || typeof payload !== 'object') {
          return;
        }

        if (payload.type === 'connected') {
          return;
        }

        if (payload.conversation_id && String(payload.conversation_id) !== String(state.id)) {
          return;
        }

        if (payload.type === 'message_added' || payload.type === 'conversation_deleted') {
          fetchConversation(config, state, messagesWrap);
        }
      };

      socket.onerror = function () {
        // Close handler manages reconnect fallback.
      };

      socket.onclose = function () {
        state.wsConnected = false;
        state.ws = null;
        scheduleLiveUpdatesReconnect();
      };
    }

    var resetMinutes = Math.max(0, Number(config.chatResetMinutes || (Number(config.chatResetHours || 0) * 60) || 0));
    if (isChatExpired(resetMinutes)) {
      clearChatSession(state);
    }

    renderMessages(messagesWrap, state.messages);
    emitConversationState(state, state.messages);

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var text = String(input.value || '').trim();
      if (!text) {
        showStatus(status, config.strings.emptyMessage || 'Please enter a message first.', true);
        return;
      }

      var retryMaxAttempts = Math.max(1, Number(config.chatSendRetryMaxAttempts || 3));
      var retryWaitMs = Math.max(0, Number(config.chatSendRetryWaitMs || 500));
      var sendTimeoutMs = Math.max(1000, Number(config.chatSendTimeoutMs || 20000));
      // Backend send_message already performs retries. The browser must wait longer than
      // that whole backend window to avoid triggering duplicate overlapping sends.
      var frontendRequestTimeoutMs = Math.max(
        sendTimeoutMs,
        (retryMaxAttempts * sendTimeoutMs) + (Math.max(0, retryMaxAttempts - 1) * retryWaitMs) + 5000
      );
      var finalFailedNotice = String(config.strings.sendFailedNotice || 'Nora scheint verhindert zu sein. Wir haben einen Mitarbeiter zusaetzlich wegen Ihres Anliegens kontaktiert.');
      var pendingBubble = appendPendingVisitorBubble(messagesWrap, text);
      var thinkingBubble = appendThinkingBubble(messagesWrap, config.strings.aiThinking || 'Nora denkt...');

      if (hooks && typeof hooks.onBeforeSend === 'function') {
        hooks.onBeforeSend();
      }

      send.disabled = true;
      showStatus(status, config.strings.sending || 'Sending...', false);

      var sendPayload = {
        action: 'restatify_mco_send_message',
        nonce: config.nonce,
        conversation_id: state.id,
        conversation_token: state.token,
        message: text,
        website: honeypot ? String(honeypot.value || '') : '',
        source_url: window.location.href
      };
      var attempt = 0;

      function trySendMessage() {
        attempt += 1;
        return postToAjax(config.ajaxUrl, sendPayload, frontendRequestTimeoutMs).then(function (payload) {
          if (!payload || !payload.success || !payload.data || !payload.data.conversation) {
            throw new Error('Invalid response');
          }

          return payload;
        }).catch(function (error) {
          if (attempt >= retryMaxAttempts) {
            throw error;
          }

          return wait(retryWaitMs).then(function () {
            return trySendMessage();
          });
        });
      }

      trySendMessage().then(function (payload) {
        state.id = payload.data.conversation.id || state.id;
        state.token = payload.data.conversation.token || state.token;
        state.messages = Array.isArray(payload.data.conversation.messages) ? payload.data.conversation.messages : [];

        saveStorage(CHAT_STORAGE_ID_KEY, state.id);
        saveStorage(CHAT_STORAGE_TOKEN_KEY, state.token);
        touchChatActivityFromConversation(payload.data.conversation, state.messages);

        input.value = '';
        renderMessages(messagesWrap, state.messages);
        emitConversationState(state, state.messages);
        connectLiveUpdates();
        showStatus(status, '', false);
      }).catch(function () {
        markPendingBubbleAsFailed(pendingBubble, finalFailedNotice);
        showStatus(status, config.strings.sendFailed || 'Message could not be sent. Please try again.', true);
      }).finally(function () {
        removeChatBubble(thinkingBubble);
        send.disabled = false;
      });
    });

    if (state.id && state.token) {
      fetchConversation(config, state, messagesWrap);
      connectLiveUpdates();
    }

    var pollMs = Math.max(3000, Number(config.pollSeconds || 8) * 1000);
    window.setInterval(function () {
      if (!state.id || !state.token) {
        return;
      }

      if (state.wsConnected) {
        return;
      }

      fetchConversation(config, state, messagesWrap);
    }, pollMs);

    window.addEventListener('beforeunload', function () {
      closeLiveUpdatesSocket();
    });
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

  function emitConversationState(state, messages) {
    try {
      document.dispatchEvent(new CustomEvent('restatify:mco-conversation-state', {
        detail: {
          conversationId: state && state.id ? String(state.id) : '',
          messages: Array.isArray(messages) ? messages : []
        }
      }));
    } catch (error) {
      return;
    }
  }

  function fetchConversation(config, state, messagesWrap) {
    return postToAjax(config.ajaxUrl, {
      action: 'restatify_mco_fetch_chat',
      nonce: config.nonce,
      conversation_id: state.id,
      conversation_token: state.token
    }, Math.max(1000, Number(config.chatSendTimeoutMs || 20000))).then(function (payload) {
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
        emitConversationState(state, state.messages);
        return;
      }

      state.messages = fresh;
      if (hadChanges) {
        touchChatActivityFromConversation(payload.data.conversation, fresh);
      }
      renderMessages(messagesWrap, state.messages);
      emitConversationState(state, state.messages);
    }).catch(function () {
      return;
    });
  }

  function postToAjax(url, payload, timeoutMs) {
    var formData = new FormData();
    Object.keys(payload || {}).forEach(function (key) {
      formData.append(key, payload[key] == null ? '' : String(payload[key]));
    });

    var timeout = Math.max(1000, Number(timeoutMs || 20000));
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

  function wait(delayMs) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, Math.max(0, Number(delayMs || 0)));
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

      var prefillData = extractBookingPrefill(text);
      var contactFormPayload = extractContactFormPayload(text);
      var openBooking = sender !== 'visitor' && (text.indexOf(BOOKING_OPEN_TOKEN) !== -1 || !!prefillData);
      var openContactForm = sender !== 'visitor' && (text.indexOf(CONTACT_FORM_OPEN_TOKEN) !== -1 || !!contactFormPayload);
      text = text
        .replace(BOOKING_OPEN_TOKEN, '')
        .replace(/\[\[RESTATIFY_BOOKING_PREFILL\]\]\s*(\{[^\n\r]*\})/g, '')
        .replace(CONTACT_FORM_OPEN_TOKEN, '')
        .replace(/\[\[RESTATIFY_CONTACT_FORM_PAYLOAD\]\]\s*(\{[^\n\r]*\})/g, '')
        .replace(BOOKING_CONFIRMED_TOKEN, '')
        .replace(BOOKING_CANCELLED_TOKEN, '')
        .trim();

      var bubble = document.createElement('div');
      bubble.className = 'restatify-mco__native-bubble is-' + sender;
      var normalizedText = sender === 'visitor' ? text : markdownRenderer.stripEmoji(text);
      bubble.innerHTML = markdownRenderer.renderMarkdownToHtml(normalizedText);
      container.appendChild(bubble);

      if (openBooking) {
        var triggerKey = String(item && item.time_gmt ? item.time_gmt : '') + '|' + sender + '|' + text;
        if (handledBookingTriggers[triggerKey]) {
          return;
        }

        handledBookingTriggers[triggerKey] = true;
        storeHandledBookingTriggers();
        document.dispatchEvent(new CustomEvent('restatify:booking-open', {
          detail: {
            prefill: prefillData || null
          }
        }));
      }

      if (openContactForm) {
        var contactTriggerKey = 'contact|' + String(item && item.time_gmt ? item.time_gmt : '') + '|' + sender + '|' + text;
        if (handledBookingTriggers[contactTriggerKey]) {
          return;
        }

        handledBookingTriggers[contactTriggerKey] = true;
        storeHandledBookingTriggers();

        var formId = contactFormPayload && contactFormPayload.form_id ? String(contactFormPayload.form_id) : '';
        if (formId) {
          try {
            if (window.location.hash !== '#restatify-form-' + formId) {
              window.location.hash = '#restatify-form-' + formId;
            }
          } catch (error) {
            // Keep event-based opening as fallback.
          }
        }

        document.dispatchEvent(new CustomEvent('restatify:form-open', {
          detail: {
            formId: formId,
            prefill: contactFormPayload && contactFormPayload.prefill ? contactFormPayload.prefill : {}
          }
        }));
      }
    });

    container.scrollTop = container.scrollHeight;
  }

  function extractBookingPrefill(text) {
    var value = String(text || '');
    if (value.indexOf(BOOKING_PREFILL_TOKEN) === -1) {
      return null;
    }

    var match = value.match(/\[\[RESTATIFY_BOOKING_PREFILL\]\]\s*(\{[^\n\r]*\})/);
    if (!match || !match[1]) {
      return null;
    }

    try {
      var parsed = JSON.parse(match[1]);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function extractContactFormPayload(text) {
    var value = String(text || '');
    if (value.indexOf(CONTACT_FORM_PAYLOAD_TOKEN) === -1) {
      return null;
    }

    var match = value.match(/\[\[RESTATIFY_CONTACT_FORM_PAYLOAD\]\]\s*(\{[^\n\r]*\})/);
    if (!match || !match[1]) {
      return null;
    }

    try {
      var parsed = JSON.parse(match[1]);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function appendPendingVisitorBubble(container, text) {
    var bubble = document.createElement('div');
    bubble.className = 'restatify-mco__native-bubble is-visitor is-pending';
    bubble.innerHTML = markdownRenderer.renderMarkdownToHtml(text);
    container.appendChild(bubble);
    container.scrollTop = container.scrollHeight;
    return bubble;
  }

  function markPendingBubbleAsFailed(bubble, noticeText) {
    if (!bubble || !bubble.parentNode) {
      return;
    }

    bubble.classList.remove('is-pending');
    bubble.classList.add('is-failed');

    var note = document.createElement('p');
    note.className = 'restatify-mco__native-failed-note';
    note.textContent = String(noticeText || '');
    bubble.appendChild(note);
  }

  function appendThinkingBubble(container, text) {
    var bubble = document.createElement('div');
    bubble.className = 'restatify-mco__native-bubble is-ai is-thinking';

    var spinner = document.createElement('span');
    spinner.className = 'restatify-mco__thinking-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    bubble.appendChild(spinner);

    var label = document.createElement('span');
    label.className = 'restatify-mco__thinking-label';
    label.textContent = String(text || 'Nora denkt...');
    bubble.appendChild(label);

    container.appendChild(bubble);
    container.scrollTop = container.scrollHeight;
    return bubble;
  }

  function removeChatBubble(bubble) {
    if (!bubble || !bubble.parentNode) {
      return;
    }

    bubble.parentNode.removeChild(bubble);
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
