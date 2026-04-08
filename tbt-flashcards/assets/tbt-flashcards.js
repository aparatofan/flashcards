/**
 * TBT Flashcards v1.0.0
 * Interactive flashcard widget for The Blue Tree.
 */
(function () {
  'use strict';

  // Track which flashcard instance is active for keyboard navigation.
  var activeContainer = null;

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
    var tapHint = container.querySelector('.fc-tap-hint');
    var translationText = container.querySelector('.fc-translation');
    var phoneticText = container.querySelector('.fc-phonetic');
    var exampleText = container.querySelector('.fc-example');
    var counterCurrent = container.querySelector('.fc-counter-current');
    var counterTotal = container.querySelector('.fc-counter-total');
    var prevBtn = container.querySelector('.fc-prev-btn');
    var nextBtn = container.querySelector('.fc-next-btn');
    var flipBtn = container.querySelector('.fc-flip-btn');
    var confettiCanvas = container.querySelector('.fc-confetti');
    var finishMsg = null;

    function isFinishCard() {
      return currentIndex === cards.length;
    }

    function renderCard() {
      if (isFinishCard()) {
        wordText.textContent = '';
        audioBtn.style.display = 'none';
        tapHint.style.display = 'none';
        translationText.textContent = '';
        phoneticText.textContent = '';
        exampleText.textContent = '';

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

        counterCurrent.textContent = cards.length;
        counterTotal.textContent = cards.length;
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
      tapHint.style.display = 'block';

      var card = cards[currentIndex];
      wordText.textContent = card.word;
      translationText.textContent = card.translation;
      phoneticText.textContent = card.phonetic || '';
      exampleText.textContent = card.example || '';
      counterCurrent.textContent = currentIndex + 1;
      counterTotal.textContent = cards.length;
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

    function playAudio(e) {
      if (e) e.stopPropagation();
      var word = cards[currentIndex] ? cards[currentIndex].word : null;
      if (!word) return;
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
    }

    // Bind events.
    cardScene.addEventListener('click', function () {
      flipCard();
    });

    audioBtn.addEventListener('click', function (e) {
      playAudio(e);
    });

    flipBtn.addEventListener('click', function () {
      flipCard();
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
