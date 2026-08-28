/* ═══════════════════════════════════════════════════════════════
   Yassota Tools Engine — 200 real, working, client-side web tools
   ---------------------------------------------------------------
   Every tool is a pure function of its inputs → outputs. No server
   round-trips, no tracking. Each registered tool declares:
     { id, cat, name, desc, icon, ui:[fields], run:(v)=>result }
   `ui` builds the form; `run` receives the field values and returns
   a string / html / {stats} / {error}. The engine renders it all.
   =============================================================== */
(function () {
  'use strict';

  /* ---- tiny helpers ---- */
  const $ = (s, r = document) => r.querySelector(s);
  const el = (t, a = {}, kids = []) => {
    const n = document.createElement(t);
    for (const k in a) {
      if (k === 'class') n.className = a[k];
      else if (k === 'html') n.innerHTML = a[k];
      else if (k.startsWith('on') && typeof a[k] === 'function') n.addEventListener(k.slice(2), a[k]);
      else if (a[k] != null) n.setAttribute(k, a[k]);
    }
    (Array.isArray(kids) ? kids : [kids]).forEach(c => c != null && n.append(c.nodeType ? c : document.createTextNode(c)));
    return n;
  };
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (x, d = 0) => { const n = parseFloat(x); return isNaN(n) ? d : n; };
  const bytes = n => { const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; n = Math.abs(n); while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; } return n.toFixed(n < 10 && i ? 1 : 0) + ' ' + u[i]; };
  const copy = txt => navigator.clipboard && navigator.clipboard.writeText(txt);

  const TOOLS = [];
  const T = (t) => { TOOLS.push(t); return t; };

  /* ============================================================
     CATEGORY 1 — TEXT  (25 tools)
     ============================================================ */
  T({ id: 'word-counter', cat: 'text', name: 'Word Counter', desc: 'Count words, characters, sentences and reading time.', icon: '📝',
    ui: [{ k: 'text', t: 'textarea', label: 'Your text', ph: 'Paste text here…' }],
    run: v => {
      const t = v.text || '', words = (t.trim().match(/\S+/g) || []).length;
      const sentences = (t.match(/[.!?]+/g) || []).length;
      return { stats: [
        ['Words', words], ['Characters', t.length],
        ['Characters (no spaces)', t.replace(/\s/g, '').length],
        ['Sentences', sentences], ['Paragraphs', (t.split(/\n\s*\n/).filter(x => x.trim()).length) || (t.trim() ? 1 : 0)],
        ['Reading time', Math.max(1, Math.round(words / 200)) + ' min'] ] };
    } });

  T({ id: 'case-converter', cat: 'text', name: 'Case Converter', desc: 'UPPER, lower, Title, Sentence, camelCase and more.', icon: '🔠',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'mode', t: 'select', label: 'Convert to', opts: ['UPPERCASE', 'lowercase', 'Title Case', 'Sentence case', 'camelCase', 'snake_case', 'kebab-case', 'aLtErNaTiNg'] }],
    run: v => {
      const t = v.text || '', m = v.mode;
      if (m === 'UPPERCASE') return t.toUpperCase();
      if (m === 'lowercase') return t.toLowerCase();
      if (m === 'Title Case') return t.replace(/\w\S*/g, w => w[0].toUpperCase() + w.slice(1).toLowerCase());
      if (m === 'Sentence case') return t.toLowerCase().replace(/(^\s*\w|[.!?]\s*\w)/g, c => c.toUpperCase());
      if (m === 'camelCase') return t.toLowerCase().replace(/[^a-z0-9]+(.)/g, (_, c) => c.toUpperCase());
      if (m === 'snake_case') return t.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
      if (m === 'kebab-case') return t.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      return [...t].map((c, i) => i % 2 ? c.toUpperCase() : c.toLowerCase()).join('');
    } });

  T({ id: 'remove-duplicate-lines', cat: 'text', name: 'Remove Duplicate Lines', desc: 'Delete repeated lines, optionally case-insensitive.', icon: '🧹',
    ui: [{ k: 'text', t: 'textarea', label: 'Lines' }, { k: 'ci', t: 'checkbox', label: 'Case-insensitive' }, { k: 'sort', t: 'checkbox', label: 'Sort result' }],
    run: v => {
      const seen = new Set(); let out = [];
      (v.text || '').split('\n').forEach(l => { const key = v.ci ? l.toLowerCase() : l; if (!seen.has(key)) { seen.add(key); out.push(l); } });
      if (v.sort) out.sort((a, b) => a.localeCompare(b));
      return out.join('\n');
    } });

  T({ id: 'sort-lines', cat: 'text', name: 'Line Sorter', desc: 'Sort lines alphabetically, reverse or by length.', icon: '↕️',
    ui: [{ k: 'text', t: 'textarea', label: 'Lines' }, { k: 'mode', t: 'select', label: 'Order', opts: ['A → Z', 'Z → A', 'Shortest first', 'Longest first', 'Reverse', 'Shuffle'] }],
    run: v => { let a = (v.text || '').split('\n');
      const m = v.mode;
      if (m === 'A → Z') a.sort((x, y) => x.localeCompare(y));
      else if (m === 'Z → A') a.sort((x, y) => y.localeCompare(x));
      else if (m === 'Shortest first') a.sort((x, y) => x.length - y.length);
      else if (m === 'Longest first') a.sort((x, y) => y.length - x.length);
      else if (m === 'Reverse') a.reverse();
      else for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1));[a[i], a[j]] = [a[j], a[i]]; }
      return a.join('\n');
    } });

  T({ id: 'find-replace', cat: 'text', name: 'Find & Replace', desc: 'Replace text with optional regex and case sensitivity.', icon: '🔁',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'find', t: 'text', label: 'Find' }, { k: 'rep', t: 'text', label: 'Replace with' }, { k: 're', t: 'checkbox', label: 'Regex' }, { k: 'ci', t: 'checkbox', label: 'Ignore case' }],
    run: v => { try { const flags = 'g' + (v.ci ? 'i' : ''); const pat = v.re ? new RegExp(v.find, flags) : new RegExp(v.find.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), flags); return (v.text || '').replace(pat, v.rep || ''); } catch (e) { return { error: 'Bad pattern: ' + e.message }; } } });

  T({ id: 'reverse-text', cat: 'text', name: 'Text Reverser', desc: 'Reverse characters, words or lines.', icon: '🪞',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'mode', t: 'select', label: 'Reverse by', opts: ['Characters', 'Words', 'Lines'] }],
    run: v => { const t = v.text || ''; if (v.mode === 'Words') return t.split(/\s+/).reverse().join(' '); if (v.mode === 'Lines') return t.split('\n').reverse().join('\n'); return [...t].reverse().join(''); } });

  T({ id: 'text-repeater', cat: 'text', name: 'Text Repeater', desc: 'Repeat any text N times with a separator.', icon: '🔂',
    ui: [{ k: 'text', t: 'text', label: 'Text' }, { k: 'n', t: 'number', label: 'Times', def: 5 }, { k: 'sep', t: 'select', label: 'Separator', opts: ['New line', 'Space', 'Comma', 'None'] }],
    run: v => { const s = { 'New line': '\n', 'Space': ' ', 'Comma': ', ', 'None': '' }[v.sep]; return Array(Math.max(0, Math.min(10000, num(v.n, 0)))).fill(v.text || '').join(s); } });

  T({ id: 'lorem-ipsum', cat: 'text', name: 'Lorem Ipsum Generator', desc: 'Generate placeholder paragraphs.', icon: '📄',
    ui: [{ k: 'n', t: 'number', label: 'Paragraphs', def: 3 }],
    run: v => { const w = 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea commodo'.split(' ');
      const p = []; for (let i = 0; i < Math.max(1, Math.min(50, num(v.n, 3))); i++) { let s = []; const len = 30 + Math.floor(Math.random() * 30); for (let j = 0; j < len; j++) s.push(w[Math.floor(Math.random() * w.length)]); let str = s.join(' '); p.push(str[0].toUpperCase() + str.slice(1) + '.'); } return p.join('\n\n'); } });

  T({ id: 'remove-extra-spaces', cat: 'text', name: 'Whitespace Cleaner', desc: 'Trim and collapse extra spaces and blank lines.', icon: '␣',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => (v.text || '').replace(/[ \t]+/g, ' ').replace(/ *\n */g, '\n').replace(/\n{3,}/g, '\n\n').trim() });

  T({ id: 'count-char-freq', cat: 'text', name: 'Character Frequency', desc: 'Count how often each character appears.', icon: '📊',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => { const f = {}; for (const c of (v.text || '')) if (c.trim()) f[c] = (f[c] || 0) + 1; return Object.entries(f).sort((a, b) => b[1] - a[1]).map(([c, n]) => `${c}  →  ${n}`).join('\n') || '(no characters)'; } });

  T({ id: 'text-to-slug', cat: 'text', name: 'Slug Generator', desc: 'Turn any title into a clean URL slug.', icon: '🔗',
    ui: [{ k: 'text', t: 'text', label: 'Title' }],
    run: v => (v.text || '').toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-') });

  T({ id: 'remove-line-breaks', cat: 'text', name: 'Remove Line Breaks', desc: 'Join wrapped lines into one paragraph.', icon: '↩️',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => (v.text || '').replace(/\s*\n\s*/g, ' ').trim() });

  T({ id: 'add-line-numbers', cat: 'text', name: 'Add Line Numbers', desc: 'Prefix each line with its number.', icon: '#️⃣',
    ui: [{ k: 'text', t: 'textarea', label: 'Lines' }],
    run: v => (v.text || '').split('\n').map((l, i) => `${String(i + 1).padStart(3, ' ')}. ${l}`).join('\n') });

  T({ id: 'text-stats-uniqueness', cat: 'text', name: 'Unique Words', desc: 'List and count unique words.', icon: '🔤',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => { const w = (v.text || '').toLowerCase().match(/[a-zA-Z\u0600-\u06FF']+/g) || []; const u = [...new Set(w)]; return { stats: [['Total words', w.length], ['Unique words', u.length], ['Repetition', w.length ? (100 - u.length / w.length * 100).toFixed(0) + '%' : '0%']] }; } });

  T({ id: 'palindrome-check', cat: 'text', name: 'Palindrome Checker', desc: 'Check if text reads the same both ways.', icon: '🔄',
    ui: [{ k: 'text', t: 'text', label: 'Word or phrase' }],
    run: v => { const c = (v.text || '').toLowerCase().replace(/[^a-z0-9\u0600-\u06FF]/g, ''); return c && c === [...c].reverse().join('') ? '✅ Yes — it is a palindrome.' : '❌ No — not a palindrome.'; } });

  T({ id: 'rot13', cat: 'text', name: 'ROT13 Cipher', desc: 'Encode/decode with the classic ROT13.', icon: '🔐',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => (v.text || '').replace(/[a-zA-Z]/g, c => String.fromCharCode((c <= 'Z' ? 90 : 122) >= (c = c.charCodeAt(0) + 13) ? c : c - 26)) });

  T({ id: 'morse-code', cat: 'text', name: 'Morse Code Translator', desc: 'Convert text to/from Morse code.', icon: '📡',
    ui: [{ k: 'text', t: 'textarea', label: 'Text or Morse' }, { k: 'mode', t: 'select', label: 'Direction', opts: ['Text → Morse', 'Morse → Text'] }],
    run: v => { const M = { A: '.-', B: '-...', C: '-.-.', D: '-..', E: '.', F: '..-.', G: '--.', H: '....', I: '..', J: '.---', K: '-.-', L: '.-..', M: '--', N: '-.', O: '---', P: '.--.', Q: '--.-', R: '.-.', S: '...', T: '-', U: '..-', V: '...-', W: '.--', X: '-..-', Y: '-.--', Z: '--..', '0': '-----', '1': '.----', '2': '..---', '3': '...--', '4': '....-', '5': '.....', '6': '-....', '7': '--...', '8': '---..', '9': '----.', ' ': '/' };
      if (v.mode === 'Text → Morse') return (v.text || '').toUpperCase().split('').map(c => M[c] || '').join(' ').trim();
      const R = Object.fromEntries(Object.entries(M).map(([k, x]) => [x, k])); return (v.text || '').trim().split(' ').map(c => R[c] || (c === '/' ? ' ' : '')).join(''); } });

  T({ id: 'binary-text', cat: 'text', name: 'Text ⇄ Binary', desc: 'Convert text to binary and back.', icon: '💾',
    ui: [{ k: 'text', t: 'textarea', label: 'Text or binary' }, { k: 'mode', t: 'select', label: 'Direction', opts: ['Text → Binary', 'Binary → Text'] }],
    run: v => { if (v.mode === 'Text → Binary') return [...(v.text || '')].map(c => c.charCodeAt(0).toString(2).padStart(8, '0')).join(' '); try { return (v.text || '').trim().split(/\s+/).map(b => String.fromCharCode(parseInt(b, 2))).join(''); } catch (e) { return { error: 'Invalid binary' }; } } });

  T({ id: 'text-truncate', cat: 'text', name: 'Text Truncator', desc: 'Cut text to a length with an ellipsis.', icon: '✂️',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'len', t: 'number', label: 'Max characters', def: 120 }],
    run: v => { const t = v.text || '', n = num(v.len, 120); return t.length <= n ? t : t.slice(0, n).trim() + '…'; } });

  T({ id: 'extract-emails', cat: 'text', name: 'Email Extractor', desc: 'Pull all email addresses from text.', icon: '📧',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => { const m = [...new Set((v.text || '').match(/[\w.+-]+@[\w-]+\.[\w.-]+/g) || [])]; return m.length ? m.join('\n') : '(no emails found)'; } });

  T({ id: 'extract-urls', cat: 'text', name: 'URL Extractor', desc: 'Extract all links from a block of text.', icon: '🌐',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => { const m = [...new Set((v.text || '').match(/https?:\/\/[^\s<>"')]+/g) || [])]; return m.length ? m.join('\n') : '(no URLs found)'; } });

  T({ id: 'text-to-html-list', cat: 'text', name: 'Lines → HTML List', desc: 'Turn lines into a <ul> or <ol>.', icon: '📋',
    ui: [{ k: 'text', t: 'textarea', label: 'Lines' }, { k: 'type', t: 'select', label: 'List type', opts: ['Unordered (ul)', 'Ordered (ol)'] }],
    run: v => { const tag = v.type[0] === 'O' ? 'ol' : 'ul'; const items = (v.text || '').split('\n').filter(x => x.trim()).map(l => '  <li>' + esc(l.trim()) + '</li>').join('\n'); return `<${tag}>\n${items}\n</${tag}>`; } });

  T({ id: 'nato-alphabet', cat: 'text', name: 'NATO Phonetic', desc: 'Spell words with the NATO alphabet.', icon: '🎙️',
    ui: [{ k: 'text', t: 'text', label: 'Text' }],
    run: v => { const N = { A: 'Alpha', B: 'Bravo', C: 'Charlie', D: 'Delta', E: 'Echo', F: 'Foxtrot', G: 'Golf', H: 'Hotel', I: 'India', J: 'Juliett', K: 'Kilo', L: 'Lima', M: 'Mike', N: 'November', O: 'Oscar', P: 'Papa', Q: 'Quebec', R: 'Romeo', S: 'Sierra', T: 'Tango', U: 'Uniform', V: 'Victor', W: 'Whiskey', X: 'Xray', Y: 'Yankee', Z: 'Zulu' }; return (v.text || '').toUpperCase().split('').map(c => N[c] || c).join(' '); } });

  T({ id: 'title-capitalizer', cat: 'text', name: 'Headline Capitalizer', desc: 'Proper title-case ignoring small words.', icon: '🅰️',
    ui: [{ k: 'text', t: 'text', label: 'Headline' }],
    run: v => { const small = new Set(['a', 'an', 'the', 'and', 'but', 'or', 'for', 'nor', 'on', 'at', 'to', 'by', 'of', 'in', 'with']); return (v.text || '').toLowerCase().split(/\s+/).map((w, i, a) => (i === 0 || i === a.length - 1 || !small.has(w)) ? w.charAt(0).toUpperCase() + w.slice(1) : w).join(' '); } });

  /* ============================================================
     CATEGORY 2 — MATH & NUMBERS  (20 tools)
     ============================================================ */
  T({ id: 'percentage-calc', cat: 'math', name: 'Percentage Calculator', desc: 'What is X% of Y, and X is what % of Y.', icon: '％',
    ui: [{ k: 'a', t: 'number', label: 'X' }, { k: 'b', t: 'number', label: 'Y' }],
    run: v => { const a = num(v.a), b = num(v.b); return { stats: [[`${a}% of ${b}`, (a * b / 100).toLocaleString()], [`${a} is what % of ${b}`, b ? (a / b * 100).toFixed(2) + '%' : '∞'], ['% change ' + a + '→' + b, a ? ((b - a) / a * 100).toFixed(2) + '%' : '∞']] }; } });

  T({ id: 'avg-calc', cat: 'math', name: 'Average / Mean / Median', desc: 'Statistics for a list of numbers.', icon: '📈',
    ui: [{ k: 'nums', t: 'textarea', label: 'Numbers (any separator)' }],
    run: v => { const a = (v.nums || '').split(/[^\d.\-]+/).map(parseFloat).filter(x => !isNaN(x)); if (!a.length) return { error: 'Enter some numbers' }; const sum = a.reduce((s, x) => s + x, 0); const sorted = [...a].sort((x, y) => x - y); const mid = Math.floor(sorted.length / 2); const median = sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2; const mean = sum / a.length; const sd = Math.sqrt(a.reduce((s, x) => s + (x - mean) ** 2, 0) / a.length); return { stats: [['Count', a.length], ['Sum', sum.toLocaleString()], ['Mean', mean.toFixed(3)], ['Median', median], ['Min', Math.min(...a)], ['Max', Math.max(...a)], ['Std dev', sd.toFixed(3)]] }; } });

  T({ id: 'tip-calc', cat: 'math', name: 'Tip Calculator', desc: 'Split a bill and tip between people.', icon: '🧾',
    ui: [{ k: 'bill', t: 'number', label: 'Bill amount' }, { k: 'tip', t: 'number', label: 'Tip %', def: 15 }, { k: 'ppl', t: 'number', label: 'People', def: 1 }],
    run: v => { const b = num(v.bill), t = num(v.tip), p = Math.max(1, num(v.ppl, 1)); const tip = b * t / 100, total = b + tip; return { stats: [['Tip', tip.toFixed(2)], ['Total', total.toFixed(2)], ['Per person', (total / p).toFixed(2)]] }; } });

  T({ id: 'discount-calc', cat: 'math', name: 'Discount Calculator', desc: 'Final price after a percentage off.', icon: '🏷️',
    ui: [{ k: 'price', t: 'number', label: 'Original price' }, { k: 'off', t: 'number', label: 'Discount %' }],
    run: v => { const p = num(v.price), o = num(v.off); const save = p * o / 100; return { stats: [['You save', save.toFixed(2)], ['Final price', (p - save).toFixed(2)]] }; } });

  T({ id: 'loan-calc', cat: 'math', name: 'Loan / EMI Calculator', desc: 'Monthly payment for a loan.', icon: '🏦',
    ui: [{ k: 'p', t: 'number', label: 'Principal' }, { k: 'r', t: 'number', label: 'Annual interest %' }, { k: 'n', t: 'number', label: 'Months', def: 12 }],
    run: v => { const p = num(v.p), r = num(v.r) / 1200, n = Math.max(1, num(v.n, 1)); const emi = r ? p * r * Math.pow(1 + r, n) / (Math.pow(1 + r, n) - 1) : p / n; return { stats: [['Monthly payment', emi.toFixed(2)], ['Total paid', (emi * n).toFixed(2)], ['Total interest', (emi * n - p).toFixed(2)]] }; } });

  T({ id: 'bmi-calc', cat: 'math', name: 'BMI Calculator', desc: 'Body Mass Index from height & weight.', icon: '⚖️',
    ui: [{ k: 'w', t: 'number', label: 'Weight (kg)' }, { k: 'h', t: 'number', label: 'Height (cm)' }],
    run: v => { const w = num(v.w), h = num(v.h) / 100; if (!h) return { error: 'Enter height' }; const bmi = w / (h * h); const cat = bmi < 18.5 ? 'Underweight' : bmi < 25 ? 'Normal' : bmi < 30 ? 'Overweight' : 'Obese'; return { stats: [['BMI', bmi.toFixed(1)], ['Category', cat]] }; } });

  T({ id: 'age-calc', cat: 'math', name: 'Age Calculator', desc: 'Exact age in years, months and days.', icon: '🎂',
    ui: [{ k: 'dob', t: 'date', label: 'Date of birth' }],
    run: v => { if (!v.dob) return { error: 'Pick a date' }; const b = new Date(v.dob), n = new Date(); if (b > n) return { error: 'Date is in the future' }; let y = n.getFullYear() - b.getFullYear(), m = n.getMonth() - b.getMonth(), d = n.getDate() - b.getDate(); if (d < 0) { m--; d += new Date(n.getFullYear(), n.getMonth(), 0).getDate(); } if (m < 0) { y--; m += 12; } const days = Math.floor((n - b) / 864e5); return { stats: [['Years', y], ['Months', m], ['Days', d], ['Total days', days.toLocaleString()], ['Total weeks', Math.floor(days / 7).toLocaleString()]] }; } });

  T({ id: 'random-number', cat: 'math', name: 'Random Number Generator', desc: 'Random integers in a range.', icon: '🎲',
    ui: [{ k: 'min', t: 'number', label: 'Min', def: 1 }, { k: 'max', t: 'number', label: 'Max', def: 100 }, { k: 'count', t: 'number', label: 'How many', def: 1 }],
    run: v => { const lo = num(v.min, 1), hi = num(v.max, 100), c = Math.max(1, Math.min(1000, num(v.count, 1))); const out = []; for (let i = 0; i < c; i++) out.push(Math.floor(Math.random() * (hi - lo + 1)) + lo); return out.join(', '); } });

  T({ id: 'number-base', cat: 'math', name: 'Number Base Converter', desc: 'Convert between binary, octal, decimal, hex.', icon: '🔢',
    ui: [{ k: 'val', t: 'text', label: 'Value' }, { k: 'from', t: 'select', label: 'From base', opts: ['Decimal (10)', 'Binary (2)', 'Octal (8)', 'Hex (16)'] }],
    run: v => { const base = { 'Decimal (10)': 10, 'Binary (2)': 2, 'Octal (8)': 8, 'Hex (16)': 16 }[v.from]; const n = parseInt((v.val || '').trim(), base); if (isNaN(n)) return { error: 'Invalid number for that base' }; return { stats: [['Decimal', n.toString(10)], ['Binary', n.toString(2)], ['Octal', n.toString(8)], ['Hex', n.toString(16).toUpperCase()]] }; } });

  T({ id: 'gcd-lcm', cat: 'math', name: 'GCD & LCM', desc: 'Greatest common divisor and least common multiple.', icon: '➗',
    ui: [{ k: 'a', t: 'number', label: 'A' }, { k: 'b', t: 'number', label: 'B' }],
    run: v => { let a = Math.abs(num(v.a)), b = Math.abs(num(v.b)); if (!a || !b) return { error: 'Enter two non-zero numbers' }; const g = (x, y) => y ? g(y, x % y) : x; const gcd = g(a, b); return { stats: [['GCD', gcd], ['LCM', a * b / gcd]] }; } });

  T({ id: 'prime-check', cat: 'math', name: 'Prime Checker', desc: 'Is a number prime? Plus its factors.', icon: '🔎',
    ui: [{ k: 'n', t: 'number', label: 'Number' }],
    run: v => { const n = Math.floor(num(v.n)); if (n < 2) return 'Not prime (must be ≥ 2).'; let f = []; for (let i = 1; i <= n; i++) if (n % i === 0) f.push(i); const prime = f.length === 2; return `${n} is ${prime ? '✅ PRIME' : '❌ not prime'}.\nDivisors: ${f.join(', ')}`; } });

  T({ id: 'factorial', cat: 'math', name: 'Factorial Calculator', desc: 'Compute n! for reasonable n.', icon: '❗',
    ui: [{ k: 'n', t: 'number', label: 'n (0–170)' }],
    run: v => { const n = Math.floor(num(v.n)); if (n < 0 || n > 170) return { error: '0 ≤ n ≤ 170' }; let r = 1; for (let i = 2; i <= n; i++) r *= i; return `${n}! = ${r.toLocaleString('fullwide', { useGrouping: false })}`; } });

  T({ id: 'fibonacci', cat: 'math', name: 'Fibonacci Generator', desc: 'First N Fibonacci numbers.', icon: '🌀',
    ui: [{ k: 'n', t: 'number', label: 'How many', def: 15 }],
    run: v => { const n = Math.max(1, Math.min(90, num(v.n, 15))); const a = [0, 1]; for (let i = 2; i < n; i++) a.push(a[i - 1] + a[i - 2]); return a.slice(0, n).join(', '); } });

  T({ id: 'quadratic', cat: 'math', name: 'Quadratic Solver', desc: 'Solve ax² + bx + c = 0.', icon: '📐',
    ui: [{ k: 'a', t: 'number', label: 'a' }, { k: 'b', t: 'number', label: 'b' }, { k: 'c', t: 'number', label: 'c' }],
    run: v => { const a = num(v.a), b = num(v.b), c = num(v.c); if (!a) return { error: 'a cannot be 0' }; const d = b * b - 4 * a * c; if (d < 0) { const re = (-b / (2 * a)).toFixed(3), im = (Math.sqrt(-d) / (2 * a)).toFixed(3); return `Complex roots:\nx₁ = ${re} + ${im}i\nx₂ = ${re} − ${im}i`; } const x1 = (-b + Math.sqrt(d)) / (2 * a), x2 = (-b - Math.sqrt(d)) / (2 * a); return `x₁ = ${x1}\nx₂ = ${x2}`; } });

  T({ id: 'temp-convert', cat: 'math', name: 'Temperature Converter', desc: 'Celsius, Fahrenheit and Kelvin.', icon: '🌡️',
    ui: [{ k: 'val', t: 'number', label: 'Value' }, { k: 'from', t: 'select', label: 'From', opts: ['Celsius', 'Fahrenheit', 'Kelvin'] }],
    run: v => { const x = num(v.val); let c; if (v.from === 'Celsius') c = x; else if (v.from === 'Fahrenheit') c = (x - 32) * 5 / 9; else c = x - 273.15; return { stats: [['Celsius', c.toFixed(2) + ' °C'], ['Fahrenheit', (c * 9 / 5 + 32).toFixed(2) + ' °F'], ['Kelvin', (c + 273.15).toFixed(2) + ' K']] }; } });

  T({ id: 'roman-numeral', cat: 'math', name: 'Roman Numerals', desc: 'Convert numbers to Roman and back.', icon: 'Ⅹ',
    ui: [{ k: 'val', t: 'text', label: 'Number or Roman numeral' }],
    run: v => { const M = [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']]; const s = (v.val || '').trim().toUpperCase(); if (/^\d+$/.test(s)) { let n = +s; if (n < 1 || n > 3999) return { error: '1–3999 only' }; let r = ''; for (const [x, l] of M) while (n >= x) { r += l; n -= x; } return r; } let n = 0, i = 0; for (const [x, l] of M) while (s.slice(i, i + l.length) === l) { n += x; i += l.length; } return i === s.length && n ? String(n) : { error: 'Invalid Roman numeral' }; } });

  T({ id: 'scientific-notation', cat: 'math', name: 'Scientific Notation', desc: 'Convert to and from scientific notation.', icon: '🔬',
    ui: [{ k: 'val', t: 'text', label: 'Number' }],
    run: v => { const n = parseFloat(v.val); if (isNaN(n)) return { error: 'Enter a number' }; return { stats: [['Scientific', n.toExponential(4)], ['Standard', n.toLocaleString('fullwide', { useGrouping: false })]] }; } });

  T({ id: 'ratio-simplify', cat: 'math', name: 'Ratio Simplifier', desc: 'Reduce a ratio to lowest terms.', icon: '⚗️',
    ui: [{ k: 'a', t: 'number', label: 'A' }, { k: 'b', t: 'number', label: 'B' }],
    run: v => { let a = Math.round(num(v.a)), b = Math.round(num(v.b)); if (!a || !b) return { error: 'Enter two numbers' }; const g = (x, y) => y ? g(y, x % y) : x; const d = g(Math.abs(a), Math.abs(b)); return `${a} : ${b}  =  ${a / d} : ${b / d}`; } });

  T({ id: 'compound-interest', cat: 'math', name: 'Compound Interest', desc: 'Grow savings with compound interest.', icon: '💰',
    ui: [{ k: 'p', t: 'number', label: 'Principal' }, { k: 'r', t: 'number', label: 'Annual rate %' }, { k: 'y', t: 'number', label: 'Years', def: 5 }, { k: 'n', t: 'number', label: 'Compounds/year', def: 12 }],
    run: v => { const p = num(v.p), r = num(v.r) / 100, y = num(v.y, 5), n = Math.max(1, num(v.n, 12)); const A = p * Math.pow(1 + r / n, n * y); return { stats: [['Final amount', A.toFixed(2)], ['Interest earned', (A - p).toFixed(2)]] }; } });

  /* ============================================================
     CATEGORY 3 — COLOR & DESIGN  (15 tools)
     ============================================================ */
  const hexToRgb = h => { h = h.replace('#', ''); if (h.length === 3) h = [...h].map(c => c + c).join(''); const n = parseInt(h, 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; };
  const rgbToHex = (r, g, b) => '#' + [r, g, b].map(x => Math.max(0, Math.min(255, Math.round(x))).toString(16).padStart(2, '0')).join('');
  const rgbToHsl = (r, g, b) => { r /= 255; g /= 255; b /= 255; const mx = Math.max(r, g, b), mn = Math.min(r, g, b); let h, s, l = (mx + mn) / 2; if (mx === mn) h = s = 0; else { const d = mx - mn; s = l > .5 ? d / (2 - mx - mn) : d / (mx + mn); h = mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; h /= 6; } return [Math.round(h * 360), Math.round(s * 100), Math.round(l * 100)]; };

  T({ id: 'hex-rgb', cat: 'color', name: 'HEX ⇄ RGB ⇄ HSL', desc: 'Convert a color between all formats.', icon: '🎨',
    ui: [{ k: 'color', t: 'color', label: 'Pick a color', def: '#6366f1' }],
    run: v => { const [r, g, b] = hexToRgb(v.color); const [h, s, l] = rgbToHsl(r, g, b); return { swatch: v.color, stats: [['HEX', v.color.toUpperCase()], ['RGB', `rgb(${r}, ${g}, ${b})`], ['HSL', `hsl(${h}, ${s}%, ${l}%)`]] }; } });

  T({ id: 'color-shades', cat: 'color', name: 'Shade Generator', desc: 'Generate lighter and darker shades.', icon: '🌗',
    ui: [{ k: 'color', t: 'color', label: 'Base color', def: '#22d3ee' }],
    run: v => { const [r, g, b] = hexToRgb(v.color); const out = []; for (let i = -4; i <= 4; i++) { const f = 1 + i * 0.18; out.push(rgbToHex(r * f, g * f, b * f)); } return { swatches: out }; } });

  T({ id: 'color-contrast', cat: 'color', name: 'Contrast Checker', desc: 'WCAG contrast ratio between two colors.', icon: '👁️',
    ui: [{ k: 'fg', t: 'color', label: 'Text color', def: '#0f172a' }, { k: 'bg', t: 'color', label: 'Background', def: '#ffffff' }],
    run: v => { const lum = h => { const [r, g, b] = hexToRgb(h).map(c => { c /= 255; return c <= .03928 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4; }); return .2126 * r + .7152 * g + .0722 * b; }; const l1 = lum(v.fg), l2 = lum(v.bg); const ratio = (Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05); return { stats: [['Ratio', ratio.toFixed(2) + ':1'], ['Normal text (AA)', ratio >= 4.5 ? '✅ Pass' : '❌ Fail'], ['Large text (AA)', ratio >= 3 ? '✅ Pass' : '❌ Fail'], ['AAA', ratio >= 7 ? '✅ Pass' : '❌ Fail']] }; } });

  T({ id: 'complementary-color', cat: 'color', name: 'Color Harmonies', desc: 'Complementary, triadic and analogous colors.', icon: '🌈',
    ui: [{ k: 'color', t: 'color', label: 'Base color', def: '#f97316' }],
    run: v => { const [r, g, b] = hexToRgb(v.color); let [h, s, l] = rgbToHsl(r, g, b); const hsl = (H) => { H = (H % 360 + 360) % 360; s /= 100; l /= 100; const a = s * Math.min(l, 1 - l); const f = n => { const k = (n + H / 30) % 12; return l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1)); }; s *= 100; l *= 100; return rgbToHex(f(0) * 255, f(8) * 255, f(4) * 255); }; return { swatches: [hsl(h), hsl(h + 180), hsl(h + 120), hsl(h + 240), hsl(h + 30), hsl(h - 30)] }; } });

  T({ id: 'gradient-generator', cat: 'color', name: 'CSS Gradient Maker', desc: 'Build a linear-gradient and copy the CSS.', icon: '🖌️',
    ui: [{ k: 'c1', t: 'color', label: 'Color 1', def: '#6366f1' }, { k: 'c2', t: 'color', label: 'Color 2', def: '#22d3ee' }, { k: 'deg', t: 'number', label: 'Angle°', def: 135 }],
    run: v => { const css = `linear-gradient(${num(v.deg, 135)}deg, ${v.c1}, ${v.c2})`; return { gradient: css, code: 'background: ' + css + ';' }; } });

  T({ id: 'random-color', cat: 'color', name: 'Random Color', desc: 'Generate a random color palette.', icon: '🎰',
    ui: [{ k: 'n', t: 'number', label: 'How many', def: 5 }],
    run: v => { const out = []; for (let i = 0; i < Math.max(1, Math.min(20, num(v.n, 5))); i++) out.push('#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0')); return { swatches: out }; } });

  T({ id: 'rgba-hex', cat: 'color', name: 'HEX + Alpha → RGBA', desc: 'Add transparency to a hex color.', icon: '🫧',
    ui: [{ k: 'color', t: 'color', label: 'Color', def: '#6366f1' }, { k: 'a', t: 'number', label: 'Alpha % ', def: 50 }],
    run: v => { const [r, g, b] = hexToRgb(v.color); const a = Math.max(0, Math.min(100, num(v.a, 50))) / 100; return `rgba(${r}, ${g}, ${b}, ${a})`; } });

  T({ id: 'color-blindness', cat: 'color', name: 'Colorblind Simulator', desc: 'Approximate how a color looks to colorblind users.', icon: '🕶️',
    ui: [{ k: 'color', t: 'color', label: 'Color', def: '#10b981' }],
    run: v => { const [r, g, b] = hexToRgb(v.color); const prot = rgbToHex(.567 * r + .433 * g, .558 * r + .442 * g, .242 * g + .758 * b); const deut = rgbToHex(.625 * r + .375 * g, .7 * r + .3 * g, .3 * g + .7 * b); const trit = rgbToHex(.95 * r + .05 * g, .433 * g + .567 * b, .475 * g + .525 * b); return { swatches: [v.color, prot, deut, trit], labels: ['Normal', 'Protanopia', 'Deuteranopia', 'Tritanopia'] }; } });

  T({ id: 'tailwind-shade', cat: 'color', name: 'Tailwind Scale', desc: 'Generate a 50–900 scale from one color.', icon: '📶',
    ui: [{ k: 'color', t: 'color', label: 'Base (500)', def: '#8b5cf6' }],
    run: v => { const [r, g, b] = hexToRgb(v.color); const steps = [.85, .7, .55, .35, .15, 0, -.15, -.3, -.45, -.6]; const out = steps.map(s => s >= 0 ? rgbToHex(r + (255 - r) * s, g + (255 - g) * s, b + (255 - b) * s) : rgbToHex(r * (1 + s), g * (1 + s), b * (1 + s))); return { swatches: out, labels: ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900'] }; } });

  T({ id: 'name-that-color', cat: 'color', name: 'Nearest Color Name', desc: 'Find the closest named CSS color.', icon: '🏷️',
    ui: [{ k: 'color', t: 'color', label: 'Color', def: '#ec4899' }],
    run: v => { const names = { black: '#000000', white: '#ffffff', red: '#ff0000', green: '#008000', blue: '#0000ff', yellow: '#ffff00', cyan: '#00ffff', magenta: '#ff00ff', gray: '#808080', orange: '#ffa500', pink: '#ffc0cb', purple: '#800080', brown: '#a52a2a', teal: '#008080', navy: '#000080', lime: '#00ff00', indigo: '#4b0082', violet: '#ee82ee', gold: '#ffd700', salmon: '#fa8072' }; const [r, g, b] = hexToRgb(v.color); let best, bd = 1e9; for (const [n, h] of Object.entries(names)) { const [R, G, B] = hexToRgb(h); const d = (r - R) ** 2 + (g - G) ** 2 + (b - B) ** 2; if (d < bd) { bd = d; best = n; } } return { swatch: v.color, stats: [['Closest name', best], ['Exact', bd === 0 ? 'yes' : 'approx']] }; } });

  T({ id: 'hsl-to-hex', cat: 'color', name: 'HSL → HEX', desc: 'Convert HSL values to a hex color.', icon: '🎛️',
    ui: [{ k: 'h', t: 'number', label: 'Hue (0–360)', def: 210 }, { k: 's', t: 'number', label: 'Sat %', def: 80 }, { k: 'l', t: 'number', label: 'Light %', def: 50 }],
    run: v => { let h = num(v.h) % 360, s = num(v.s, 80) / 100, l = num(v.l, 50) / 100; const a = s * Math.min(l, 1 - l); const f = n => { const k = (n + h / 30) % 12; return l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1)); }; const hex = rgbToHex(f(0) * 255, f(8) * 255, f(4) * 255); return { swatch: hex, stats: [['HEX', hex.toUpperCase()]] }; } });

  T({ id: 'brightness-adjust', cat: 'color', name: 'Brightness Adjuster', desc: 'Lighten or darken a color by a percentage.', icon: '🔆',
    ui: [{ k: 'color', t: 'color', label: 'Color', def: '#0ea5e9' }, { k: 'amt', t: 'number', label: 'Amount % (− dark, + light)', def: 20 }],
    run: v => { const [r, g, b] = hexToRgb(v.color); const f = num(v.amt) / 100; const adj = c => f >= 0 ? c + (255 - c) * f : c * (1 + f); const hex = rgbToHex(adj(r), adj(g), adj(b)); return { swatch: hex, stats: [['Result', hex.toUpperCase()]] }; } });

  T({ id: 'palette-from-image-hint', cat: 'color', name: 'Brand Palette Builder', desc: 'Build a 5-color palette around a hue.', icon: '🎨',
    ui: [{ k: 'color', t: 'color', label: 'Anchor color', def: '#f43f5e' }],
    run: v => { const [r, g, b] = hexToRgb(v.color); let [h] = rgbToHsl(r, g, b); const mk = (H, S, L) => { S /= 100; L /= 100; const a = S * Math.min(L, 1 - L); const f = n => { const k = (n + H / 30) % 12; return L - a * Math.max(-1, Math.min(k - 3, 9 - k, 1)); }; return rgbToHex(f(0) * 255, f(8) * 255, f(4) * 255); }; return { swatches: [mk(h, 70, 25), mk(h, 75, 42), v.color, mk(h + 20, 70, 65), mk(h + 40, 60, 85)] }; } });

  T({ id: 'css-shadow', cat: 'color', name: 'Box-Shadow Generator', desc: 'Design a CSS box-shadow and copy it.', icon: '🌑',
    ui: [{ k: 'x', t: 'number', label: 'X offset', def: 0 }, { k: 'y', t: 'number', label: 'Y offset', def: 10 }, { k: 'blur', t: 'number', label: 'Blur', def: 30 }, { k: 'color', t: 'color', label: 'Color', def: '#6366f1' }, { k: 'a', t: 'number', label: 'Opacity %', def: 25 }],
    run: v => { const [r, g, b] = hexToRgb(v.color); const css = `${num(v.x)}px ${num(v.y, 10)}px ${num(v.blur, 30)}px rgba(${r},${g},${b},${num(v.a, 25) / 100})`; return { shadow: css, code: 'box-shadow: ' + css + ';' }; } });

  /* ============================================================
     CATEGORY 4 — DEVELOPER  (25 tools)
     ============================================================ */
  T({ id: 'json-formatter', cat: 'dev', name: 'JSON Formatter', desc: 'Pretty-print and validate JSON.', icon: '{ }',
    ui: [{ k: 'json', t: 'textarea', label: 'JSON' }, { k: 'indent', t: 'select', label: 'Indent', opts: ['2 spaces', '4 spaces', 'Tab', 'Minify'] }],
    run: v => { try { const o = JSON.parse(v.json); const ind = { '2 spaces': 2, '4 spaces': 4, 'Tab': '\t', 'Minify': 0 }[v.indent]; return JSON.stringify(o, null, ind); } catch (e) { return { error: 'Invalid JSON: ' + e.message }; } } });

  T({ id: 'base64', cat: 'dev', name: 'Base64 Encode/Decode', desc: 'Encode or decode Base64 (UTF-8 safe).', icon: '🔡',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'mode', t: 'select', label: 'Direction', opts: ['Encode', 'Decode'] }],
    run: v => { try { return v.mode === 'Encode' ? btoa(unescape(encodeURIComponent(v.text || ''))) : decodeURIComponent(escape(atob((v.text || '').trim()))); } catch (e) { return { error: 'Invalid input' }; } } });

  T({ id: 'url-encode', cat: 'dev', name: 'URL Encode/Decode', desc: 'Percent-encode or decode a URL component.', icon: '🔗',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'mode', t: 'select', label: 'Direction', opts: ['Encode', 'Decode'] }],
    run: v => { try { return v.mode === 'Encode' ? encodeURIComponent(v.text || '') : decodeURIComponent(v.text || ''); } catch (e) { return { error: 'Invalid input' }; } } });

  T({ id: 'html-encode', cat: 'dev', name: 'HTML Entity Encoder', desc: 'Escape or unescape HTML entities.', icon: '⟨⟩',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'mode', t: 'select', label: 'Direction', opts: ['Encode', 'Decode'] }],
    run: v => { if (v.mode === 'Encode') return esc(v.text || ''); const d = el('textarea'); d.innerHTML = v.text || ''; return d.value; } });

  T({ id: 'jwt-decode', cat: 'dev', name: 'JWT Decoder', desc: 'Decode a JWT header and payload (no verify).', icon: '🔓',
    ui: [{ k: 'jwt', t: 'textarea', label: 'JWT token' }],
    run: v => { try { const [h, p] = (v.jwt || '').trim().split('.'); const dec = s => JSON.stringify(JSON.parse(decodeURIComponent(escape(atob(s.replace(/-/g, '+').replace(/_/g, '/'))))), null, 2); return 'HEADER:\n' + dec(h) + '\n\nPAYLOAD:\n' + dec(p); } catch (e) { return { error: 'Invalid JWT' }; } } });

  T({ id: 'uuid-gen', cat: 'dev', name: 'UUID Generator', desc: 'Generate v4 UUIDs.', icon: '🆔',
    ui: [{ k: 'n', t: 'number', label: 'How many', def: 5 }],
    run: v => { const out = []; for (let i = 0; i < Math.max(1, Math.min(100, num(v.n, 5))); i++) out.push((crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }))); return out.join('\n'); } });

  T({ id: 'hash-gen', cat: 'dev', name: 'SHA Hash Generator', desc: 'SHA-1, SHA-256, SHA-384, SHA-512.', icon: '#️⃣',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'algo', t: 'select', label: 'Algorithm', opts: ['SHA-256', 'SHA-1', 'SHA-384', 'SHA-512'] }],
    async: true,
    run: async v => { try { const buf = await crypto.subtle.digest(v.algo, new TextEncoder().encode(v.text || '')); return [...new Uint8Array(buf)].map(b => b.toString(16).padStart(2, '0')).join(''); } catch (e) { return { error: 'Hashing failed' }; } } });

  T({ id: 'color-hex-validate', cat: 'dev', name: 'Timestamp Converter', desc: 'Unix timestamp ⇄ human date.', icon: '⏱️',
    ui: [{ k: 'val', t: 'text', label: 'Unix seconds or date' }],
    run: v => { const s = (v.val || '').trim(); if (/^\d{10,13}$/.test(s)) { const ms = s.length === 13 ? +s : +s * 1000; const d = new Date(ms); return { stats: [['ISO', d.toISOString()], ['Local', d.toString()], ['UTC', d.toUTCString()]] }; } const d = new Date(s); if (isNaN(d)) return { error: 'Enter a unix timestamp or a date' }; return { stats: [['Unix (s)', Math.floor(d.getTime() / 1000)], ['Unix (ms)', d.getTime()], ['ISO', d.toISOString()]] }; } });

  T({ id: 'cron-explainer', cat: 'dev', name: 'Cron Describer', desc: 'Explain a 5-field cron expression.', icon: '⏰',
    ui: [{ k: 'cron', t: 'text', label: 'Cron (e.g. 0 9 * * 1-5)' }],
    run: v => { const p = (v.cron || '').trim().split(/\s+/); if (p.length !== 5) return { error: 'Need 5 fields: min hour dom month dow' }; const [mi, h, dom, mo, dow] = p; const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']; const f = (x, n) => x === '*' ? 'every ' + n : x.includes('/') ? 'every ' + x.split('/')[1] + ' ' + n : n + ' ' + x; return `Runs at ${f(mi, 'minute')}, ${f(h, 'hour')}, on ${dom === '*' ? 'every day' : 'day ' + dom} of ${mo === '*' ? 'every month' : 'month ' + mo}` + (dow !== '*' ? `, on ${dow.split(',').map(d => days[+d] || d).join('/')}` : '') + '.'; } });

  T({ id: 'regex-tester', cat: 'dev', name: 'Regex Tester', desc: 'Test a regex against text and see matches.', icon: '🔣',
    ui: [{ k: 'pat', t: 'text', label: 'Pattern' }, { k: 'flags', t: 'text', label: 'Flags', def: 'g' }, { k: 'text', t: 'textarea', label: 'Test string' }],
    run: v => { try { const re = new RegExp(v.pat, v.flags || ''); const m = (v.text || '').match(re); return m ? `Matches (${m.length}):\n` + m.join('\n') : 'No matches.'; } catch (e) { return { error: e.message }; } } });

  T({ id: 'css-minify', cat: 'dev', name: 'CSS Minifier', desc: 'Strip comments and whitespace from CSS.', icon: '📉',
    ui: [{ k: 'css', t: 'textarea', label: 'CSS' }],
    run: v => (v.css || '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s*([{}:;,])\s*/g, '$1').replace(/;}/g, '}').replace(/\s+/g, ' ').trim() });

  T({ id: 'js-minify', cat: 'dev', name: 'JS Comment Stripper', desc: 'Remove comments & extra whitespace from JS.', icon: '🧽',
    ui: [{ k: 'js', t: 'textarea', label: 'JavaScript' }],
    run: v => (v.js || '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\n\s*\n/g, '\n').trim() });

  T({ id: 'html-to-jsx', cat: 'dev', name: 'HTML → JSX', desc: 'Convert HTML attributes to JSX.', icon: '⚛️',
    ui: [{ k: 'html', t: 'textarea', label: 'HTML' }],
    run: v => (v.html || '').replace(/\bclass=/g, 'className=').replace(/\bfor=/g, 'htmlFor=').replace(/style="([^"]*)"/g, (_, s) => { const o = s.split(';').filter(Boolean).map(r => { const [k, val] = r.split(':'); return `${k.trim().replace(/-(\w)/g, (_, c) => c.toUpperCase())}: '${val.trim()}'`; }).join(', '); return `style={{ ${o} }}`; }) });

  T({ id: 'query-string-parse', cat: 'dev', name: 'Query String Parser', desc: 'Parse a URL query string into pairs.', icon: '❓',
    ui: [{ k: 'url', t: 'text', label: 'URL or query string' }],
    run: v => { try { const qs = (v.url || '').split('?')[1] || v.url || ''; const p = new URLSearchParams(qs); const o = [...p.entries()]; return o.length ? o.map(([k, val]) => `${k} = ${val}`).join('\n') : '(no parameters)'; } catch (e) { return { error: 'Invalid' }; } } });

  T({ id: 'json-to-csv', cat: 'dev', name: 'JSON → CSV', desc: 'Flatten a JSON array into CSV.', icon: '📑',
    ui: [{ k: 'json', t: 'textarea', label: 'JSON array of objects' }],
    run: v => { try { const a = JSON.parse(v.json); if (!Array.isArray(a)) return { error: 'Expected a JSON array' }; const keys = [...new Set(a.flatMap(o => Object.keys(o)))]; const esc = x => { x = x == null ? '' : String(x); return /[",\n]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x; }; return [keys.join(','), ...a.map(o => keys.map(k => esc(o[k])).join(','))].join('\n'); } catch (e) { return { error: e.message }; } } });

  T({ id: 'csv-to-json', cat: 'dev', name: 'CSV → JSON', desc: 'Turn CSV rows into JSON objects.', icon: '🗂️',
    ui: [{ k: 'csv', t: 'textarea', label: 'CSV (first row = headers)' }],
    run: v => { const rows = (v.csv || '').trim().split('\n').map(r => r.split(',').map(c => c.trim())); if (rows.length < 2) return { error: 'Need a header + rows' }; const h = rows[0]; const out = rows.slice(1).map(r => Object.fromEntries(h.map((k, i) => [k, r[i] ?? '']))); return JSON.stringify(out, null, 2); } });

  T({ id: 'color-to-css-var', cat: 'dev', name: 'HTTP Status Lookup', desc: 'Explain any HTTP status code.', icon: '🌐',
    ui: [{ k: 'code', t: 'number', label: 'Status code' }],
    run: v => { const C = { 200: 'OK', 201: 'Created', 204: 'No Content', 301: 'Moved Permanently', 302: 'Found', 304: 'Not Modified', 400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found', 405: 'Method Not Allowed', 409: 'Conflict', 418: "I'm a teapot", 422: 'Unprocessable Entity', 429: 'Too Many Requests', 500: 'Internal Server Error', 502: 'Bad Gateway', 503: 'Service Unavailable', 504: 'Gateway Timeout' }; const c = num(v.code); const cls = c < 200 ? 'Informational' : c < 300 ? 'Success' : c < 400 ? 'Redirect' : c < 500 ? 'Client Error' : 'Server Error'; return C[c] ? `${c} — ${C[c]}\nClass: ${cls}` : `${c} — Unknown (${cls})`; } });

  T({ id: 'lorem-json', cat: 'dev', name: 'Mock JSON Generator', desc: 'Generate fake user records as JSON.', icon: '🧪',
    ui: [{ k: 'n', t: 'number', label: 'Records', def: 3 }],
    run: v => { const first = ['Sam', 'Alex', 'Lina', 'Omar', 'Nour', 'Zaid', 'Maya', 'Kai']; const last = ['Ahmad', 'Khoury', 'Nasser', 'Haddad', 'Saleh', 'Aziz']; const out = []; for (let i = 0; i < Math.max(1, Math.min(50, num(v.n, 3))); i++) { const f = first[Math.random() * first.length | 0], l = last[Math.random() * last.length | 0]; out.push({ id: i + 1, name: f + ' ' + l, email: (f + '.' + l).toLowerCase() + '@example.com', age: 18 + (Math.random() * 40 | 0), active: Math.random() > .5 }); } return JSON.stringify(out, null, 2); } });

  T({ id: 'slugify-dev', cat: 'dev', name: 'Variable Name Formatter', desc: 'Format a phrase as camel/snake/pascal/const.', icon: '🐍',
    ui: [{ k: 'text', t: 'text', label: 'Phrase' }, { k: 'style', t: 'select', label: 'Style', opts: ['camelCase', 'PascalCase', 'snake_case', 'CONSTANT_CASE', 'kebab-case'] }],
    run: v => { const w = (v.text || '').toLowerCase().match(/[a-z0-9]+/g) || []; const s = v.style; if (s === 'camelCase') return w.map((x, i) => i ? x[0].toUpperCase() + x.slice(1) : x).join(''); if (s === 'PascalCase') return w.map(x => x[0].toUpperCase() + x.slice(1)).join(''); if (s === 'snake_case') return w.join('_'); if (s === 'CONSTANT_CASE') return w.join('_').toUpperCase(); return w.join('-'); } });

  T({ id: 'markdown-preview', cat: 'dev', name: 'Markdown → HTML', desc: 'Convert basic Markdown to HTML.', icon: '📘',
    ui: [{ k: 'md', t: 'textarea', label: 'Markdown' }],
    run: v => { let h = esc(v.md || ''); h = h.replace(/^### (.*)$/gm, '<h3>$1</h3>').replace(/^## (.*)$/gm, '<h2>$1</h2>').replace(/^# (.*)$/gm, '<h1>$1</h1>').replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\*(.+?)\*/g, '<em>$1</em>').replace(/`(.+?)`/g, '<code>$1</code>').replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2">$1</a>').replace(/^\- (.*)$/gm, '<li>$1</li>').replace(/\n/g, '<br>'); return h; } });

  T({ id: 'ascii-table', cat: 'dev', name: 'ASCII Lookup', desc: 'Character ⇄ ASCII/Unicode code point.', icon: '🔤',
    ui: [{ k: 'val', t: 'text', label: 'Character or number' }],
    run: v => { const s = (v.val || '').trim(); if (/^\d+$/.test(s)) return `Code ${s} = "${String.fromCodePoint(+s)}"`; if (s) return `"${s[0]}" = ${s.codePointAt(0)} (0x${s.codePointAt(0).toString(16)})`; return { error: 'Enter a character or number' }; } });

  T({ id: 'string-escape', cat: 'dev', name: 'String Escaper', desc: 'Escape a string for JSON / JS.', icon: '⌨️',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => JSON.stringify(v.text || '') });

  T({ id: 'indent-converter', cat: 'dev', name: 'Tabs ⇄ Spaces', desc: 'Convert indentation in code.', icon: '↹',
    ui: [{ k: 'code', t: 'textarea', label: 'Code' }, { k: 'mode', t: 'select', label: 'Convert', opts: ['Tabs → Spaces', 'Spaces → Tabs'] }, { k: 'size', t: 'number', label: 'Spaces per tab', def: 2 }],
    run: v => { const n = Math.max(1, num(v.size, 2)); if (v.mode === 'Tabs → Spaces') return (v.code || '').replace(/\t/g, ' '.repeat(n)); return (v.code || '').replace(new RegExp('^( {' + n + '})+', 'gm'), m => '\t'.repeat(m.length / n)); } });

  T({ id: 'user-agent-parse', cat: 'dev', name: 'Your User-Agent', desc: 'Show and parse your browser user-agent.', icon: '🖥️',
    ui: [],
    run: () => { const ua = navigator.userAgent; const b = /Edg/.test(ua) ? 'Edge' : /Chrome/.test(ua) ? 'Chrome' : /Firefox/.test(ua) ? 'Firefox' : /Safari/.test(ua) ? 'Safari' : 'Unknown'; const os = /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'macOS' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Linux/.test(ua) ? 'Linux' : 'Unknown'; return { stats: [['Browser', b], ['OS', os], ['Language', navigator.language], ['Cores', navigator.hardwareConcurrency || '?'], ['Screen', screen.width + '×' + screen.height]], raw: ua }; } });

  /* ============================================================
     CATEGORY 5 — SEO & WEB  (20 tools)
     ============================================================ */
  T({ id: 'meta-tag-gen', cat: 'seo', name: 'Meta Tag Generator', desc: 'Generate SEO + Open Graph meta tags.', icon: '🏷️',
    ui: [{ k: 'title', t: 'text', label: 'Page title' }, { k: 'desc', t: 'textarea', label: 'Description' }, { k: 'url', t: 'text', label: 'URL' }, { k: 'img', t: 'text', label: 'Image URL' }],
    run: v => { const e = esc; return `<title>${e(v.title)}</title>\n<meta name="description" content="${e(v.desc)}">\n<link rel="canonical" href="${e(v.url)}">\n<meta property="og:title" content="${e(v.title)}">\n<meta property="og:description" content="${e(v.desc)}">\n<meta property="og:url" content="${e(v.url)}">\n<meta property="og:image" content="${e(v.img)}">\n<meta name="twitter:card" content="summary_large_image">`; } });

  T({ id: 'serp-preview', cat: 'seo', name: 'SERP Snippet Preview', desc: 'Preview how your page looks in Google.', icon: '🔍',
    ui: [{ k: 'title', t: 'text', label: 'Title' }, { k: 'url', t: 'text', label: 'URL' }, { k: 'desc', t: 'textarea', label: 'Meta description' }],
    run: v => { const tl = (v.title || '').length, dl = (v.desc || '').length; return { serp: { title: v.title, url: v.url, desc: v.desc }, stats: [['Title length', tl + (tl > 60 ? ' ⚠️ long' : ' ✅')], ['Desc length', dl + (dl > 160 ? ' ⚠️ long' : dl < 70 ? ' ⚠️ short' : ' ✅')]] }; } });

  T({ id: 'keyword-density', cat: 'seo', name: 'Keyword Density', desc: 'Top keywords and their density.', icon: '📊',
    ui: [{ k: 'text', t: 'textarea', label: 'Content' }],
    run: v => { const w = (v.text || '').toLowerCase().match(/[a-z\u0600-\u06FF]{3,}/g) || []; const stop = new Set(['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'her', 'was', 'one', 'our', 'out', 'has', 'with', 'this', 'that', 'from', 'they', 'have', 'your']); const f = {}; w.forEach(x => { if (!stop.has(x)) f[x] = (f[x] || 0) + 1; }); const top = Object.entries(f).sort((a, b) => b[1] - a[1]).slice(0, 12); return top.length ? top.map(([k, n]) => `${k} — ${n} (${(n / w.length * 100).toFixed(1)}%)`).join('\n') : '(no keywords)'; } });

  T({ id: 'robots-gen', cat: 'seo', name: 'robots.txt Generator', desc: 'Build a robots.txt file.', icon: '🤖',
    ui: [{ k: 'allow', t: 'checkbox', label: 'Allow all crawlers', def: true }, { k: 'sitemap', t: 'text', label: 'Sitemap URL' }, { k: 'disallow', t: 'textarea', label: 'Disallow paths (one per line)' }],
    run: v => { let out = 'User-agent: *\n'; if (v.allow) out += 'Allow: /\n'; (v.disallow || '').split('\n').filter(x => x.trim()).forEach(p => out += 'Disallow: ' + p.trim() + '\n'); if (v.sitemap) out += '\nSitemap: ' + v.sitemap; return out; } });

  T({ id: 'schema-gen', cat: 'seo', name: 'JSON-LD Schema', desc: 'Generate Article/Organization schema.', icon: '📋',
    ui: [{ k: 'type', t: 'select', label: 'Type', opts: ['Article', 'Organization', 'Product', 'FAQPage'] }, { k: 'name', t: 'text', label: 'Name / Title' }, { k: 'url', t: 'text', label: 'URL' }, { k: 'desc', t: 'textarea', label: 'Description' }],
    run: v => { const base = { '@context': 'https://schema.org', '@type': v.type }; if (v.type === 'Article') Object.assign(base, { headline: v.name, description: v.desc, url: v.url }); else if (v.type === 'Product') Object.assign(base, { name: v.name, description: v.desc, url: v.url }); else Object.assign(base, { name: v.name, url: v.url, description: v.desc }); return '<script type="application/ld+json">\n' + JSON.stringify(base, null, 2) + '\n</script>'; } });

  T({ id: 'utm-builder', cat: 'seo', name: 'UTM Link Builder', desc: 'Build campaign tracking URLs.', icon: '🎯',
    ui: [{ k: 'url', t: 'text', label: 'Base URL' }, { k: 'source', t: 'text', label: 'Source' }, { k: 'medium', t: 'text', label: 'Medium' }, { k: 'campaign', t: 'text', label: 'Campaign' }],
    run: v => { if (!v.url) return { error: 'Enter a URL' }; const p = new URLSearchParams(); if (v.source) p.set('utm_source', v.source); if (v.medium) p.set('utm_medium', v.medium); if (v.campaign) p.set('utm_campaign', v.campaign); return v.url + (v.url.includes('?') ? '&' : '?') + p.toString(); } });

  T({ id: 'title-length', cat: 'seo', name: 'Title/Desc Length Checker', desc: 'Check pixel-safe title & meta length.', icon: '📏',
    ui: [{ k: 'title', t: 'text', label: 'Title' }, { k: 'desc', t: 'textarea', label: 'Description' }],
    run: v => { const t = (v.title || '').length, d = (v.desc || '').length; return { stats: [['Title chars', `${t} / 60`], ['Title status', t <= 60 ? '✅ Good' : '⚠️ May truncate'], ['Desc chars', `${d} / 160`], ['Desc status', d >= 70 && d <= 160 ? '✅ Good' : d < 70 ? '⚠️ Too short' : '⚠️ Too long']] }; } });

  T({ id: 'og-preview', cat: 'seo', name: 'Social Card Preview', desc: 'Preview an Open Graph share card.', icon: '📱',
    ui: [{ k: 'title', t: 'text', label: 'Title' }, { k: 'desc', t: 'text', label: 'Description' }, { k: 'url', t: 'text', label: 'Domain' }, { k: 'img', t: 'text', label: 'Image URL' }],
    run: v => ({ ogcard: { title: v.title, desc: v.desc, url: v.url, img: v.img } }) });

  T({ id: 'hreflang-gen', cat: 'seo', name: 'Hreflang Generator', desc: 'Generate hreflang tags for languages.', icon: '🌍',
    ui: [{ k: 'url', t: 'text', label: 'Page URL' }, { k: 'langs', t: 'text', label: 'Lang codes (comma-sep)', def: 'en,ar,fr' }],
    run: v => (v.langs || '').split(',').map(l => l.trim()).filter(Boolean).map(l => `<link rel="alternate" hreflang="${l}" href="${esc(v.url)}?lang=${l}">`).join('\n') + `\n<link rel="alternate" hreflang="x-default" href="${esc(v.url)}">` });

  T({ id: 'sitemap-url-gen', cat: 'seo', name: 'Sitemap XML Builder', desc: 'Build a sitemap from a list of URLs.', icon: '🗺️',
    ui: [{ k: 'urls', t: 'textarea', label: 'URLs (one per line)' }],
    run: v => { const urls = (v.urls || '').split('\n').filter(x => x.trim()); const body = urls.map(u => `  <url>\n    <loc>${esc(u.trim())}</loc>\n    <changefreq>weekly</changefreq>\n  </url>`).join('\n'); return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${body}\n</urlset>`; } });

  T({ id: 'reading-level', cat: 'seo', name: 'Readability Score', desc: 'Flesch reading-ease of your text.', icon: '📖',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => { const t = v.text || ''; const words = (t.match(/\S+/g) || []).length; const sentences = (t.match(/[.!?]+/g) || []).length || 1; const syll = (t.toLowerCase().match(/[aeiouy]+/g) || []).length || words; if (!words) return { error: 'Enter text' }; const score = 206.835 - 1.015 * (words / sentences) - 84.6 * (syll / words); const lvl = score >= 90 ? 'Very easy' : score >= 70 ? 'Easy' : score >= 50 ? 'Fairly hard' : score >= 30 ? 'Difficult' : 'Very confusing'; return { stats: [['Flesch score', score.toFixed(1)], ['Reading level', lvl], ['Words/sentence', (words / sentences).toFixed(1)]] }; } });

  T({ id: 'canonical-gen', cat: 'seo', name: 'Canonical Tag', desc: 'Generate a canonical link tag.', icon: '🔗',
    ui: [{ k: 'url', t: 'text', label: 'Preferred URL' }],
    run: v => `<link rel="canonical" href="${esc((v.url || '').trim())}">` });

  T({ id: 'twitter-card', cat: 'seo', name: 'Twitter Card Tags', desc: 'Generate Twitter card meta tags.', icon: '🐦',
    ui: [{ k: 'title', t: 'text', label: 'Title' }, { k: 'desc', t: 'text', label: 'Description' }, { k: 'img', t: 'text', label: 'Image' }, { k: 'handle', t: 'text', label: '@handle' }],
    run: v => `<meta name="twitter:card" content="summary_large_image">\n<meta name="twitter:title" content="${esc(v.title)}">\n<meta name="twitter:description" content="${esc(v.desc)}">\n<meta name="twitter:image" content="${esc(v.img)}">\n<meta name="twitter:site" content="${esc(v.handle)}">` });

  T({ id: 'word-frequency-seo', cat: 'seo', name: 'Content Length Analyzer', desc: 'Is your content long enough to rank?', icon: '📐',
    ui: [{ k: 'text', t: 'textarea', label: 'Article content' }],
    run: v => { const w = ((v.text || '').match(/\S+/g) || []).length; const verdict = w < 300 ? '⚠️ Thin content — aim for 600+' : w < 600 ? 'Okay — 600+ is safer' : w < 1500 ? '✅ Good depth' : '✅ In-depth (great for SEO)'; return { stats: [['Words', w], ['Est. reading time', Math.max(1, Math.round(w / 200)) + ' min'], ['SEO verdict', verdict]] }; } });

  T({ id: 'anchor-text-gen', cat: 'seo', name: 'Anchor Tag Builder', desc: 'Build an SEO-friendly <a> tag.', icon: '⚓',
    ui: [{ k: 'url', t: 'text', label: 'URL' }, { k: 'text', t: 'text', label: 'Anchor text' }, { k: 'nofollow', t: 'checkbox', label: 'Add rel="nofollow"' }, { k: 'blank', t: 'checkbox', label: 'Open in new tab' }],
    run: v => { let rel = []; if (v.nofollow) rel.push('nofollow'); if (v.blank) rel.push('noopener'); return `<a href="${esc(v.url)}"${v.blank ? ' target="_blank"' : ''}${rel.length ? ' rel="' + rel.join(' ') + '"' : ''}>${esc(v.text)}</a>`; } });

  T({ id: 'redirect-gen', cat: 'seo', name: '.htaccess Redirect', desc: 'Generate a 301 redirect rule.', icon: '↪️',
    ui: [{ k: 'from', t: 'text', label: 'Old path (/old)' }, { k: 'to', t: 'text', label: 'New URL' }],
    run: v => `Redirect 301 ${esc((v.from || '').trim())} ${esc((v.to || '').trim())}` });

  T({ id: 'faq-schema', cat: 'seo', name: 'FAQ Schema Builder', desc: 'Build FAQPage JSON-LD from Q&A.', icon: '❔',
    ui: [{ k: 'qa', t: 'textarea', label: 'Q and A lines: Q: … then A: …' }],
    run: v => { const lines = (v.qa || '').split('\n'); const items = []; let cur = null; lines.forEach(l => { const q = l.match(/^Q:\s*(.*)/i), a = l.match(/^A:\s*(.*)/i); if (q) { cur = { '@type': 'Question', name: q[1], acceptedAnswer: { '@type': 'Answer', text: '' } }; items.push(cur); } else if (a && cur) cur.acceptedAnswer.text = a[1]; }); if (!items.length) return { error: 'Use lines like "Q: …" and "A: …"' }; return '<script type="application/ld+json">\n' + JSON.stringify({ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: items }, null, 2) + '\n</script>'; } });

  T({ id: 'breadcrumb-schema', cat: 'seo', name: 'Breadcrumb Schema', desc: 'Generate BreadcrumbList JSON-LD.', icon: '🍞',
    ui: [{ k: 'items', t: 'textarea', label: 'Name|URL per line' }],
    run: v => { const rows = (v.items || '').split('\n').filter(x => x.includes('|')); if (!rows.length) return { error: 'Use "Name|URL" per line' }; const list = rows.map((r, i) => { const [name, url] = r.split('|'); return { '@type': 'ListItem', position: i + 1, name: name.trim(), item: url.trim() }; }); return '<script type="application/ld+json">\n' + JSON.stringify({ '@context': 'https://schema.org', '@type': 'BreadcrumbList', itemListElement: list }, null, 2) + '\n</script>'; } });

  T({ id: 'slug-seo', cat: 'seo', name: 'SEO URL Slug', desc: 'Clean, keyword-rich URL slug.', icon: '🌱',
    ui: [{ k: 'text', t: 'text', label: 'Title' }, { k: 'max', t: 'number', label: 'Max words', def: 6 }],
    run: v => { const stop = new Set(['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'is', 'are']); let w = (v.text || '').toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9\s]/g, '').split(/\s+/).filter(x => x && !stop.has(x)); return w.slice(0, Math.max(1, num(v.max, 6))).join('-'); } });

  T({ id: 'domain-age-hint', cat: 'seo', name: 'Alt Text Helper', desc: 'Generate accessible image alt text tips.', icon: '🖼️',
    ui: [{ k: 'subject', t: 'text', label: 'What is in the image?' }, { k: 'kw', t: 'text', label: 'Target keyword (optional)' }],
    run: v => { const s = (v.subject || '').trim(); if (!s) return { error: 'Describe the image' }; const alt = (v.kw ? v.kw.trim() + ' — ' : '') + s; return { stats: [['Suggested alt', alt.slice(0, 125)], ['Length', alt.length + ' / 125 chars'], ['Tip', 'Describe, don\'t keyword-stuff']] }; } });

  /* ============================================================
     CATEGORY 6 — SECURITY & PRIVACY  (15 tools)
     ============================================================ */
  T({ id: 'password-gen', cat: 'security', name: 'Password Generator', desc: 'Strong random passwords.', icon: '🔑',
    ui: [{ k: 'len', t: 'number', label: 'Length', def: 16 }, { k: 'upper', t: 'checkbox', label: 'A-Z', def: true }, { k: 'lower', t: 'checkbox', label: 'a-z', def: true }, { k: 'num', t: 'checkbox', label: '0-9', def: true }, { k: 'sym', t: 'checkbox', label: 'Symbols', def: true }],
    run: v => { let set = ''; if (v.upper) set += 'ABCDEFGHJKLMNPQRSTUVWXYZ'; if (v.lower) set += 'abcdefghijkmnpqrstuvwxyz'; if (v.num) set += '23456789'; if (v.sym) set += '!@#$%^&*-_=+?'; if (!set) return { error: 'Pick at least one set' }; const len = Math.max(4, Math.min(128, num(v.len, 16))); const a = crypto.getRandomValues(new Uint32Array(len)); let p = ''; for (let i = 0; i < len; i++) p += set[a[i] % set.length]; return p; } });

  T({ id: 'password-strength', cat: 'security', name: 'Password Strength', desc: 'Estimate password entropy and crack time.', icon: '🛡️',
    ui: [{ k: 'pw', t: 'text', label: 'Password' }],
    run: v => { const p = v.pw || ''; if (!p) return { error: 'Enter a password' }; let pool = 0; if (/[a-z]/.test(p)) pool += 26; if (/[A-Z]/.test(p)) pool += 26; if (/[0-9]/.test(p)) pool += 10; if (/[^a-zA-Z0-9]/.test(p)) pool += 32; const entropy = p.length * Math.log2(pool || 1); const guesses = Math.pow(2, entropy) / 2; const secs = guesses / 1e10; const human = secs < 1 ? 'instant' : secs < 60 ? secs.toFixed(0) + ' sec' : secs < 3600 ? (secs / 60).toFixed(0) + ' min' : secs < 86400 ? (secs / 3600).toFixed(0) + ' hr' : secs < 3.15e7 ? (secs / 86400).toFixed(0) + ' days' : (secs / 3.15e7).toExponential(1) + ' years'; const lvl = entropy < 40 ? '❌ Weak' : entropy < 60 ? '⚠️ Fair' : entropy < 80 ? '✅ Strong' : '💪 Very strong'; return { stats: [['Entropy', entropy.toFixed(0) + ' bits'], ['Strength', lvl], ['Crack time (est)', human]] }; } });

  T({ id: 'pin-gen', cat: 'security', name: 'PIN Generator', desc: 'Random numeric PIN codes.', icon: '🔢',
    ui: [{ k: 'len', t: 'number', label: 'Digits', def: 6 }, { k: 'n', t: 'number', label: 'How many', def: 1 }],
    run: v => { const len = Math.max(3, Math.min(12, num(v.len, 6))), c = Math.max(1, Math.min(50, num(v.n, 1))); const out = []; for (let i = 0; i < c; i++) { let p = ''; const a = crypto.getRandomValues(new Uint8Array(len)); for (let j = 0; j < len; j++) p += a[j] % 10; out.push(p); } return out.join('\n'); } });

  T({ id: 'passphrase-gen', cat: 'security', name: 'Passphrase Generator', desc: 'Memorable multi-word passphrases.', icon: '📿',
    ui: [{ k: 'words', t: 'number', label: 'Words', def: 4 }],
    run: v => { const list = 'apple river tiger cloud stone maple ocean amber quartz ember willow cedar falcon pixel copper velvet lunar solar cobalt orchid'.split(' '); const n = Math.max(3, Math.min(10, num(v.words, 4))); const a = crypto.getRandomValues(new Uint32Array(n)); const w = []; for (let i = 0; i < n; i++) w.push(list[a[i] % list.length]); return w.join('-') + '-' + (crypto.getRandomValues(new Uint8Array(1))[0] % 90 + 10); } });

  T({ id: 'md5-note', cat: 'security', name: 'Base64 Image Encoder', desc: 'Turn a small image into a data URI.', icon: '🖼️',
    ui: [{ k: 'file', t: 'file', label: 'Image file', accept: 'image/*' }],
    async: true,
    run: v => new Promise(res => { const f = v.file; if (!f) return res({ error: 'Choose an image' }); if (f.size > 500000) return res({ error: 'Keep under 500 KB' }); const r = new FileReader(); r.onload = () => res(r.result); r.onerror = () => res({ error: 'Read failed' }); r.readAsDataURL(f); }) });

  T({ id: 'random-hex-token', cat: 'security', name: 'Secure Token', desc: 'Cryptographically-random hex token.', icon: '🎟️',
    ui: [{ k: 'bytes', t: 'number', label: 'Bytes', def: 32 }],
    run: v => { const n = Math.max(4, Math.min(128, num(v.bytes, 32))); const a = crypto.getRandomValues(new Uint8Array(n)); return [...a].map(b => b.toString(16).padStart(2, '0')).join(''); } });

  T({ id: 'htpasswd-note', cat: 'security', name: 'Basic Auth Header', desc: 'Build an HTTP Basic Auth header.', icon: '🔐',
    ui: [{ k: 'user', t: 'text', label: 'Username' }, { k: 'pass', t: 'text', label: 'Password' }],
    run: v => 'Authorization: Basic ' + btoa((v.user || '') + ':' + (v.pass || '')) });

  T({ id: 'email-mask', cat: 'security', name: 'Email Masker', desc: 'Partially hide an email for display.', icon: '🕵️',
    ui: [{ k: 'email', t: 'text', label: 'Email' }],
    run: v => { const m = (v.email || '').match(/^(.)(.*)(.)@(.+)$/); if (!m) return { error: 'Enter a valid email' }; return m[1] + '*'.repeat(Math.max(1, m[2].length)) + m[3] + '@' + m[4]; } });

  T({ id: 'credit-card-mask', cat: 'security', name: 'Card Number Masker', desc: 'Mask all but the last 4 digits.', icon: '💳',
    ui: [{ k: 'num', t: 'text', label: 'Card number' }],
    run: v => { const d = (v.num || '').replace(/\D/g, ''); if (d.length < 4) return { error: 'Too short' }; return '•••• •••• •••• ' + d.slice(-4); } });

  T({ id: 'luhn-check', cat: 'security', name: 'Luhn / Card Validator', desc: 'Validate a card number checksum.', icon: '✔️',
    ui: [{ k: 'num', t: 'text', label: 'Card number' }],
    run: v => { const d = (v.num || '').replace(/\D/g, ''); if (d.length < 12) return { error: 'Enter a full number' }; let sum = 0, alt = false; for (let i = d.length - 1; i >= 0; i--) { let n = +d[i]; if (alt) { n *= 2; if (n > 9) n -= 9; } sum += n; alt = !alt; } return sum % 10 === 0 ? '✅ Valid checksum' : '❌ Invalid checksum'; } });

  T({ id: 'gdpr-cookie', cat: 'security', name: 'Cookie Banner Snippet', desc: 'Copy a simple consent banner.', icon: '🍪',
    ui: [{ k: 'msg', t: 'text', label: 'Message', def: 'We use cookies to improve your experience.' }],
    run: v => `<div id="cc" style="position:fixed;bottom:16px;left:16px;right:16px;background:#0f172a;color:#fff;padding:14px 18px;border-radius:12px;display:flex;gap:14px;align-items:center;justify-content:space-between;z-index:9999">\n  <span>${esc(v.msg)}</span>\n  <button onclick="localStorage.setItem('cc','1');cc.remove()" style="background:#6366f1;color:#fff;border:0;padding:8px 16px;border-radius:8px;cursor:pointer">Accept</button>\n</div>\n<script>if(localStorage.getItem('cc'))document.getElementById('cc').remove()</script>` });

  T({ id: 'ip-info', cat: 'security', name: 'What Is My Screen', desc: 'Local browser & network info (private).', icon: '📟',
    ui: [],
    run: () => ({ stats: [['Viewport', innerWidth + '×' + innerHeight], ['Screen', screen.width + '×' + screen.height], ['Pixel ratio', devicePixelRatio], ['Timezone', Intl.DateTimeFormat().resolvedOptions().timeZone], ['Online', navigator.onLine ? 'yes' : 'no'], ['Do Not Track', navigator.doNotTrack || 'unset']] }) });

  T({ id: 'random-mac', cat: 'security', name: 'Random MAC Address', desc: 'Generate a random MAC address.', icon: '🔌',
    ui: [{ k: 'sep', t: 'select', label: 'Separator', opts: [':', '-'] }],
    run: v => { const a = crypto.getRandomValues(new Uint8Array(6)); a[0] = (a[0] & 0xfe) | 0x02; return [...a].map(b => b.toString(16).padStart(2, '0')).join(v.sep).toUpperCase(); } });

  T({ id: 'text-encrypt-xor', cat: 'security', name: 'XOR Cipher', desc: 'Simple XOR encrypt/decrypt with a key.', icon: '🔀',
    ui: [{ k: 'text', t: 'textarea', label: 'Text (or hex to decrypt)' }, { k: 'key', t: 'text', label: 'Key' }, { k: 'mode', t: 'select', label: 'Mode', opts: ['Encrypt', 'Decrypt'] }],
    run: v => { const key = v.key || ''; if (!key) return { error: 'Enter a key' }; if (v.mode === 'Encrypt') { let out = ''; const t = v.text || ''; for (let i = 0; i < t.length; i++) out += (t.charCodeAt(i) ^ key.charCodeAt(i % key.length)).toString(16).padStart(2, '0'); return out; } try { const h = (v.text || '').trim().match(/.{2}/g) || []; let out = ''; h.forEach((b, i) => out += String.fromCharCode(parseInt(b, 16) ^ key.charCodeAt(i % key.length))); return out; } catch (e) { return { error: 'Invalid hex' }; } } });

  /* ============================================================
     CATEGORY 7 — UNIT CONVERTERS  (20 tools)
     ============================================================ */
  const conv = (id, name, desc, icon, units) => T({ id, cat: 'convert', name, desc, icon,
    ui: [{ k: 'val', t: 'number', label: 'Value', def: 1 }, { k: 'from', t: 'select', label: 'From', opts: Object.keys(units) }, { k: 'to', t: 'select', label: 'To', opts: Object.keys(units) }],
    run: v => { const x = num(v.val, 0); const base = x * units[v.from]; return { stats: [[`${x} ${v.from}`, (base / units[v.to]).toLocaleString(undefined, { maximumFractionDigits: 6 }) + ' ' + v.to]] }; } });

  conv('len-convert', 'Length Converter', 'Meters, feet, miles, km and more.', '📏', { m: 1, km: 1000, cm: .01, mm: .001, mi: 1609.34, yd: .9144, ft: .3048, in: .0254, nmi: 1852 });
  conv('weight-convert', 'Weight Converter', 'Kg, lb, oz, tonnes and more.', '⚖️', { kg: 1, g: .001, mg: 1e-6, lb: .453592, oz: .0283495, t: 1000, st: 6.35029 });
  conv('volume-convert', 'Volume Converter', 'Liters, gallons, cups and more.', '🧪', { L: 1, mL: .001, gal: 3.78541, qt: .946353, pt: .473176, cup: .236588, floz: .0295735, m3: 1000 });
  conv('area-convert', 'Area Converter', 'm², ft², acres, hectares.', '⬛', { 'm²': 1, 'km²': 1e6, 'ft²': .092903, 'yd²': .836127, acre: 4046.86, hectare: 10000, 'mi²': 2.59e6 });
  conv('speed-convert', 'Speed Converter', 'km/h, mph, m/s, knots.', '🏎️', { 'km/h': 1, 'mph': 1.60934, 'm/s': 3.6, 'knot': 1.852, 'ft/s': 1.09728 });
  conv('time-convert', 'Time Converter', 'Seconds, minutes, hours, days.', '⏳', { sec: 1, min: 60, hr: 3600, day: 86400, week: 604800, month: 2.628e6, year: 3.154e7 });
  conv('data-convert', 'Data Size Converter', 'Bytes, KB, MB, GB, TB.', '💽', { B: 1, KB: 1024, MB: 1048576, GB: 1073741824, TB: 1.0995e12, bit: .125 });
  conv('pressure-convert', 'Pressure Converter', 'Pascal, bar, psi, atm.', '🎈', { Pa: 1, kPa: 1000, bar: 100000, psi: 6894.76, atm: 101325, mmHg: 133.322 });
  conv('energy-convert', 'Energy Converter', 'Joule, calorie, kWh, BTU.', '⚡', { J: 1, kJ: 1000, cal: 4.184, kcal: 4184, Wh: 3600, kWh: 3.6e6, BTU: 1055.06 });
  conv('angle-convert', 'Angle Converter', 'Degrees, radians, gradians.', '📐', { deg: 1, rad: 57.2958, grad: .9, turn: 360 });
  conv('fuel-convert', 'Fuel Economy', 'MPG, km/L, L/100km.', '⛽', { 'km/L': 1, 'mpg(US)': .425144, 'mpg(UK)': .354006, 'L/100km': -1 });
  conv('cooking-convert', 'Cooking Measures', 'Tsp, tbsp, cup, mL.', '🥄', { mL: 1, tsp: 4.92892, tbsp: 14.7868, cup: 236.588, floz: 29.5735 });
  conv('digital-res', 'Frequency Converter', 'Hz, kHz, MHz, GHz.', '📻', { Hz: 1, kHz: 1000, MHz: 1e6, GHz: 1e9 });
  conv('power-convert', 'Power Converter', 'Watts, kW, horsepower.', '🔋', { W: 1, kW: 1000, hp: 745.7, MW: 1e6 });
  conv('torque-convert', 'Torque Converter', 'Nm, lb-ft, kg-m.', '🔧', { 'Nm': 1, 'lb-ft': 1.35582, 'kg-m': 9.80665 });
  conv('illuminance', 'Illuminance', 'Lux and foot-candles.', '💡', { lux: 1, 'foot-candle': 10.7639 });

  T({ id: 'currency-note', cat: 'convert', name: 'Aspect Ratio Calculator', desc: 'Compute missing width/height by ratio.', icon: '🖼️',
    ui: [{ k: 'w', t: 'number', label: 'Width' }, { k: 'h', t: 'number', label: 'Height' }, { k: 'nw', t: 'number', label: 'New width (blank to solve)' }],
    run: v => { const w = num(v.w), h = num(v.h); if (!w || !h) return { error: 'Enter original width & height' }; const g = (a, b) => b ? g(b, a % b) : a; const d = g(w, h); if (v.nw) return `Ratio ${w / d}:${h / d}\nAt width ${num(v.nw)} → height ${(num(v.nw) * h / w).toFixed(1)}`; return `Aspect ratio: ${w / d}:${h / d}`; } });

  T({ id: 'shoe-size', cat: 'convert', name: 'Number to Words', desc: 'Spell a number in English words.', icon: '🔤',
    ui: [{ k: 'n', t: 'number', label: 'Number (0–999999)' }],
    run: v => { let n = Math.floor(num(v.n)); if (n < 0 || n > 999999) return { error: '0–999999' }; const ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen']; const tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety']; const three = x => { let s = ''; if (x >= 100) { s += ones[Math.floor(x / 100)] + ' hundred '; x %= 100; } if (x >= 20) { s += tens[Math.floor(x / 10)] + ' '; x %= 10; } if (x) s += ones[x] + ' '; return s.trim(); }; if (n === 0) return 'zero'; let r = ''; if (n >= 1000) { r += three(Math.floor(n / 1000)) + ' thousand '; n %= 1000; } if (n) r += three(n); return r.trim(); } });

  T({ id: 'base-n-convert', cat: 'convert', name: 'Any Base Converter', desc: 'Convert between base 2–36.', icon: '🔢',
    ui: [{ k: 'val', t: 'text', label: 'Value' }, { k: 'from', t: 'number', label: 'From base', def: 10 }, { k: 'to', t: 'number', label: 'To base', def: 16 }],
    run: v => { const fb = Math.max(2, Math.min(36, num(v.from, 10))), tb = Math.max(2, Math.min(36, num(v.to, 16))); const n = parseInt((v.val || '').trim(), fb); if (isNaN(n)) return { error: 'Invalid for base ' + fb }; return `${v.val} (base ${fb}) = ${n.toString(tb).toUpperCase()} (base ${tb})`; } });

  /* ============================================================
     CATEGORY 8 — IMAGE & MEDIA (client-side)  (20 tools)
     ============================================================ */
  const withImage = (v, cb) => new Promise(res => { const f = v.file; if (!f) return res({ error: 'Choose an image' }); const img = new Image(); img.onload = () => { try { res(cb(img)); } catch (e) { res({ error: e.message }); } }; img.onerror = () => res({ error: 'Could not load image' }); img.src = URL.createObjectURL(f); });

  const imgTool = (id, name, desc, icon, extra, cb) => T({ id, cat: 'image', name, desc, icon, async: true, ui: [{ k: 'file', t: 'file', label: 'Image', accept: 'image/*' }, ...extra], run: v => withImage(v, img => cb(img, v)) });

  imgTool('img-resize', 'Image Resizer', 'Resize an image to a target width.', '📐', [{ k: 'w', t: 'number', label: 'New width (px)', def: 800 }], (img, v) => { const w = Math.max(1, num(v.w, 800)); const h = Math.round(img.height * w / img.width); const c = el('canvas'); c.width = w; c.height = h; c.getContext('2d').drawImage(img, 0, 0, w, h); return { image: c.toDataURL('image/png'), note: `${img.width}×${img.height} → ${w}×${h}` }; });
  imgTool('img-compress', 'Image Compressor', 'Compress to JPEG at a quality level.', '🗜️', [{ k: 'q', t: 'number', label: 'Quality % ', def: 70 }], (img, v) => { const c = el('canvas'); c.width = img.width; c.height = img.height; c.getContext('2d').drawImage(img, 0, 0); return { image: c.toDataURL('image/jpeg', Math.max(.1, Math.min(1, num(v.q, 70) / 100))), note: 'Right-click → Save image' }; });
  imgTool('img-to-jpg', 'PNG → JPG', 'Convert an image to JPEG.', '🖼️', [], img => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0); return { image: c.toDataURL('image/jpeg', .92) }; });
  imgTool('img-to-png', 'JPG → PNG', 'Convert an image to PNG.', '🏞️', [], img => { const c = el('canvas'); c.width = img.width; c.height = img.height; c.getContext('2d').drawImage(img, 0, 0); return { image: c.toDataURL('image/png') }; });
  imgTool('img-to-webp', 'Convert to WebP', 'Export an image as WebP.', '🌐', [{ k: 'q', t: 'number', label: 'Quality %', def: 80 }], (img, v) => { const c = el('canvas'); c.width = img.width; c.height = img.height; c.getContext('2d').drawImage(img, 0, 0); const url = c.toDataURL('image/webp', num(v.q, 80) / 100); if (url.indexOf('image/webp') < 0) return { error: 'WebP not supported here' }; return { image: url }; });
  imgTool('img-grayscale', 'Grayscale Filter', 'Convert an image to black & white.', '⬜', [], img => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0); const d = ctx.getImageData(0, 0, c.width, c.height); for (let i = 0; i < d.data.length; i += 4) { const g = d.data[i] * .3 + d.data[i + 1] * .59 + d.data[i + 2] * .11; d.data[i] = d.data[i + 1] = d.data[i + 2] = g; } ctx.putImageData(d, 0, 0); return { image: c.toDataURL() }; });
  imgTool('img-invert', 'Invert Colors', 'Create a photo negative.', '🌗', [], img => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0); const d = ctx.getImageData(0, 0, c.width, c.height); for (let i = 0; i < d.data.length; i += 4) { d.data[i] = 255 - d.data[i]; d.data[i + 1] = 255 - d.data[i + 1]; d.data[i + 2] = 255 - d.data[i + 2]; } ctx.putImageData(d, 0, 0); return { image: c.toDataURL() }; });
  imgTool('img-sepia', 'Sepia Filter', 'Apply a warm vintage tone.', '🟫', [], img => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0); const d = ctx.getImageData(0, 0, c.width, c.height); for (let i = 0; i < d.data.length; i += 4) { const r = d.data[i], g = d.data[i + 1], b = d.data[i + 2]; d.data[i] = Math.min(255, r * .393 + g * .769 + b * .189); d.data[i + 1] = Math.min(255, r * .349 + g * .686 + b * .168); d.data[i + 2] = Math.min(255, r * .272 + g * .534 + b * .131); } ctx.putImageData(d, 0, 0); return { image: c.toDataURL() }; });
  imgTool('img-rotate', 'Image Rotator', 'Rotate an image 90/180/270°.', '🔄', [{ k: 'deg', t: 'select', label: 'Degrees', opts: ['90', '180', '270'] }], (img, v) => { const deg = num(v.deg, 90); const c = el('canvas'); const swap = deg === 90 || deg === 270; c.width = swap ? img.height : img.width; c.height = swap ? img.width : img.height; const ctx = c.getContext('2d'); ctx.translate(c.width / 2, c.height / 2); ctx.rotate(deg * Math.PI / 180); ctx.drawImage(img, -img.width / 2, -img.height / 2); return { image: c.toDataURL('image/png') }; });
  imgTool('img-flip', 'Image Flipper', 'Mirror horizontally or vertically.', '🪞', [{ k: 'dir', t: 'select', label: 'Direction', opts: ['Horizontal', 'Vertical'] }], (img, v) => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); if (v.dir === 'Horizontal') { ctx.translate(c.width, 0); ctx.scale(-1, 1); } else { ctx.translate(0, c.height); ctx.scale(1, -1); } ctx.drawImage(img, 0, 0); return { image: c.toDataURL('image/png') }; });
  imgTool('img-crop-square', 'Square Crop', 'Center-crop to a square.', '⬛', [], img => { const s = Math.min(img.width, img.height); const c = el('canvas'); c.width = c.height = s; c.getContext('2d').drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, 0, 0, s, s); return { image: c.toDataURL('image/png') }; });
  imgTool('img-brightness', 'Brightness / Contrast', 'Adjust brightness of an image.', '🔆', [{ k: 'b', t: 'number', label: 'Brightness % (−/+)', def: 20 }], (img, v) => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.filter = `brightness(${100 + num(v.b, 20)}%)`; ctx.drawImage(img, 0, 0); return { image: c.toDataURL('image/png') }; });
  imgTool('img-blur', 'Blur Filter', 'Apply a Gaussian blur.', '🌫️', [{ k: 'r', t: 'number', label: 'Radius px', def: 4 }], (img, v) => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.filter = `blur(${Math.max(0, num(v.r, 4))}px)`; ctx.drawImage(img, 0, 0); return { image: c.toDataURL('image/png') }; });
  imgTool('img-thumbnail', 'Thumbnail Maker', 'Create a small square thumbnail.', '🔲', [{ k: 's', t: 'number', label: 'Size px', def: 150 }], (img, v) => { const s = Math.max(16, num(v.s, 150)); const src = Math.min(img.width, img.height); const c = el('canvas'); c.width = c.height = s; c.getContext('2d').drawImage(img, (img.width - src) / 2, (img.height - src) / 2, src, src, 0, 0, s, s); return { image: c.toDataURL('image/png') }; });
  imgTool('img-info', 'Image Info', 'Show dimensions, ratio and size.', 'ℹ️', [], (img, v) => { const g = (a, b) => b ? g(b, a % b) : a; const d = g(img.width, img.height); return { note: `${img.width}×${img.height}px · ratio ${img.width / d}:${img.height / d} · ${bytes(v.file.size)} · ${v.file.type}` }; });
  imgTool('img-round', 'Rounded Corners', 'Add rounded corners (PNG).', '🔘', [{ k: 'r', t: 'number', label: 'Radius px', def: 40 }], (img, v) => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); const r = Math.min(num(v.r, 40), Math.min(img.width, img.height) / 2); ctx.beginPath(); ctx.moveTo(r, 0); ctx.arcTo(c.width, 0, c.width, c.height, r); ctx.arcTo(c.width, c.height, 0, c.height, r); ctx.arcTo(0, c.height, 0, 0, r); ctx.arcTo(0, 0, c.width, 0, r); ctx.closePath(); ctx.clip(); ctx.drawImage(img, 0, 0); return { image: c.toDataURL('image/png') }; });
  imgTool('img-watermark', 'Text Watermark', 'Stamp text onto an image.', '💧', [{ k: 'txt', t: 'text', label: 'Watermark text', def: 'yassota.com' }], (img, v) => { const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0); ctx.font = `bold ${Math.max(16, img.width / 20)}px sans-serif`; ctx.fillStyle = 'rgba(255,255,255,.6)'; ctx.textAlign = 'right'; ctx.fillText(v.txt || 'yassota.com', img.width - 20, img.height - 20); return { image: c.toDataURL('image/png') }; });
  imgTool('img-dominant', 'Dominant Color', 'Find the average color of an image.', '🎨', [], img => { const c = el('canvas'); c.width = c.height = 1; c.getContext('2d').drawImage(img, 0, 0, 1, 1); const [r, g, b] = c.getContext('2d').getImageData(0, 0, 1, 1).data; return { swatch: rgbToHex(r, g, b), note: `Average color: ${rgbToHex(r, g, b).toUpperCase()}  rgb(${r},${g},${b})` }; });
  imgTool('img-pixelate', 'Pixelate', 'Apply a pixelation / mosaic effect.', '🟦', [{ k: 'size', t: 'number', label: 'Block px', def: 12 }], (img, v) => { const bs = Math.max(2, num(v.size, 12)); const c = el('canvas'); c.width = img.width; c.height = img.height; const ctx = c.getContext('2d'); ctx.imageSmoothingEnabled = false; const sw = Math.max(1, Math.floor(img.width / bs)), sh = Math.max(1, Math.floor(img.height / bs)); ctx.drawImage(img, 0, 0, sw, sh); ctx.drawImage(c, 0, 0, sw, sh, 0, 0, img.width, img.height); return { image: c.toDataURL('image/png') }; });
  imgTool('img-favicon', 'Favicon Maker', 'Create a 32×32 favicon PNG.', '⭐', [], img => { const c = el('canvas'); c.width = c.height = 32; const s = Math.min(img.width, img.height); c.getContext('2d').drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, 0, 0, 32, 32); return { image: c.toDataURL('image/png'), note: '32×32 favicon' }; });

  /* ============================================================
     CATEGORY 9 — PRODUCTIVITY & TIME  (20 tools)
     ============================================================ */
  T({ id: 'countdown-days', cat: 'product', name: 'Days Until Date', desc: 'Count days from today to any date.', icon: '📅',
    ui: [{ k: 'date', t: 'date', label: 'Target date' }],
    run: v => { if (!v.date) return { error: 'Pick a date' }; const d = Math.ceil((new Date(v.date) - new Date().setHours(0, 0, 0, 0)) / 864e5); return d === 0 ? 'That is today!' : d > 0 ? `${d} day(s) to go` : `${-d} day(s) ago`; } });

  T({ id: 'date-diff', cat: 'product', name: 'Date Difference', desc: 'Days/weeks between two dates.', icon: '📆',
    ui: [{ k: 'a', t: 'date', label: 'Start' }, { k: 'b', t: 'date', label: 'End' }],
    run: v => { if (!v.a || !v.b) return { error: 'Pick both dates' }; const days = Math.abs(Math.round((new Date(v.b) - new Date(v.a)) / 864e5)); return { stats: [['Days', days], ['Weeks', (days / 7).toFixed(1)], ['Months', (days / 30.44).toFixed(1)], ['Years', (days / 365.25).toFixed(2)]] }; } });

  T({ id: 'add-days', cat: 'product', name: 'Add/Subtract Days', desc: 'Find a date N days from a start.', icon: '➕',
    ui: [{ k: 'date', t: 'date', label: 'Start date' }, { k: 'days', t: 'number', label: 'Days (+/−)', def: 30 }],
    run: v => { if (!v.date) return { error: 'Pick a date' }; const d = new Date(v.date); d.setDate(d.getDate() + num(v.days, 0)); return d.toDateString(); } });

  T({ id: 'time-zone-note', cat: 'product', name: 'World Clock', desc: 'Current time in major cities.', icon: '🌏',
    ui: [],
    run: () => { const zones = { 'New York': 'America/New_York', 'London': 'Europe/London', 'Dubai': 'Asia/Dubai', 'Damascus': 'Asia/Damascus', 'Tokyo': 'Asia/Tokyo', 'Sydney': 'Australia/Sydney' }; return { stats: Object.entries(zones).map(([c, z]) => [c, new Date().toLocaleTimeString('en-US', { timeZone: z, hour: '2-digit', minute: '2-digit' })]) }; } });

  T({ id: 'pomodoro-plan', cat: 'product', name: 'Pomodoro Planner', desc: 'Plan work/break cycles for a task.', icon: '🍅',
    ui: [{ k: 'mins', t: 'number', label: 'Total minutes', def: 120 }],
    run: v => { const total = Math.max(25, num(v.mins, 120)); const cycles = Math.floor(total / 30); return { stats: [['Pomodoros (25m)', cycles], ['Short breaks', cycles], ['Long break after', '4 pomodoros'], ['Focus time', cycles * 25 + ' min']] }; } });

  T({ id: 'reading-time-est', cat: 'product', name: 'Reading Time Estimator', desc: 'How long to read this text.', icon: '⏲️',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }, { k: 'wpm', t: 'number', label: 'Words/min', def: 200 }],
    run: v => { const w = ((v.text || '').match(/\S+/g) || []).length; const m = w / Math.max(50, num(v.wpm, 200)); return { stats: [['Words', w], ['Reading time', m < 1 ? '< 1 min' : Math.round(m) + ' min'], ['Speaking time', Math.round(w / 130) + ' min']] }; } });

  T({ id: 'grade-calc', cat: 'product', name: 'Grade Calculator', desc: 'Weighted average of your grades.', icon: '🎓',
    ui: [{ k: 'data', t: 'textarea', label: 'score,weight per line (e.g. 90,30)' }],
    run: v => { const rows = (v.data || '').split('\n').map(r => r.split(',').map(parseFloat)).filter(r => r.length === 2 && !r.some(isNaN)); if (!rows.length) return { error: 'Use "score,weight" lines' }; const tw = rows.reduce((s, r) => s + r[1], 0); const avg = rows.reduce((s, r) => s + r[0] * r[1], 0) / (tw || 1); return { stats: [['Weighted grade', avg.toFixed(2)], ['Total weight', tw + '%']] }; } });

  T({ id: 'gpa-calc', cat: 'product', name: 'GPA Calculator', desc: 'Compute GPA from grades & credits.', icon: '📚',
    ui: [{ k: 'data', t: 'textarea', label: 'gradePoint,credits per line (e.g. 4,3)' }],
    run: v => { const rows = (v.data || '').split('\n').map(r => r.split(',').map(parseFloat)).filter(r => r.length === 2 && !r.some(isNaN)); if (!rows.length) return { error: 'Use "gradePoint,credits" lines' }; const tc = rows.reduce((s, r) => s + r[1], 0); const gpa = rows.reduce((s, r) => s + r[0] * r[1], 0) / (tc || 1); return { stats: [['GPA', gpa.toFixed(3)], ['Total credits', tc]] }; } });

  T({ id: 'invoice-total', cat: 'product', name: 'Invoice Total', desc: 'Subtotal, tax and total for line items.', icon: '🧾',
    ui: [{ k: 'items', t: 'textarea', label: 'qty,price per line (e.g. 2,19.99)' }, { k: 'tax', t: 'number', label: 'Tax %', def: 0 }],
    run: v => { const rows = (v.items || '').split('\n').map(r => r.split(',').map(parseFloat)).filter(r => r.length === 2 && !r.some(isNaN)); if (!rows.length) return { error: 'Use "qty,price" lines' }; const sub = rows.reduce((s, r) => s + r[0] * r[1], 0); const tax = sub * num(v.tax) / 100; return { stats: [['Subtotal', sub.toFixed(2)], ['Tax', tax.toFixed(2)], ['Total', (sub + tax).toFixed(2)]] }; } });

  T({ id: 'random-picker', cat: 'product', name: 'Random Picker', desc: 'Pick a random winner from a list.', icon: '🏆',
    ui: [{ k: 'items', t: 'textarea', label: 'Options (one per line)' }],
    run: v => { const a = (v.items || '').split('\n').map(x => x.trim()).filter(Boolean); if (!a.length) return { error: 'Add some options' }; return '🎉 ' + a[Math.floor(Math.random() * a.length)]; } });

  T({ id: 'list-shuffle', cat: 'product', name: 'List Shuffler', desc: 'Randomize the order of a list.', icon: '🔀',
    ui: [{ k: 'items', t: 'textarea', label: 'Items (one per line)' }],
    run: v => { const a = (v.items || '').split('\n').filter(x => x.trim()); for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1));[a[i], a[j]] = [a[j], a[i]]; } return a.join('\n'); } });

  T({ id: 'team-splitter', cat: 'product', name: 'Team Splitter', desc: 'Split names into balanced teams.', icon: '👥',
    ui: [{ k: 'names', t: 'textarea', label: 'Names (one per line)' }, { k: 'teams', t: 'number', label: 'Teams', def: 2 }],
    run: v => { let a = (v.names || '').split('\n').map(x => x.trim()).filter(Boolean); const t = Math.max(2, num(v.teams, 2)); for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1));[a[i], a[j]] = [a[j], a[i]]; } const groups = Array.from({ length: t }, () => []); a.forEach((n, i) => groups[i % t].push(n)); return groups.map((g, i) => `Team ${i + 1}: ${g.join(', ')}`).join('\n'); } });

  T({ id: 'dice-roll', cat: 'product', name: 'Dice Roller', desc: 'Roll dice (e.g. 2d6, 1d20).', icon: '🎲',
    ui: [{ k: 'dice', t: 'text', label: 'Notation (e.g. 2d6)', def: '2d6' }],
    run: v => { const m = (v.dice || '').match(/(\d+)d(\d+)/i); if (!m) return { error: 'Use NdM, e.g. 2d6' }; const n = Math.min(100, +m[1]), s = +m[2]; const rolls = []; for (let i = 0; i < n; i++) rolls.push(1 + Math.floor(Math.random() * s)); return `Rolls: ${rolls.join(', ')}\nTotal: ${rolls.reduce((a, b) => a + b, 0)}`; } });

  T({ id: 'coin-flip', cat: 'product', name: 'Coin Flipper', desc: 'Flip one or many coins.', icon: '🪙',
    ui: [{ k: 'n', t: 'number', label: 'Flips', def: 1 }],
    run: v => { const n = Math.max(1, Math.min(1000, num(v.n, 1))); let h = 0; const out = []; for (let i = 0; i < n; i++) { const r = Math.random() < .5; if (r) h++; if (n <= 20) out.push(r ? 'Heads' : 'Tails'); } return n <= 20 ? out.join(', ') : `Heads: ${h}, Tails: ${n - h}`; } });

  T({ id: 'countdown-timer-note', cat: 'product', name: 'Working Days Counter', desc: 'Count business days between dates.', icon: '💼',
    ui: [{ k: 'a', t: 'date', label: 'Start' }, { k: 'b', t: 'date', label: 'End' }],
    run: v => { if (!v.a || !v.b) return { error: 'Pick both dates' }; let d = new Date(v.a), end = new Date(v.b), count = 0; if (d > end) [d, end] = [end, d]; while (d <= end) { const w = d.getDay(); if (w !== 0 && w !== 6) count++; d.setDate(d.getDate() + 1); } return { stats: [['Business days', count]] }; } });

  T({ id: 'savings-goal', cat: 'product', name: 'Savings Goal Planner', desc: 'Monthly saving to reach a goal.', icon: '🎯',
    ui: [{ k: 'goal', t: 'number', label: 'Goal amount' }, { k: 'have', t: 'number', label: 'Saved so far' }, { k: 'months', t: 'number', label: 'Months', def: 12 }],
    run: v => { const g = num(v.goal), h = num(v.have), m = Math.max(1, num(v.months, 12)); const need = Math.max(0, g - h); return { stats: [['Remaining', need.toFixed(2)], ['Per month', (need / m).toFixed(2)], ['Per week', (need / (m * 4.33)).toFixed(2)]] }; } });

  T({ id: 'unit-price', cat: 'product', name: 'Unit Price Compare', desc: 'Which package is cheaper per unit?', icon: '🛒',
    ui: [{ k: 'p1', t: 'number', label: 'Price A' }, { k: 'q1', t: 'number', label: 'Qty A' }, { k: 'p2', t: 'number', label: 'Price B' }, { k: 'q2', t: 'number', label: 'Qty B' }],
    run: v => { const u1 = num(v.p1) / (num(v.q1) || 1), u2 = num(v.p2) / (num(v.q2) || 1); const better = u1 < u2 ? 'A' : u2 < u1 ? 'B' : 'equal'; return { stats: [['Unit price A', u1.toFixed(4)], ['Unit price B', u2.toFixed(4)], ['Better deal', better]] }; } });

  T({ id: 'hours-calc', cat: 'product', name: 'Hours Calculator', desc: 'Total hours between start & end time.', icon: '🕐',
    ui: [{ k: 'start', t: 'text', label: 'Start (HH:MM)', def: '09:00' }, { k: 'end', t: 'text', label: 'End (HH:MM)', def: '17:30' }, { k: 'break', t: 'number', label: 'Break (min)', def: 30 }],
    run: v => { const p = t => { const m = (t || '').match(/(\d{1,2}):(\d{2})/); return m ? +m[1] * 60 + +m[2] : null; }; const s = p(v.start), e = p(v.end); if (s == null || e == null) return { error: 'Use HH:MM' }; let mins = (e - s + 1440) % 1440 - num(v.break, 0); return { stats: [['Worked', (mins / 60).toFixed(2) + ' hrs'], ['Minutes', mins]] }; } });

  T({ id: 'random-name', cat: 'product', name: 'Username Generator', desc: 'Generate available-looking usernames.', icon: '🙂',
    ui: [{ k: 'word', t: 'text', label: 'Base word (optional)' }, { k: 'n', t: 'number', label: 'How many', def: 6 }],
    run: v => { const adj = ['swift', 'bright', 'cosmic', 'silent', 'lunar', 'neon', 'pixel', 'cyber', 'solar', 'urban']; const noun = ['fox', 'wolf', 'ninja', 'coder', 'nova', 'byte', 'quest', 'orbit', 'spark', 'echo']; const base = (v.word || '').toLowerCase().replace(/\s+/g, ''); const out = []; for (let i = 0; i < Math.max(1, Math.min(20, num(v.n, 6))); i++) { const a = adj[Math.random() * adj.length | 0], n = noun[Math.random() * noun.length | 0]; out.push((base || a) + '_' + n + (Math.random() * 900 + 100 | 0)); } return out.join('\n'); } });

  /* ============================================================
     CATEGORY 10 — SOCIAL & MARKETING  (20 tools)
     ============================================================ */
  T({ id: 'hashtag-gen', cat: 'social', name: 'Hashtag Generator', desc: 'Turn keywords into hashtags.', icon: '#️⃣',
    ui: [{ k: 'text', t: 'textarea', label: 'Keywords or caption' }],
    run: v => { const w = (v.text || '').toLowerCase().match(/[a-z0-9\u0600-\u06FF]{3,}/g) || []; const tags = [...new Set(w)].map(x => '#' + x); return tags.length ? tags.join(' ') : '(add some words)'; } });

  T({ id: 'char-counter-social', cat: 'social', name: 'Social Char Counter', desc: 'Character limits for each platform.', icon: '📱',
    ui: [{ k: 'text', t: 'textarea', label: 'Your post' }],
    run: v => { const n = (v.text || '').length; const lim = { Twitter: 280, Instagram: 2200, 'FB post': 63206, 'LinkedIn': 3000, 'Bio': 160, 'SMS': 160 }; return { stats: Object.entries(lim).map(([p, l]) => [p, `${n}/${l} ${n <= l ? '✅' : '❌'}`]) }; } });

  T({ id: 'emoji-picker', cat: 'social', name: 'Emoji Search', desc: 'Find emojis by keyword.', icon: '😀',
    ui: [{ k: 'q', t: 'text', label: 'Keyword (fire, love, star…)' }],
    run: v => { const map = { fire: '🔥🧨🎆', love: '❤️😍🥰💕', star: '⭐🌟✨💫', money: '💰💵🤑💸', happy: '😀😄😁🙂', sad: '😢😭😞☹️', food: '🍕🍔🍟🌮', party: '🎉🎊🥳🍾', tech: '💻📱⌨️🖥️', check: '✅☑️✔️👍', arrow: '➡️⬅️⬆️⬇️', heart: '❤️🧡💛💚💙💜' }; const q = (v.q || '').toLowerCase(); const hit = Object.entries(map).find(([k]) => k.includes(q) || q.includes(k)); return hit ? hit[1] : 'Try: fire, love, star, money, happy, food, party, tech, check, heart'; } });

  T({ id: 'bio-gen', cat: 'social', name: 'Bio Formatter', desc: 'Format a stylish multi-line bio.', icon: '📝',
    ui: [{ k: 'lines', t: 'textarea', label: 'Bio lines' }, { k: 'emoji', t: 'text', label: 'Bullet emoji', def: '▸' }],
    run: v => (v.lines || '').split('\n').filter(x => x.trim()).map(l => (v.emoji || '▸') + ' ' + l.trim()).join('\n') });

  T({ id: 'tweet-splitter', cat: 'social', name: 'Thread Splitter', desc: 'Split long text into tweet-sized parts.', icon: '🧵',
    ui: [{ k: 'text', t: 'textarea', label: 'Long text' }, { k: 'limit', t: 'number', label: 'Char limit', def: 270 }],
    run: v => { const lim = num(v.limit, 270) - 8; const words = (v.text || '').split(/\s+/); const parts = []; let cur = ''; words.forEach(w => { if ((cur + ' ' + w).trim().length > lim) { parts.push(cur.trim()); cur = w; } else cur += ' ' + w; }); if (cur.trim()) parts.push(cur.trim()); return parts.map((p, i) => `(${i + 1}/${parts.length}) ${p}`).join('\n\n'); } });

  T({ id: 'yt-title-score', cat: 'social', name: 'Headline Analyzer', desc: 'Score a headline for engagement.', icon: '📰',
    ui: [{ k: 'title', t: 'text', label: 'Headline' }],
    run: v => { const t = v.title || ''; const words = t.split(/\s+/).filter(Boolean).length; const power = ['how', 'why', 'best', 'top', 'guide', 'free', 'easy', 'new', 'proven', 'ultimate', 'secret', 'you', 'now']; const hits = power.filter(p => t.toLowerCase().includes(p)).length; const num_ = /\d/.test(t) ? 1 : 0; let score = 40 + hits * 12 + num_ * 15 + (words >= 6 && words <= 12 ? 15 : 0); score = Math.min(100, score); return { stats: [['Score', score + '/100'], ['Words', words + (words >= 6 && words <= 12 ? ' ✅' : ' ⚠️')], ['Power words', hits], ['Has number', num_ ? 'yes ✅' : 'no']] }; } });

  T({ id: 'cta-gen', cat: 'social', name: 'CTA Generator', desc: 'Get call-to-action phrase ideas.', icon: '📣',
    ui: [{ k: 'product', t: 'text', label: 'Product/offer' }],
    run: v => { const p = (v.product || 'it').trim(); return [`👉 Get ${p} today`, `Start using ${p} — free`, `Don't miss out on ${p}`, `Try ${p} risk-free now`, `Claim your ${p} →`, `Join thousands using ${p}`, `Unlock ${p} in seconds`].join('\n'); } });

  T({ id: 'fake-tweet-note', cat: 'social', name: 'Caption Length Splitter', desc: 'Chunk captions for carousels.', icon: '🖼️',
    ui: [{ k: 'text', t: 'textarea', label: 'Caption' }, { k: 'n', t: 'number', label: 'Slides', def: 5 }],
    run: v => { const words = (v.text || '').split(/\s+/).filter(Boolean); const n = Math.max(1, num(v.n, 5)); const per = Math.ceil(words.length / n); const out = []; for (let i = 0; i < n; i++) out.push(`Slide ${i + 1}: ` + words.slice(i * per, (i + 1) * per).join(' ')); return out.filter(s => s.length > 9).join('\n\n'); } });

  T({ id: 'engagement-rate', cat: 'social', name: 'Engagement Rate', desc: 'Compute engagement rate %.', icon: '📈',
    ui: [{ k: 'likes', t: 'number', label: 'Likes + comments' }, { k: 'followers', t: 'number', label: 'Followers' }],
    run: v => { const e = num(v.likes), f = num(v.followers); if (!f) return { error: 'Enter followers' }; const r = e / f * 100; const lvl = r < 1 ? 'Low' : r < 3.5 ? 'Good' : r < 6 ? 'High' : 'Excellent'; return { stats: [['Engagement rate', r.toFixed(2) + '%'], ['Benchmark', lvl]] }; } });

  T({ id: 'best-time-note', cat: 'social', name: 'Word Cloud Data', desc: 'Top words for a word-cloud.', icon: '☁️',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => { const w = (v.text || '').toLowerCase().match(/[a-z\u0600-\u06FF]{3,}/g) || []; const f = {}; w.forEach(x => f[x] = (f[x] || 0) + 1); return Object.entries(f).sort((a, b) => b[1] - a[1]).slice(0, 20).map(([k, n]) => `${k}: ${n}`).join('\n') || '(no words)'; } });

  T({ id: 'poll-gen', cat: 'social', name: 'Poll Idea Formatter', desc: 'Format a poll with options.', icon: '📊',
    ui: [{ k: 'q', t: 'text', label: 'Question' }, { k: 'opts', t: 'textarea', label: 'Options (one per line)' }],
    run: v => { const opts = (v.opts || '').split('\n').filter(x => x.trim()); const letters = 'ABCDEFGH'; return `📊 ${v.q}\n\n` + opts.map((o, i) => `${letters[i]}) ${o.trim()}`).join('\n') + '\n\nVote below! 👇'; } });

  T({ id: 'link-in-bio', cat: 'social', name: 'Link-in-Bio Builder', desc: 'Build a tidy link list block.', icon: '🔗',
    ui: [{ k: 'links', t: 'textarea', label: 'Label|URL per line' }],
    run: v => (v.links || '').split('\n').filter(x => x.includes('|')).map(r => { const [l, u] = r.split('|'); return `▸ ${l.trim()}: ${u.trim()}`; }).join('\n') });

  T({ id: 'mention-extract', cat: 'social', name: 'Mention & Tag Extractor', desc: 'Pull @mentions and #hashtags.', icon: '@',
    ui: [{ k: 'text', t: 'textarea', label: 'Post text' }],
    run: v => { const men = [...new Set((v.text || '').match(/@\w+/g) || [])]; const tags = [...new Set((v.text || '').match(/#\w+/g) || [])]; return `Mentions (${men.length}): ${men.join(' ') || '—'}\nHashtags (${tags.length}): ${tags.join(' ') || '—'}`; } });

  T({ id: 'emoji-remove', cat: 'social', name: 'Emoji Remover', desc: 'Strip all emojis from text.', icon: '🚫',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => (v.text || '').replace(/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE00}-\u{FE0F}\u{1F1E6}-\u{1F1FF}]/gu, '').replace(/\s+/g, ' ').trim() });

  T({ id: 'title-emoji', cat: 'social', name: 'Emoji Title Enhancer', desc: 'Add relevant emojis to a title.', icon: '✨',
    ui: [{ k: 'title', t: 'text', label: 'Title' }],
    run: v => { const map = { new: '🆕', best: '🏆', free: '🎁', tip: '💡', money: '💰', fast: '⚡', love: '❤️', guide: '📘', top: '🔝', hot: '🔥', win: '🎉' }; let t = v.title || ''; let pre = ''; for (const [k, e] of Object.entries(map)) if (t.toLowerCase().includes(k)) pre += e; return (pre || '✨') + ' ' + t; } });

  T({ id: 'aspect-social', cat: 'social', name: 'Social Image Sizes', desc: 'Recommended image sizes per platform.', icon: '📐',
    ui: [{ k: 'platform', t: 'select', label: 'Platform', opts: ['Instagram', 'Facebook', 'Twitter/X', 'LinkedIn', 'YouTube', 'TikTok'] }],
    run: v => { const S = { Instagram: 'Post 1080×1080 · Story 1080×1920 · Reel 1080×1920', Facebook: 'Post 1200×630 · Cover 820×312 · Story 1080×1920', 'Twitter/X': 'Post 1600×900 · Header 1500×500', LinkedIn: 'Post 1200×627 · Cover 1584×396', YouTube: 'Thumbnail 1280×720 · Banner 2560×1440', TikTok: 'Video 1080×1920 · Profile 200×200' }; return S[v.platform]; } });

  T({ id: 'quote-card-note', cat: 'social', name: 'Quote Formatter', desc: 'Format a quote card text.', icon: '❝',
    ui: [{ k: 'quote', t: 'textarea', label: 'Quote' }, { k: 'author', t: 'text', label: 'Author' }],
    run: v => `“${(v.quote || '').trim()}”\n\n— ${(v.author || 'Unknown').trim()}` });

  T({ id: 'follower-milestone', cat: 'social', name: 'Growth Forecaster', desc: 'Project follower growth over time.', icon: '🚀',
    ui: [{ k: 'now', t: 'number', label: 'Current followers' }, { k: 'rate', t: 'number', label: 'Monthly growth %', def: 10 }, { k: 'months', t: 'number', label: 'Months', def: 12 }],
    run: v => { const n = num(v.now), r = num(v.rate, 10) / 100, m = Math.max(1, num(v.months, 12)); const future = n * Math.pow(1 + r, m); return { stats: [['In ' + m + ' months', Math.round(future).toLocaleString()], ['New followers', Math.round(future - n).toLocaleString()]] }; } });

  T({ id: 'ab-test-note', cat: 'social', name: 'A/B Winner Calculator', desc: 'Which variant has a better rate?', icon: '🆚',
    ui: [{ k: 'a_c', t: 'number', label: 'A conversions' }, { k: 'a_v', t: 'number', label: 'A visitors' }, { k: 'b_c', t: 'number', label: 'B conversions' }, { k: 'b_v', t: 'number', label: 'B visitors' }],
    run: v => { const a = num(v.a_c) / (num(v.a_v) || 1) * 100, b = num(v.b_c) / (num(v.b_v) || 1) * 100; const w = a > b ? 'A' : b > a ? 'B' : 'tie'; return { stats: [['A rate', a.toFixed(2) + '%'], ['B rate', b.toFixed(2) + '%'], ['Winner', w], ['Lift', (Math.abs(a - b)).toFixed(2) + ' pts']] }; } });

  T({ id: 'roi-calc', cat: 'social', name: 'Ad ROI Calculator', desc: 'Return on ad spend and profit.', icon: '💵',
    ui: [{ k: 'spend', t: 'number', label: 'Ad spend' }, { k: 'revenue', t: 'number', label: 'Revenue' }],
    run: v => { const s = num(v.spend), r = num(v.revenue); if (!s) return { error: 'Enter ad spend' }; return { stats: [['Profit', (r - s).toFixed(2)], ['ROI', ((r - s) / s * 100).toFixed(1) + '%'], ['ROAS', (r / s).toFixed(2) + 'x']] }; } });

  /* ============================================================
     BONUS TOOLS  (8) — round the library past 200
     ============================================================ */
  T({ id: 'text-diff', cat: 'text', name: 'Text Diff', desc: 'Show lines that differ between two texts.', icon: '⚖️',
    ui: [{ k: 'a', t: 'textarea', label: 'Original' }, { k: 'b', t: 'textarea', label: 'Changed' }],
    run: v => { const A = (v.a || '').split('\n'), B = (v.b || '').split('\n'); const out = []; const max = Math.max(A.length, B.length); for (let i = 0; i < max; i++) { if (A[i] === B[i]) continue; if (A[i] != null) out.push('- ' + A[i]); if (B[i] != null) out.push('+ ' + B[i]); } return out.length ? out.join('\n') : '✅ The texts are identical.'; } });

  T({ id: 'acronym-gen', cat: 'text', name: 'Acronym Maker', desc: 'Build an acronym from a phrase.', icon: '🔡',
    ui: [{ k: 'text', t: 'text', label: 'Phrase' }],
    run: v => { const w = (v.text || '').match(/[A-Za-z؀-ۿ]+/g) || []; return w.length ? w.map(x => x[0].toUpperCase()).join('') : '(enter a phrase)'; } });

  T({ id: 'pythagoras', cat: 'math', name: 'Pythagorean Theorem', desc: 'Find the hypotenuse or a missing side.', icon: '📐',
    ui: [{ k: 'a', t: 'number', label: 'Side a' }, { k: 'b', t: 'number', label: 'Side b' }],
    run: v => { const a = num(v.a), b = num(v.b); if (!a || !b) return { error: 'Enter both sides' }; return { stats: [['Hypotenuse c', Math.hypot(a, b).toFixed(4)], ['Area', (a * b / 2).toFixed(2)]] }; } });

  T({ id: 'string-to-color', cat: 'dev', name: 'String → Color', desc: 'Deterministic color from any string.', icon: '🎨',
    ui: [{ k: 'text', t: 'text', label: 'Any string (name, id…)' }],
    run: v => { let h = 0; const s = v.text || ''; for (let i = 0; i < s.length; i++) h = s.charCodeAt(i) + ((h << 5) - h); const hex = '#' + ((h >> 0) & 0xffffff).toString(16).padStart(6, '0').slice(0, 6); return { swatch: hex, stats: [['Color', hex.toUpperCase()]] }; } });

  T({ id: 'yes-no', cat: 'product', name: 'Decision Maker', desc: 'Let fate decide: yes or no.', icon: '🎱',
    ui: [{ k: 'q', t: 'text', label: 'Your question (optional)' }],
    run: v => { const ans = ['Yes ✅', 'No ❌', 'Definitely 💯', 'Not now ⏳', 'Ask again later 🔁', 'Absolutely 🚀', 'Better not 🙅']; return (v.q ? v.q + '\n→ ' : '') + ans[Math.floor(Math.random() * ans.length)]; } });

  T({ id: 'download-time', cat: 'convert', name: 'Download Time', desc: 'Estimate download time for a file.', icon: '📥',
    ui: [{ k: 'size', t: 'number', label: 'File size (MB)' }, { k: 'speed', t: 'number', label: 'Speed (Mbps)', def: 50 }],
    run: v => { const mb = num(v.size), mbps = num(v.speed, 50); if (!mb || !mbps) return { error: 'Enter size and speed' }; const secs = (mb * 8) / mbps; const h = Math.floor(secs / 3600), m = Math.floor(secs % 3600 / 60), s = Math.round(secs % 60); return { stats: [['Time', (h ? h + 'h ' : '') + (m ? m + 'm ' : '') + s + 's'], ['Total seconds', secs.toFixed(1)]] }; } });

  T({ id: 'text-entropy', cat: 'security', name: 'Text Entropy', desc: 'Shannon entropy of a string (randomness).', icon: '📉',
    ui: [{ k: 'text', t: 'textarea', label: 'Text' }],
    run: v => { const s = v.text || ''; if (!s) return { error: 'Enter text' }; const f = {}; for (const c of s) f[c] = (f[c] || 0) + 1; let H = 0; for (const c in f) { const p = f[c] / s.length; H -= p * Math.log2(p); } return { stats: [['Entropy/char', H.toFixed(3) + ' bits'], ['Total entropy', (H * s.length).toFixed(1) + ' bits'], ['Unique chars', Object.keys(f).length]] }; } });

  T({ id: 'initials-avatar', cat: 'social', name: 'Initials Avatar', desc: 'Generate a colored initials avatar.', icon: '🅰️',
    ui: [{ k: 'name', t: 'text', label: 'Name' }],
    run: v => { const n = (v.name || '').trim(); if (!n) return { error: 'Enter a name' }; const parts = n.split(/\s+/); const initials = (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase(); let h = 0; for (let i = 0; i < n.length; i++) h = n.charCodeAt(i) + ((h << 5) - h); const bg = 'hsl(' + (h % 360) + ',65%,55%)'; const c = el('canvas'); c.width = c.height = 160; const ctx = c.getContext('2d'); ctx.fillStyle = bg; ctx.fillRect(0, 0, 160, 160); ctx.fillStyle = '#fff'; ctx.font = 'bold 64px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(initials, 80, 88); return { image: c.toDataURL('image/png'), note: 'Initials: ' + initials }; } });

  /* ============================================================
     ENGINE — expose registry + renderer
     ============================================================ */
  const CATS = {
    text: { name: 'Text', icon: '📝', cls: 'cat-text' },
    math: { name: 'Math & Numbers', icon: '🔢', cls: 'cat-math' },
    color: { name: 'Color & Design', icon: '🎨', cls: 'cat-color' },
    dev: { name: 'Developer', icon: '⌨️', cls: 'cat-dev' },
    seo: { name: 'SEO & Web', icon: '🔍', cls: 'cat-seo' },
    security: { name: 'Security', icon: '🔐', cls: 'cat-security' },
    convert: { name: 'Converters', icon: '🔄', cls: 'cat-convert' },
    image: { name: 'Image & Media', icon: '🖼️', cls: 'cat-image' },
    product: { name: 'Productivity', icon: '⚡', cls: 'cat-product' },
    social: { name: 'Social & Marketing', icon: '📣', cls: 'cat-social' },
  };

  window.YassotaTools = {
    all: TOOLS,
    cats: CATS,
    byId: id => TOOLS.find(t => t.id === id),
    byCat: c => TOOLS.filter(t => t.cat === c),
    count: TOOLS.length,
    render: mountToolRunner,
    renderResult,
  };

  /* ---- result renderer ---- */
  function renderResult(box, r) {
    box.innerHTML = '';
    box.style.display = 'block';
    if (r == null || r === '') { box.style.display = 'none'; return; }
    if (typeof r === 'object' && r.error) {
      box.append(el('div', { class: 'flash-error', html: '⚠️ ' + esc(r.error) }));
      return;
    }
    const addCopy = txt => { const b = el('button', { class: 'btn-copy', onclick: () => { copy(txt); b.textContent = '✓ Copied'; setTimeout(() => b.textContent = 'Copy', 1500); } }, 'Copy'); return b; };

    if (typeof r === 'string') {
      const pre = el('pre'); pre.textContent = r;
      box.append(el('div', { style: 'display:flex;justify-content:flex-end;margin-bottom:8px' }, addCopy(r)), pre);
      return;
    }
    if (r.stats) {
      const grid = el('div', { class: 'result-grid' });
      r.stats.forEach(([k, val]) => grid.append(el('div', { class: 'stat-item' }, [el('div', { class: 'stat-val' }, String(val)), el('div', { class: 'stat-label' }, k)])));
      box.append(grid);
      if (r.raw) { const pre = el('pre', { style: 'margin-top:12px' }); pre.textContent = r.raw; box.append(pre); }
    }
    if (r.swatch) box.append(el('div', { class: 'color-preview', style: 'background:' + r.swatch }));
    if (r.swatches) {
      const row = el('div', { class: 'swatch-row' });
      r.swatches.forEach((c, i) => { const s = el('div', { class: 'swatch', style: 'background:' + c, title: c, onclick: () => copy(c) }); const wrap = el('div', { style: 'text-align:center' }, [s, el('div', { style: 'font-size:10px;margin-top:4px;color:var(--muted)' }, (r.labels && r.labels[i]) || c)]); row.append(wrap); });
      box.append(row);
    }
    if (r.gradient) { box.append(el('div', { class: 'color-preview', style: 'height:110px;background:' + r.gradient }), el('div', { style: 'display:flex;gap:8px;align-items:center;margin-top:10px' }, [el('code', { style: 'flex:1;font-size:12px;word-break:break-all' }, r.code), addCopy(r.code)])); }
    if (r.shadow) { box.append(el('div', { style: 'height:110px;display:flex;align-items:center;justify-content:center' }, el('div', { style: 'width:120px;height:120px;border-radius:16px;background:#fff;box-shadow:' + r.shadow })), el('div', { style: 'display:flex;gap:8px;align-items:center;margin-top:10px' }, [el('code', { style: 'flex:1;font-size:12px;word-break:break-all' }, r.code), addCopy(r.code)])); }
    if (r.image) { box.append(el('img', { src: r.image, style: 'max-width:100%;border-radius:12px;border:1px solid var(--line)' })); if (r.note) box.append(el('div', { class: 'tip' }, r.note)); box.append(el('div', { class: 'tip' }, 'Right-click the image → “Save image as…” to download.')); }
    if (r.note && !r.image) box.append(el('div', { class: 'tip' }, r.note));
    if (r.serp) { box.append(el('div', { style: 'background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px' }, [el('div', { style: 'color:#1a0dab;font-size:19px;line-height:1.3' }, r.serp.title || 'Title'), el('div', { style: 'color:#006621;font-size:13px' }, r.serp.url || 'example.com'), el('div', { style: 'color:#545454;font-size:13px' }, r.serp.desc || 'Description…')])); }
    if (r.ogcard) { box.append(el('div', { style: 'border:1px solid var(--line);border-radius:12px;overflow:hidden;max-width:420px' }, [r.ogcard.img ? el('img', { src: r.ogcard.img, style: 'width:100%;height:180px;object-fit:cover' }) : el('div', { style: 'height:180px;background:var(--grad-brand)' }), el('div', { style: 'padding:12px' }, [el('div', { style: 'font-size:11px;color:var(--muted);text-transform:uppercase' }, r.ogcard.url || 'yassota.com'), el('div', { style: 'font-weight:700;margin:4px 0' }, r.ogcard.title || 'Title'), el('div', { style: 'font-size:13px;color:var(--muted)' }, r.ogcard.desc || '')])])); }
  }

  /* ---- form + runner mount ---- */
  function mountToolRunner(tool, container) {
    container.innerHTML = '';
    const shell = el('div', { class: 'tool-shell' });
    const form = el('div', { class: 'tool-controls' });
    const fields = {};

    (tool.ui || []).forEach(f => {
      const wrap = el('div', { class: 'tool-col-fixed' });
      if (f.t !== 'checkbox') wrap.append(el('label', {}, f.label));
      let input;
      if (f.t === 'textarea') input = el('textarea', { placeholder: f.ph || '' });
      else if (f.t === 'select') { input = el('select'); (f.opts || []).forEach(o => input.append(el('option', {}, o))); }
      else if (f.t === 'checkbox') { input = el('input', { type: 'checkbox' }); if (f.def) input.checked = true; wrap.append(el('label', { style: 'display:flex;align-items:center;gap:8px;cursor:pointer' }, [input, f.label])); }
      else if (f.t === 'color') input = el('input', { type: 'color', value: f.def || '#6366f1' });
      else if (f.t === 'file') input = el('input', { type: 'file', accept: f.accept || '' });
      else { input = el('input', { type: f.t }); if (f.def != null) input.value = f.def; }
      if (f.t !== 'checkbox') wrap.append(input);
      fields[f.k] = { input, type: f.t };
      form.append(wrap);
    });

    const result = el('div', { class: 'tool-result', style: 'display:none' });
    const runBtn = el('button', { class: 'btn-run' }, '▶ Run');
    const clearBtn = el('button', { class: 'btn-ghost' }, 'Clear');

    const gather = () => { const v = {}; for (const k in fields) { const { input, type } = fields[k]; v[k] = type === 'checkbox' ? input.checked : type === 'file' ? input.files[0] : input.value; } return v; };
    const doRun = async () => {
      runBtn.textContent = '…'; runBtn.disabled = true;
      try { let out = tool.run(gather()); if (out instanceof Promise) out = await out; renderResult(result, out); }
      catch (e) { renderResult(result, { error: e.message || 'Something went wrong' }); }
      runBtn.textContent = '▶ Run'; runBtn.disabled = false;
    };
    runBtn.addEventListener('click', doRun);
    clearBtn.addEventListener('click', () => { for (const k in fields) { const { input, type } = fields[k]; if (type === 'checkbox') input.checked = false; else if (type !== 'file' && type !== 'color') input.value = ''; } result.style.display = 'none'; });
    // live-run for instant tools (no file, no async)
    if (!tool.async && !(tool.ui || []).some(f => f.t === 'file')) {
      form.addEventListener('input', () => { if ((tool.ui || []).length) doRun(); });
    }

    shell.append(form, el('div', { style: 'display:flex;gap:10px;margin-top:16px' }, [runBtn, clearBtn]), result);
    container.append(shell);
    if (!(tool.ui || []).length) doRun();
  }
})();
