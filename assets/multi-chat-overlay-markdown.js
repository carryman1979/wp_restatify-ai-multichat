(function () {
  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function stripEmoji(value) {
    var text = String(value || '');
    try {
      return text.replace(/\p{Extended_Pictographic}/gu, '').replace(/[\uFE0F\u200D]/g, '');
    } catch (error) {
      return text;
    }
  }

  function applyInlineMarkdown(line) {
    var safe = escapeHtml(line);
    safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    safe = safe.replace(/\*(.+?)\*/g, '<em>$1</em>');
    return safe;
  }

  function renderMarkdownToHtml(text) {
    var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
    var html = [];
    var paragraph = [];
    var listType = '';

    function flushParagraph() {
      if (!paragraph.length) {
        return;
      }
      html.push('<p>' + paragraph.join('<br>') + '</p>');
      paragraph = [];
    }

    function closeList() {
      if (!listType) {
        return;
      }
      html.push('</' + listType + '>');
      listType = '';
    }

    lines.forEach(function (rawLine) {
      var line = String(rawLine || '');
      var trimmed = line.trim();

      if (!trimmed) {
        flushParagraph();
        closeList();
        return;
      }

      var unorderedMatch = /^[-*]\s+(.+)$/.exec(trimmed);
      if (unorderedMatch) {
        flushParagraph();
        if (listType !== 'ul') {
          closeList();
          listType = 'ul';
          html.push('<ul>');
        }
        html.push('<li>' + applyInlineMarkdown(unorderedMatch[1]) + '</li>');
        return;
      }

      var orderedMatch = /^\d+[\.)]\s+(.+)$/.exec(trimmed);
      if (orderedMatch) {
        flushParagraph();
        if (listType !== 'ol') {
          closeList();
          listType = 'ol';
          html.push('<ol>');
        }
        html.push('<li>' + applyInlineMarkdown(orderedMatch[1]) + '</li>');
        return;
      }

      closeList();
      paragraph.push(applyInlineMarkdown(trimmed));
    });

    flushParagraph();
    closeList();

    return html.join('');
  }

  window.RestatifyMcoMarkdown = {
    stripEmoji: stripEmoji,
    renderMarkdownToHtml: renderMarkdownToHtml
  };
})();
