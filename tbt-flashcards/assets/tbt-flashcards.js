/**
 * TBT Flashcards v1.0.0
 * Interactive flashcard widget for The Blue Tree.
 */
(function () {
  'use strict';

  // Track which flashcard instance is active for keyboard navigation.
  var activeContainer = null;

  // Session-wide in-memory cache of text → Blob URL for ElevenLabs audio.
  // Shared across all flashcard instances on the page so the same phrase is
  // only fetched from the proxy once per page load.
  var audioBlobCache = typeof Map !== 'undefined' ? new Map() : null;
  // Fallback simple object cache for very old browsers (no Map).
  var audioBlobCacheFallback = {};

  function getCachedBlobUrl(text) {
    if (audioBlobCache) return audioBlobCache.get(text);
    return audioBlobCacheFallback[text];
  }

  function setCachedBlobUrl(text, url) {
    if (audioBlobCache) {
      audioBlobCache.set(text, url);
    } else {
      audioBlobCacheFallback[text] = url;
    }
  }

  /**
   * Web Speech API fallback — used if the ElevenLabs proxy fails for any
   * reason (network error, API key missing, quota exceeded). We keep this
   * around so the audio button never silently does nothing.
   */
  function speakWithWebSpeech(word) {
    if (typeof speechSynthesis === 'undefined' ||
        typeof SpeechSynthesisUtterance === 'undefined') {
      return;
    }
    try {
      var utterance = new SpeechSynthesisUtterance(word);
      utterance.lang = 'en-GB';
      utterance.rate = 0.9;
      var voices = speechSynthesis.getVoices();
      var british = null;
      for (var i = 0; i < voices.length; i++) {
        if (voices[i].lang === 'en-GB') { british = voices[i]; break; }
      }
      if (!british) {
        for (var j = 0; j < voices.length; j++) {
          if (voices[j].lang.indexOf('en') === 0) { british = voices[j]; break; }
        }
      }
      if (british) utterance.voice = british;
      speechSynthesis.speak(utterance);
    } catch (err) {
      console.error('TBT Flashcards Web Speech fallback:', err);
    }
  }

  /**
   * Parse a single CSV line respecting quoted fields.
   */
  function parseCSVLine(line, delimiter) {
    var result = [];
    var current = '';
    var inQuotes = false;
    for (var i = 0; i < line.length; i++) {
      var ch = line[i];
      if (ch === '"') {
        if (inQuotes && line[i + 1] === '"') {
          current += '"';
          i++;
        } else {
          inQuotes = !inQuotes;
        }
      } else if (ch === delimiter && !inQuotes) {
        result.push(current);
        current = '';
      } else {
        current += ch;
      }
    }
    result.push(current);
    return result.map(function (s) {
      return s.replace(/^"|"$/g, '').trim();
    });
  }

  /**
   * Parse full CSV text into an array of card objects.
   */
  function parseCSV(text) {
    var lines = text.trim().split('\n');
    if (lines.length < 2) return [];

    var header = lines[0];
    var delimiter = header.indexOf('\t') !== -1 ? '\t' : header.indexOf(';') !== -1 ? ';' : ',';
    var headers = header.split(delimiter).map(function (h) {
      return h.trim().toLowerCase().replace(/["']/g, '');
    });

    var wordAliases = ['word', 'english', 'en', 'front'];
    var translationAliases = ['translation', 'polish', 'pl', 'back'];
    var phoneticAliases = ['phonetic', 'pronunciation', 'ipa'];
    var exampleAliases = ['example', 'sentence', 'context'];

    function findIndex(aliases) {
      for (var i = 0; i < aliases.length; i++) {
        var idx = headers.indexOf(aliases[i]);
        if (idx !== -1) return idx;
      }
      return -1;
    }

    var wordIdx = findIndex(wordAliases);
    var translationIdx = findIndex(translationAliases);
    var phoneticIdx = findIndex(phoneticAliases);
    var exampleIdx = findIndex(exampleAliases);

    if (wordIdx === -1 || translationIdx === -1) return [];

    var cards = [];
    for (var i = 1; i < lines.length; i++) {
      var cols = parseCSVLine(lines[i], delimiter);
      if (cols.length <= Math.max(wordIdx, translationIdx)) continue;
      var word = (cols[wordIdx] || '').trim();
      if (!word) continue;
      cards.push({
        word: word,
        phonetic: phoneticIdx >= 0 ? (cols[phoneticIdx] || '').trim() : '',
        translation: (cols[translationIdx] || '').trim(),
        example: exampleIdx >= 0 ? (cols[exampleIdx] || '').trim() : ''
      });
    }

    return cards;
  }

  /**
   * Confetti animation.
   */
  function launchConfetti(canvas) {
    var ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;

    var colors = ['#0859C6', '#3b82f6', '#f59e0b', '#ef4444', '#10b981', '#8b5cf6', '#f472b6', '#06b6d4'];
    var pieces = [];
    var count = 150;

    for (var i = 0; i < count; i++) {
      pieces.push({
        x: Math.random() * canvas.width,
        y: Math.random() * -canvas.height,
        w: Math.random() * 10 + 5,
        h: Math.random() * 6 + 3,
        color: colors[Math.floor(Math.random() * colors.length)],
        vx: (Math.random() - 0.5) * 4,
        vy: Math.random() * 4 + 2,
        rot: Math.random() * 360,
        rotSpeed: (Math.random() - 0.5) * 12,
        opacity: 1
      });
    }

    var startTime = Date.now();
    var duration = 3000;

    function animate() {
      var elapsed = Date.now() - startTime;
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      var fadeStart = duration - 800;
      var globalAlpha = elapsed > fadeStart ? 1 - (elapsed - fadeStart) / 800 : 1;

      for (var j = 0; j < pieces.length; j++) {
        var p = pieces[j];
        p.x += p.vx;
        p.y += p.vy;
        p.vy += 0.08;
        p.rot += p.rotSpeed;

        ctx.save();
        ctx.globalAlpha = globalAlpha * p.opacity;
        ctx.translate(p.x, p.y);
        ctx.rotate((p.rot * Math.PI) / 180);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
        ctx.restore();
      }

      if (elapsed < duration) {
        requestAnimationFrame(animate);
      } else {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
      }
    }

    animate();
  }

  /**
   * Initialize a single flashcard widget instance.
   */
  function initInstance(container) {
    var csvUrl = container.getAttribute('data-csv-url');
    if (!csvUrl) return;

    var cards = [];
    var currentIndex = 0;
    var isFlipped = false;
    var confettiFired = false;

    // DOM references scoped to this instance.
    var cardScene = container.querySelector('.fc-card-scene');
    var cardEl = container.querySelector('.fc-card');
    var wordText = container.querySelector('.fc-word');
    var audioBtn = container.querySelector('.fc-audio-btn');
    var translationText = container.querySelector('.fc-translation');
    var phoneticText = container.querySelector('.fc-phonetic');
    var exampleText = container.querySelector('.fc-example');
    var backDivider = container.querySelector('.fc-back-divider');
    var counterCurrentEls = container.querySelectorAll('.fc-counter-current');
    var counterTotalEls = container.querySelectorAll('.fc-counter-total');
    var prevBtn = container.querySelector('.fc-prev-btn');
    var nextBtn = container.querySelector('.fc-next-btn');
    var confettiCanvas = container.querySelector('.fc-confetti');
    var finishMsg = null;

    function isFinishCard() {
      return currentIndex === cards.length;
    }

    function renderCard() {
      if (isFinishCard()) {
        wordText.textContent = '';
        audioBtn.style.display = 'none';
        translationText.textContent = '';
        phoneticText.textContent = '';
        phoneticText.style.display = 'none';
        exampleText.textContent = '';
        exampleText.style.display = 'none';
        if (backDivider) backDivider.style.display = 'none';

        if (!finishMsg) {
          finishMsg = document.createElement('div');
          finishMsg.className = 'fc-finish-message';
          finishMsg.textContent = 'Well done!';
          var sub = document.createElement('div');
          sub.className = 'fc-finish-sub';
          sub.textContent = 'You completed the whole set.';
          finishMsg.appendChild(sub);
          container.querySelector('.fc-card-front').appendChild(finishMsg);
        }
        finishMsg.style.display = 'block';

        for (var ci = 0; ci < counterCurrentEls.length; ci++) counterCurrentEls[ci].textContent = cards.length;
        for (var ti = 0; ti < counterTotalEls.length; ti++) counterTotalEls[ti].textContent = cards.length;
        prevBtn.disabled = false;
        nextBtn.disabled = true;

        cardEl.classList.remove('is-flipped');
        isFlipped = false;

        if (!confettiFired) {
          confettiFired = true;
          launchConfetti(confettiCanvas);
        }
        return;
      }

      // Hide finish message when going back.
      if (finishMsg) finishMsg.style.display = 'none';
      audioBtn.style.display = 'flex';

      var card = cards[currentIndex];
      wordText.textContent = card.word;
      translationText.textContent = card.translation;
      phoneticText.textContent = card.phonetic || '';
      phoneticText.style.display = card.phonetic ? '' : 'none';
      exampleText.textContent = card.example || '';
      exampleText.style.display = card.example ? '' : 'none';
      if (backDivider) backDivider.style.display = card.example ? '' : 'none';
      for (var ci = 0; ci < counterCurrentEls.length; ci++) counterCurrentEls[ci].textContent = currentIndex + 1;
      for (var ti = 0; ti < counterTotalEls.length; ti++) counterTotalEls[ti].textContent = cards.length;
      prevBtn.disabled = currentIndex === 0;
      nextBtn.disabled = false;

      if (isFlipped) {
        isFlipped = false;
        cardEl.classList.remove('is-flipped');
      }
    }

    function flipCard() {
      if (isFinishCard()) return;
      isFlipped = !isFlipped;
      cardEl.classList.toggle('is-flipped', isFlipped);
    }

    function nextCard() {
      if (currentIndex >= cards.length) return;
      currentIndex++;
      cardEl.classList.remove('slide-left', 'slide-right');
      void cardEl.offsetWidth; // force reflow
      cardEl.classList.add('slide-left');
      renderCard();
    }

    function prevCard() {
      if (currentIndex <= 0) return;
      currentIndex--;
      cardEl.classList.remove('slide-left', 'slide-right');
      void cardEl.offsetWidth;
      cardEl.classList.add('slide-right');
      renderCard();
    }

    // Currently playing Audio element, so a new click cancels the previous one.
    var currentAudio = null;
    // Timer used to auto-clear the transient error state on the audio button.
    var audioErrorTimer = null;

    function setAudioLoading(isLoading) {
      if (isLoading) {
        audioBtn.classList.add('is-loading');
        audioBtn.setAttribute('aria-busy', 'true');
      } else {
        audioBtn.classList.remove('is-loading');
        audioBtn.removeAttribute('aria-busy');
      }
    }

    function showAudioError() {
      audioBtn.classList.add('has-error');
      if (audioErrorTimer) clearTimeout(audioErrorTimer);
      audioErrorTimer = setTimeout(function () {
        audioBtn.classList.remove('has-error');
        audioErrorTimer = null;
      }, 1800);
    }

    function playBlobUrl(url, word) {
      try {
        if (currentAudio) {
          try { currentAudio.pause(); } catch (_) {}
        }
        var audio = new Audio(url);
        currentAudio = audio;
        var playPromise = audio.play();
        if (playPromise && typeof playPromise.catch === 'function') {
          playPromise.catch(function (err) {
            console.warn('TBT Flashcards audio playback failed:', err);
            speakWithWebSpeech(word);
          });
        }
      } catch (err) {
        console.warn('TBT Flashcards audio playback exception:', err);
        speakWithWebSpeech(word);
      }
    }

    function playAudio(e) {
      if (e) e.stopPropagation();
      var word = cards[currentIndex] ? cards[currentIndex].word : null;
      if (!word) return;

      // 1) In-memory Blob URL cache — instant replay.
      var cachedUrl = getCachedBlobUrl(word);
      if (cachedUrl) {
        playBlobUrl(cachedUrl, word);
        return;
      }

      // 2) No proxy configured on the page → straight to fallback.
      if (typeof tbtFcAjax === 'undefined' || !tbtFcAjax || !tbtFcAjax.ajaxurl) {
        speakWithWebSpeech(word);
        return;
      }

      // 3) Fetch from the WordPress AJAX proxy.
      setAudioLoading(true);

      var body = new URLSearchParams();
      body.append('action', 'tbt_fc_tts');
      body.append('nonce', tbtFcAjax.nonce);
      body.append('text', word);

      fetch(tbtFcAjax.ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString()
      })
        .then(function (response) {
          var ct = response.headers.get('Content-Type') || '';
          if (!response.ok || ct.indexOf('audio/') !== 0) {
            // Likely a JSON error payload from the proxy.
            return response.text().then(function (text) {
              var msg = 'HTTP ' + response.status;
              try {
                var parsed = JSON.parse(text);
                if (parsed && parsed.error) msg = parsed.error;
              } catch (_) {}
              throw new Error(msg);
            });
          }
          return response.blob();
        })
        .then(function (blob) {
          if (!blob || blob.size === 0) throw new Error('Empty audio response');
          var url = URL.createObjectURL(blob);
          setCachedBlobUrl(word, url);
          playBlobUrl(url, word);
        })
        .catch(function (err) {
          console.warn('TBT Flashcards TTS proxy failed:', err && err.message ? err.message : err);
          showAudioError();
          // Last-resort fallback so the button never does nothing.
          speakWithWebSpeech(word);
        })
        .then(function () {
          // Finally-ish: clear the spinner in both success and failure paths.
          setAudioLoading(false);
        });
    }

    // Bind events.
    cardScene.addEventListener('click', function () {
      flipCard();
    });

    audioBtn.addEventListener('click', function (e) {
      playAudio(e);
    });

    prevBtn.addEventListener('click', function () {
      prevCard();
    });

    nextBtn.addEventListener('click', function () {
      nextCard();
    });

    // Set this instance as active when clicked.
    container.addEventListener('click', function () {
      activeContainer = container;
    });

    // Default the first initialised instance as active.
    if (!activeContainer) {
      activeContainer = container;
    }

    // Keyboard support — only for the active instance.
    document.addEventListener('keydown', function (e) {
      if (activeContainer !== container) return;
      if (cards.length === 0) return;
      // Skip if user is typing in an input/textarea.
      var tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

      if (e.key === 'ArrowRight') { nextCard(); e.preventDefault(); }
      else if (e.key === 'ArrowLeft') { prevCard(); e.preventDefault(); }
      else if (e.key === ' ') { flipCard(); e.preventDefault(); }
    });

    // Preload voices for speech synthesis.
    if (typeof speechSynthesis !== 'undefined' && speechSynthesis.onvoiceschanged !== undefined) {
      speechSynthesis.onvoiceschanged = function () {};
    }

    // Fetch and parse the CSV.
    fetch(csvUrl)
      .then(function (response) {
        if (!response.ok) throw new Error('Failed to load CSV');
        return response.text();
      })
      .then(function (text) {
        cards = parseCSV(text);
        if (cards.length > 0) {
          currentIndex = 0;
          isFlipped = false;
          renderCard();
        }
      })
      .catch(function (err) {
        wordText.textContent = 'Error loading flashcards';
        console.error('TBT Flashcards:', err);
      });
  }

  /**
   * Initialize all flashcard instances on the page.
   */
  function init() {
    var containers = document.querySelectorAll('.tbt-flashcard-app');
    for (var i = 0; i < containers.length; i++) {
      initInstance(containers[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
